#!/usr/bin/env python3
"""
AWS Reserved Instances Expiry Monitor

Checks all EC2 Reserved Instances across one or more AWS regions and sends
an email alert when any will expire within the configured threshold (default 30 days).

Email delivery supports two backends:
  - AWS SES  (recommended for production)
  - SMTP     (any mail server, e.g. Gmail, SendGrid, self-hosted)

Configuration priority (highest → lowest):
  1. CLI flags
  2. Environment variables  (RI_MONITOR_*)
  3. INI config file        (ri_monitor.ini or path from --config)
  4. Built-in defaults

Usage:
    python ri_monitor.py [--config /path/to/ri_monitor.ini]
                         [--regions us-east-1 eu-west-1] [--days 30]
                         [--sender from@example.com] [--recipients a@b.com c@d.com]
                         [--email-backend ses|smtp]
                         [--smtp-host HOST] [--smtp-port 587]
                         [--smtp-user USER] [--smtp-password PASS]
                         [--dry-run]
                         [--write-config [PATH]]   # generate a sample INI file

All options can also be supplied via environment variables or an INI config file.
"""

import argparse
import configparser
import os
import smtplib
import sys
from datetime import datetime, timezone
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from typing import Dict, List, Optional

import boto3
from botocore.exceptions import BotoCoreError, ClientError


# ---------------------------------------------------------------------------
# Configuration defaults (override via config file, env vars, or CLI args)
# ---------------------------------------------------------------------------

DEFAULT_REGIONS: List[str] = ["us-east-1"]
DEFAULT_DAYS_THRESHOLD: int = 30
DEFAULT_EMAIL_BACKEND: str = "ses"   # "ses" or "smtp"
DEFAULT_SMTP_PORT: int = 587         # STARTTLS
DEFAULT_SMTP_SSL_PORT: int = 465     # implicit SSL/TLS
INI_SECTION: str = "ri_monitor"

# Candidate paths searched in order when --config is not specified.
DEFAULT_CONFIG_PATHS: List[str] = [
    "ri_monitor.ini",
    os.path.expanduser("~/.ri_monitor.ini"),
    os.path.expanduser("~/.config/ri_monitor/ri_monitor.ini"),
]


def get_env(key: str, default=None):
    return os.environ.get(key, default)


# ---------------------------------------------------------------------------
# INI config file support
# ---------------------------------------------------------------------------

def load_ini_config(path: Optional[str] = None) -> Dict[str, str]:
    """Read the [ri_monitor] section from an INI file and return it as a dict.

    If *path* is None the DEFAULT_CONFIG_PATHS list is searched and the first
    existing file is used.  Missing files are silently ignored unless a path
    was explicitly requested via --config, in which case an error is raised.
    """
    explicit = path is not None
    candidates = [path] if explicit else DEFAULT_CONFIG_PATHS

    chosen: Optional[str] = None
    for candidate in candidates:
        if os.path.isfile(candidate):
            chosen = candidate
            break

    if chosen is None:
        if explicit:
            print(f"ERROR: config file not found: {path}", file=sys.stderr)
            sys.exit(1)
        return {}   # no config file – silently proceed with defaults

    parser = configparser.ConfigParser()
    try:
        parser.read(chosen)
    except configparser.Error as exc:
        print(f"ERROR: could not parse config file {chosen!r}: {exc}", file=sys.stderr)
        sys.exit(1)

    if INI_SECTION not in parser:
        print(
            f"[WARN] Config file {chosen!r} has no [{INI_SECTION}] section – ignored.",
            file=sys.stderr,
        )
        return {}

    print(f"Config  : loaded from {chosen}")
    return dict(parser[INI_SECTION])


