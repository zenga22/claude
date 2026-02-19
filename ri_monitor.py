#!/usr/bin/env python3
"""
AWS Reserved Instances Expiry Monitor

Checks all EC2 Reserved Instances across one or more AWS regions and sends
an email alert when any will expire within the configured threshold (default 30 days).

Email delivery supports two backends:
  - AWS SES  (recommended for production)
  - SMTP     (any mail server, e.g. Gmail, SendGrid, self-hosted)

Usage:
    python ri_monitor.py [--regions us-east-1 eu-west-1] [--days 30]
                         [--sender from@example.com] [--recipients a@b.com c@d.com]
                         [--email-backend ses|smtp]
                         [--smtp-host HOST] [--smtp-port 587]
                         [--smtp-user USER] [--smtp-password PASS]
                         [--dry-run]

All options can also be supplied via environment variables (see CONFIG section).
"""

import argparse
import os
import smtplib
import sys
from datetime import datetime, timezone
from email.mime.multipart import MIMEMultipart
from email.mime.text import MIMEText
from typing import List, Optional

import boto3
from botocore.exceptions import BotoCoreError, ClientError


# ---------------------------------------------------------------------------
# Configuration defaults (override via CLI args or environment variables)
# ---------------------------------------------------------------------------

DEFAULT_REGIONS: List[str] = ["us-east-1"]
DEFAULT_DAYS_THRESHOLD: int = 30
DEFAULT_EMAIL_BACKEND: str = "ses"   # "ses" or "smtp"
DEFAULT_SMTP_PORT: int = 587


def get_env(key: str, default=None):
    return os.environ.get(key, default)


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
    use_tls: bool = True,
):
    with smtplib.SMTP(host, port) as server:
        if use_tls:
            server.starttls()
        if username and password:
            server.login(username, password)
        server.sendmail(sender, recipients, msg.as_string())
    print(f"Email sent via SMTP ({host}:{port}) to: {', '.join(recipients)}")


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Monitor AWS Reserved Instances and alert by email before expiry.",
        formatter_class=argparse.ArgumentDefaultsHelpFormatter,
    )

    # Scope
    parser.add_argument(
        "--regions", nargs="*",
        default=get_env("RI_MONITOR_REGIONS", "").split() or DEFAULT_REGIONS,
        help="AWS region(s) to check. Pass no value to check ALL regions.",
    )
    parser.add_argument(
        "--all-regions", action="store_true",
        help="Check every available AWS region (overrides --regions).",
    )
    parser.add_argument(
        "--days", type=int,
        default=int(get_env("RI_MONITOR_DAYS", DEFAULT_DAYS_THRESHOLD)),
        help="Alert threshold in days before expiry.",
    )
    parser.add_argument(
        "--profile",
        default=get_env("AWS_PROFILE"),
        help="AWS CLI named profile to use.",
    )

    # Email
    parser.add_argument(
        "--sender",
        default=get_env("RI_MONITOR_SENDER"),
        help="From address for the alert email.",
    )
    parser.add_argument(
        "--recipients", nargs="+",
        default=get_env("RI_MONITOR_RECIPIENTS", "").split() or [],
        help="Recipient email address(es).",
    )
    parser.add_argument(
        "--email-backend", choices=["ses", "smtp"],
        default=get_env("RI_MONITOR_EMAIL_BACKEND", DEFAULT_EMAIL_BACKEND),
        help="Email delivery method.",
    )

    # SES
    parser.add_argument(
        "--ses-region",
        default=get_env("RI_MONITOR_SES_REGION", "us-east-1"),
        help="AWS region for the SES endpoint.",
    )

    # SMTP
    parser.add_argument("--smtp-host", default=get_env("RI_MONITOR_SMTP_HOST"), help="SMTP server hostname.")
    parser.add_argument("--smtp-port", type=int, default=int(get_env("RI_MONITOR_SMTP_PORT", DEFAULT_SMTP_PORT)), help="SMTP server port.")
    parser.add_argument("--smtp-user", default=get_env("RI_MONITOR_SMTP_USER"), help="SMTP login username.")
    parser.add_argument("--smtp-password", default=get_env("RI_MONITOR_SMTP_PASSWORD"), help="SMTP login password.")
    parser.add_argument("--smtp-no-tls", action="store_true", help="Disable STARTTLS for SMTP.")

    # Misc
    parser.add_argument(
        "--dry-run", action="store_true",
        default=get_env("RI_MONITOR_DRY_RUN", "").lower() in ("1", "true", "yes"),
        help="Print report to stdout without sending email.",
    )
    parser.add_argument(
        "--always-send", action="store_true",
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
                port=args.smtp_port,
                username=args.smtp_user,
                password=args.smtp_password,
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