def write_sample_config(dest: str) -> None:
    """Write a fully-commented sample INI config file to *dest*."""
    sample = f"""\
# ri_monitor.ini – AWS Reserved Instances Expiry Monitor configuration
# -----------------------------------------------------------------------
# All keys are optional.  Precedence: CLI flags > env vars > this file.
# Boolean values accept: true/false, yes/no, 1/0  (case-insensitive).
# Lists (regions, recipients) are whitespace- or comma-separated.

[{INI_SECTION}]

# ── Scope ───────────────────────────────────────────────────────────────
# AWS regions to check.  Space- or comma-separated.
# Omit (or leave blank) to use the built-in default ({" ".join(DEFAULT_REGIONS)}).
#regions = us-east-1 eu-west-1 ap-southeast-1

# Set to true to check every available AWS region (overrides 'regions').
#all_regions = false

# Number of days before expiry to trigger an alert.
#days = {DEFAULT_DAYS_THRESHOLD}

# AWS CLI named profile to use for credentials.
#profile =

# ── Email ────────────────────────────────────────────────────────────────
# Email delivery backend: ses  or  smtp
#email_backend = {DEFAULT_EMAIL_BACKEND}

# "From" address (must be verified in SES when using the ses backend).
#sender = alerts@mycompany.com

# One or more recipient addresses, space- or comma-separated.
#recipients = ops@mycompany.com finance@mycompany.com

# ── AWS SES options ──────────────────────────────────────────────────────
# AWS region where the SES endpoint lives.
#ses_region = us-east-1

# ── SMTP options ─────────────────────────────────────────────────────────
#smtp_host = smtp.gmail.com

# Connection security — choose ONE of the three modes:
#
#   smtp_ssl = true   → implicit SSL/TLS via SMTPS  (default port {DEFAULT_SMTP_SSL_PORT})
#   smtp_no_tls = true → plain connection, no encryption  (not recommended)
#   (neither)          → STARTTLS upgrade             (default port {DEFAULT_SMTP_PORT})
#
#smtp_ssl = false
#smtp_no_tls = false

# Port is inferred from the security mode when not set:
#   STARTTLS → {DEFAULT_SMTP_PORT}, SMTPS/SSL → {DEFAULT_SMTP_SSL_PORT}
#smtp_port =

#smtp_user = you@gmail.com
#smtp_password = your-app-password

# ── Misc ─────────────────────────────────────────────────────────────────
# Print the report to stdout without sending any email.
#dry_run = false

# Send an email even when no RIs are expiring (useful as a heartbeat).
#always_send = false
"""
    with open(dest, "w") as fh:
        fh.write(sample)
    print(f"Sample config written to: {dest}")


# ---------------------------------------------------------------------------
# Data model
# ---------------------------------------------------------------------------

class ReservedInstance:
    """Lightweight wrapper around the boto3 Reserved Instance dict."""

    def __init__(self, region: str, raw: dict):
        self.region = region
        self.ri_id = raw.get("ReservedInstancesId", "unknown")
        self.instance_type = raw.get("InstanceType", "unknown")
        self.instance_count = raw.get("InstanceCount", 0)
        self.platform = raw.get("ProductDescription", "unknown")
        self.scope = raw.get("Scope", "unknown")
        self.availability_zone = raw.get("AvailabilityZone", "")
        self.state = raw.get("State", "unknown")
        self.start = raw.get("Start")
        self.end: Optional[datetime] = raw.get("End")
        self.offering_class = raw.get("OfferingClass", "standard")
        self.offering_type = raw.get("OfferingType", "unknown")

    @property
    def days_remaining(self) -> Optional[int]:
        if self.end is None:
            return None
        now = datetime.now(timezone.utc)
        delta = self.end - now
        return delta.days

    @property
    def expiry_str(self) -> str:
        if self.end is None:
            return "N/A"
        return self.end.strftime("%Y-%m-%d")

    def __repr__(self) -> str:
        return (
            f"<RI {self.ri_id} {self.instance_type}x{self.instance_count} "
            f"region={self.region} expires={self.expiry_str} days_left={self.days_remaining}>"
        )


# ---------------------------------------------------------------------------
# AWS helpers
# ---------------------------------------------------------------------------

def get_all_regions(session: boto3.Session) -> List[str]:
    """Return all EC2-enabled regions for this account."""
    ec2 = session.client("ec2", region_name="us-east-1")
    response = ec2.describe_regions(Filters=[{"Name": "opt-in-status", "Values": ["opt-in-not-required", "opted-in"]}])
    return [r["RegionName"] for r in response["Regions"]]


def fetch_reserved_instances(session: boto3.Session, region: str) -> List[ReservedInstance]:
    """Return all *active* Reserved Instances in a single region."""
    ec2 = session.client("ec2", region_name=region)
    try:
        response = ec2.describe_reserved_instances(
            Filters=[{"Name": "state", "Values": ["active"]}]
        )
    except ClientError as exc:
        code = exc.response["Error"]["Code"]
        print(f"  [WARN] {region}: ClientError {code} — skipping region.", file=sys.stderr)
        return []
    except BotoCoreError as exc:
        print(f"  [WARN] {region}: BotoCoreError {exc} — skipping region.", file=sys.stderr)
        return []

    return [ReservedInstance(region, r) for r in response.get("ReservedInstances", [])]


def collect_expiring(
    regions: List[str],
    days_threshold: int,
    profile: Optional[str] = None,
) -> List[ReservedInstance]:
    """Gather all RIs expiring within *days_threshold* days across given regions."""
    session = boto3.Session(profile_name=profile) if profile else boto3.Session()

    if not regions:
        print("Discovering all AWS regions …")
        regions = get_all_regions(session)
        print(f"Found {len(regions)} regions.")

    expiring: List[ReservedInstance] = []
    for region in regions:
        print(f"  Checking {region} …")
        ris = fetch_reserved_instances(session, region)
        for ri in ris:
            days = ri.days_remaining
            if days is not None and days <= days_threshold:
                expiring.append(ri)
        print(f"    → {len(ris)} active RI(s), {sum(1 for r in ris if r.days_remaining is not None and r.days_remaining <= days_threshold)} expiring within {days_threshold} days.")

    return expiring


# ---------------------------------------------------------------------------
# Email composition
# ---------------------------------------------------------------------------

def build_plain_text(expiring: List[ReservedInstance], days_threshold: int) -> str:
    lines = [
        f"AWS Reserved Instances Expiry Report",
        f"Generated: {datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M UTC')}",
        f"Threshold: {days_threshold} days",
        "",
        f"{'Region':<20} {'Type':<15} {'Count':>5} {'Platform':<20} {'Scope':<12} {'AZ':<15} {'Expires':<12} {'Days Left':>10}",
        "-" * 115,
    ]
    for ri in sorted(expiring, key=lambda r: (r.days_remaining or 0)):
        lines.append(
            f"{ri.region:<20} {ri.instance_type:<15} {ri.instance_count:>5} "
            f"{ri.platform:<20} {ri.scope:<12} {ri.availability_zone or 'N/A':<15} "
            f"{ri.expiry_str:<12} {ri.days_remaining:>10}"
        )
    lines += [
        "",
        f"Total: {len(expiring)} Reserved Instance(s) expiring within {days_threshold} days.",
        "",
        "Action: Review and renew these instances to avoid on-demand pricing.",
    ]
    return "\n".join(lines)


def build_html(expiring: List[ReservedInstance], days_threshold: int) -> str:
    now_str = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
    rows = ""
    for ri in sorted(expiring, key=lambda r: (r.days_remaining or 0)):
        days = ri.days_remaining
        if days is None:
            color = "#555"
        elif days <= 7:
            color = "#c0392b"   # red
        elif days <= 14:
            color = "#e67e22"   # orange
        else:
            color = "#f39c12"   # yellow/amber

        az = ri.availability_zone or "—"
        rows += f"""
        <tr>
          <td>{ri.region}</td>
          <td>{ri.instance_type}</td>
          <td style="text-align:center">{ri.instance_count}</td>
          <td>{ri.platform}</td>
          <td>{ri.scope}</td>
          <td>{az}</td>
          <td>{ri.offering_class} / {ri.offering_type}</td>
          <td>{ri.expiry_str}</td>
          <td style="font-weight:bold;color:{color};text-align:center">{days}</td>
        </tr>"""

    return f"""<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body {{ font-family: Arial, sans-serif; font-size: 14px; color: #333; }}
  h2   {{ color: #2c3e50; }}
  table {{ border-collapse: collapse; width: 100%; margin-top: 16px; }}
  th   {{ background: #2c3e50; color: #fff; padding: 8px 12px; text-align: left; }}
  td   {{ padding: 7px 12px; border-bottom: 1px solid #ddd; }}
  tr:nth-child(even) td {{ background: #f8f8f8; }}
  .footer {{ margin-top: 20px; font-size: 12px; color: #777; }}
  .warning {{ background:#fff3cd; border:1px solid #ffc107; padding:10px 16px;
              border-radius:4px; margin-bottom:16px; }}
</style>
</head>
<body>
  <h2>&#9888; AWS Reserved Instances Expiry Report</h2>
  <p>Generated: <strong>{now_str}</strong> &nbsp;|&nbsp; Threshold: <strong>{days_threshold} days</strong></p>
  <div class="warning">
    <strong>{len(expiring)}</strong> Reserved Instance(s) will expire within {days_threshold} days.
    Review and renew them to avoid on-demand pricing.
  </div>
  <table>
    <thead>
      <tr>
        <th>Region</th><th>Instance Type</th><th>Count</th><th>Platform</th>
        <th>Scope</th><th>Availability Zone</th><th>Offering</th>
        <th>Expires On</th><th>Days Left</th>
      </tr>
    </thead>
    <tbody>{rows}
    </tbody>
  </table>
  <p class="footer">
    Sent by <em>ri_monitor.py</em> &mdash; AWS Reserved Instances Expiry Monitor.
  </p>
</body>
</html>"""


def compose_email(
    sender: str,
    recipients: List[str],
    expiring: List[ReservedInstance],
    days_threshold: int,
) -> MIMEMultipart:
    count = len(expiring)
    subject = f"[AWS] {count} Reserved Instance(s) expiring within {days_threshold} days"

    msg = MIMEMultipart("alternative")
    msg["Subject"] = subject
    msg["From"] = sender
    msg["To"] = ", ".join(recipients)

    plain = build_plain_text(expiring, days_threshold)
    html = build_html(expiring, days_threshold)

    msg.attach(MIMEText(plain, "plain"))
    msg.attach(MIMEText(html, "html"))
    return msg


# ---------------------------------------------------------------------------
# Email delivery backends
# ---------------------------------------------------------------------------

def send_via_ses(
    msg: MIMEMultipart,
    sender: str,
    recipients: List[str],
    region: str = "us-east-1",
):
    session = boto3.Session()
    ses = session.client("ses", region_name=region)
    ses.send_raw_email(
        Source=sender,
        Destinations=recipients,
        RawMessage={"Data": msg.as_string()},
    )
    print(f"Email sent via SES to: {', '.join(recipients)}")


def send_via_smtp(
    msg: MIMEMultipart,
    sender: str,
    recipients: List[str],
    host: str,
    port: int,
    username: Optional[str],
    password: Optional[str],
    use_ssl: bool = False,
    use_tls: bool = True,
):
    """Send *msg* via SMTP.

    Connection modes (mutually exclusive, evaluated in order):
      use_ssl=True  – implicit SSL/TLS via smtplib.SMTP_SSL (typical port 465)
      use_tls=True  – plain connection upgraded with STARTTLS  (typical port 587)
      both False    – plain/unencrypted connection (not recommended)
    """
    if use_ssl:
        ctx = __import__("ssl").create_default_context()
        with smtplib.SMTP_SSL(host, port, context=ctx) as server:
            if username and password:
                server.login(username, password)
            server.sendmail(sender, recipients, msg.as_string())
    else:
        with smtplib.SMTP(host, port) as server:
            if use_tls:
                server.starttls()
            if username and password:
                server.login(username, password)
            server.sendmail(sender, recipients, msg.as_string())
    mode = "SMTPS/SSL" if use_ssl else ("SMTP+STARTTLS" if use_tls else "SMTP")
    print(f"Email sent via {mode} ({host}:{port}) to: {', '.join(recipients)}")


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def _ini_bool(ini: Dict[str, str], key: str, default: bool) -> bool:
    """Return a boolean from the INI dict, honouring true/false/yes/no/1/0."""
    raw = ini.get(key)
    if raw is None:
        return default
    return raw.strip().lower() in ("1", "true", "yes")


def _ini_list(ini: Dict[str, str], key: str, default: List[str]) -> List[str]:
    """Return a list from the INI dict (whitespace- or comma-separated)."""
    raw = ini.get(key)
    if not raw:
        return default
    # Accept both "a b c" and "a, b, c" and mixed formats.
    return [item.strip() for item in raw.replace(",", " ").split() if item.strip()]


def parse_args() -> argparse.Namespace:
    # ── Step 1: pre-parse only --config and --write-config ──────────────
    # We need --config before building the main parser so that INI values
    # can be used as defaults.  add_help=False avoids duplicate -h output.
    pre = argparse.ArgumentParser(add_help=False)
    pre.add_argument("--config", default=None, metavar="PATH")
    pre.add_argument("--write-config", nargs="?", const="ri_monitor.ini", metavar="PATH")
    pre_args, _ = pre.parse_known_args()

    # Handle --write-config early so it works even without AWS credentials.
    if pre_args.write_config is not None:
        write_sample_config(pre_args.write_config)
        sys.exit(0)

    # ── Step 2: load INI file ────────────────────────────────────────────
    ini = load_ini_config(pre_args.config)

    # ── Step 3: helper that applies the priority chain ───────────────────
    # Priority: env var > INI value > hardcoded default
    # (CLI flags are applied last by argparse itself, winning over all defaults.)
    def cfg(env_key: str, ini_key: str, default=None):
        env_val = os.environ.get(env_key)
        if env_val is not None:
            return env_val
        ini_val = ini.get(ini_key)
        if ini_val is not None:
            return ini_val
        return default

    def cfg_int(env_key: str, ini_key: str, default: int) -> int:
        return int(cfg(env_key, ini_key, default))

    def cfg_bool(env_key: str, ini_key: str, default: bool) -> bool:
        env_val = os.environ.get(env_key)
        if env_val is not None:
            return env_val.lower() in ("1", "true", "yes")
        return _ini_bool(ini, ini_key, default)

    def cfg_list(env_key: str, ini_key: str, default: List[str]) -> List[str]:
        env_val = os.environ.get(env_key, "").split()
        if env_val:
            return env_val
        return _ini_list(ini, ini_key, default)

    # ── Step 4: build the full parser with resolved defaults ─────────────
    parser = argparse.ArgumentParser(
        description="Monitor AWS Reserved Instances and alert by email before expiry.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
        parents=[pre],
    )

    # Scope
    parser.add_argument(
        "--regions", nargs="*",
        default=cfg_list("RI_MONITOR_REGIONS", "regions", DEFAULT_REGIONS),
        help="AWS region(s) to check. Pass no value to check ALL regions.",
    )
    parser.add_argument(
        "--all-regions", action="store_true",
        default=cfg_bool("", "all_regions", False),
        help="Check every available AWS region (overrides --regions).",
    )
    parser.add_argument(
        "--days", type=int,
        default=cfg_int("RI_MONITOR_DAYS", "days", DEFAULT_DAYS_THRESHOLD),
        help="Alert threshold in days before expiry.",
    )
    parser.add_argument(
        "--profile",
        default=cfg("AWS_PROFILE", "profile"),
        help="AWS CLI named profile to use.",
    )

    # Email
    parser.add_argument(
        "--sender",
        default=cfg("RI_MONITOR_SENDER", "sender"),
        help="From address for the alert email.",
    )
    parser.add_argument(
        "--recipients", nargs="+",
        default=cfg_list("RI_MONITOR_RECIPIENTS", "recipients", []),
        help="Recipient email address(es).",
    )
    parser.add_argument(
        "--email-backend", choices=["ses", "smtp"],
        default=cfg("RI_MONITOR_EMAIL_BACKEND", "email_backend", DEFAULT_EMAIL_BACKEND),
        help="Email delivery method.",
    )

    # SES
    parser.add_argument(
        "--ses-region",
        default=cfg("RI_MONITOR_SES_REGION", "ses_region", "us-east-1"),
        help="AWS region for the SES endpoint.",
    )

    # SMTP
    parser.add_argument(
        "--smtp-host",
        default=cfg("RI_MONITOR_SMTP_HOST", "smtp_host"),
        help="SMTP server hostname.",
    )
    _raw_smtp_port = cfg("RI_MONITOR_SMTP_PORT", "smtp_port")
    parser.add_argument(
        "--smtp-port", type=int,
        default=int(_raw_smtp_port) if _raw_smtp_port else None,
        help=(
            f"SMTP server port. Defaults to {DEFAULT_SMTP_PORT} for STARTTLS "
            f"or {DEFAULT_SMTP_SSL_PORT} when --smtp-ssl is set."
        ),
    )
    parser.add_argument(
        "--smtp-ssl", action="store_true",
        default=cfg_bool("RI_MONITOR_SMTP_SSL", "smtp_ssl", False),
        help=(
            "Use implicit SSL/TLS (SMTPS) instead of STARTTLS. "
            f"Typical port: {DEFAULT_SMTP_SSL_PORT}. "
            "Mutually exclusive with --smtp-no-tls."
        ),
    )
    parser.add_argument(
        "--smtp-user",
        default=cfg("RI_MONITOR_SMTP_USER", "smtp_user"),
        help="SMTP login username.",
    )
    parser.add_argument(
        "--smtp-password",
        default=cfg("RI_MONITOR_SMTP_PASSWORD", "smtp_password"),
        help="SMTP login password.",
    )
    parser.add_argument(
        "--smtp-no-tls", action="store_true",
        default=cfg_bool("", "smtp_no_tls", False),
        help="Disable STARTTLS for SMTP.",
    )

    # Misc
    parser.add_argument(
        "--dry-run", action="store_true",
        default=cfg_bool("RI_MONITOR_DRY_RUN", "dry_run", False),
        help="Print report to stdout without sending email.",
    )
    parser.add_argument(
        "--always-send", action="store_true",
        default=cfg_bool("", "always_send", False),
        help="Send email even when no RIs are expiring (useful for health checks).",
    )

    return parser.parse_args()


# ---------------------------------------------------------------------------
# Entry point
# ---------------------------------------------------------------------------

def main():
    args = parse_args()

    regions = [] if args.all_regions else (args.regions or DEFAULT_REGIONS)

    print(f"\n=== AWS Reserved Instances Expiry Monitor ===")
    print(f"Threshold : {args.days} days")
    print(f"Regions   : {'ALL' if not regions else ', '.join(regions)}")
    print(f"Backend   : {args.email_backend}")
    print(f"Dry-run   : {args.dry_run}\n")

    # 1. Collect expiring RIs
    expiring = collect_expiring(regions, args.days, args.profile)

    print(f"\nFound {len(expiring)} RI(s) expiring within {args.days} days.")

    if not expiring and not args.always_send:
        print("Nothing to report. No email sent.")
        return

    # 2. Validate email settings
    if not args.dry_run:
        if not args.sender:
            print("ERROR: --sender (or RI_MONITOR_SENDER env var) is required.", file=sys.stderr)
            sys.exit(1)
        if not args.recipients:
            print("ERROR: --recipients (or RI_MONITOR_RECIPIENTS env var) is required.", file=sys.stderr)
            sys.exit(1)
        if args.email_backend == "smtp" and not args.smtp_host:
            print("ERROR: --smtp-host is required when using the smtp backend.", file=sys.stderr)
            sys.exit(1)
        if args.email_backend == "smtp" and args.smtp_ssl and args.smtp_no_tls:
            print("ERROR: --smtp-ssl and --smtp-no-tls are mutually exclusive.", file=sys.stderr)
            sys.exit(1)

    # Resolve SMTP port: explicit > ssl-aware default
    smtp_port = args.smtp_port or (DEFAULT_SMTP_SSL_PORT if args.smtp_ssl else DEFAULT_SMTP_PORT)

    # 3. Compose message
    sender = args.sender or "ri-monitor@example.com"
    recipients = args.recipients or ["ops@example.com"]
    msg = compose_email(sender, recipients, expiring, args.days)

    # 4. Deliver or print
    if args.dry_run:
        print("\n--- Plain-text email preview ---")
        print(build_plain_text(expiring, args.days))
        print("\n--- HTML email preview (truncated) ---")
        html_preview = build_html(expiring, args.days)
        print(html_preview[:1000] + ("…" if len(html_preview) > 1000 else ""))
        return

    try:
        if args.email_backend == "ses":
            send_via_ses(msg, sender, recipients, region=args.ses_region)
        else:
            send_via_smtp(
                msg, sender, recipients,
                host=args.smtp_host,
                port=smtp_port,
                username=args.smtp_user,
                password=args.smtp_password,
                use_ssl=args.smtp_ssl,
                use_tls=not args.smtp_no_tls,
            )
    except (ClientError, BotoCoreError) as exc:
        print(f"ERROR sending via SES: {exc}", file=sys.stderr)
        sys.exit(1)
    except smtplib.SMTPException as exc:
        print(f"ERROR sending via SMTP: {exc}", file=sys.stderr)
        sys.exit(1)


if __name__ == "__main__":
    main()
