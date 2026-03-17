#!/usr/bin/env python3
"""
heartbeat.py – Send a heartbeat to the Heartbeat Monitor server.

Usage:
    python3 heartbeat.py

    # Or with environment variable overrides:
    MONITOR_URL=http://... COMPUTER_ID=my-server python3 heartbeat.py

Add to crontab (every 5 minutes):
    */5 * * * * /usr/bin/python3 /path/to/heartbeat.py >> /var/log/heartbeat.log 2>&1

Requires: Python 3.6+, no third-party packages needed.
"""

import json
import os
import platform
import socket
import sys
import time
import urllib.request
import urllib.error
from datetime import datetime

# =============================================================================
# Configuration – edit here or set environment variables
# =============================================================================

MONITOR_URL = os.environ.get(
    "MONITOR_URL",
    "http://your-monitor-server.example.com/api/heartbeat.php",
)
API_KEY     = os.environ.get("API_KEY",     "change-me-to-a-strong-secret")
COMPUTER_ID = os.environ.get("COMPUTER_ID", socket.gethostname().split(".")[0])
HOSTNAME    = os.environ.get("HOSTNAME_VAL", socket.getfqdn())

MAX_RETRIES  = 3
RETRY_DELAY  = 5   # seconds
TIMEOUT      = 10  # seconds

# =============================================================================
# Helpers
# =============================================================================

def get_extra() -> dict:
    """Collect optional system metadata to send with the heartbeat."""
    extra = {"python_version": platform.python_version(), "os": platform.system()}

    # Uptime (Linux)
    try:
        with open("/proc/uptime") as f:
            uptime_sec = float(f.read().split()[0])
        days, rem = divmod(int(uptime_sec), 86400)
        hours, rem = divmod(rem, 3600)
        extra["uptime"] = f"{days}d {hours}h {rem // 60}m"
    except Exception:
        pass

    # Load average (Unix)
    try:
        load = os.getloadavg()
        extra["load"] = f"{load[0]:.2f} {load[1]:.2f} {load[2]:.2f}"
    except Exception:
        pass

    return extra


def send_heartbeat() -> bool:
    """Send one heartbeat. Returns True on success."""
    payload = json.dumps({
        "id":       COMPUTER_ID,
        "hostname": HOSTNAME,
        "extra":    get_extra(),
    }).encode("utf-8")

    req = urllib.request.Request(
        MONITOR_URL,
        data=payload,
        method="POST",
        headers={
            "Content-Type": "application/json",
            "X-Api-Key":    API_KEY,
        },
    )

    try:
        with urllib.request.urlopen(req, timeout=TIMEOUT) as resp:
            body = resp.read().decode("utf-8")
            print(f"{ts()} [OK] Heartbeat sent for '{COMPUTER_ID}'. Response: {body.strip()}")
            return True
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", errors="replace")
        print(f"{ts()} [ERROR] HTTP {e.code}: {body.strip()}", file=sys.stderr)
    except urllib.error.URLError as e:
        print(f"{ts()} [ERROR] URL error: {e.reason}", file=sys.stderr)
    except Exception as e:
        print(f"{ts()} [ERROR] Unexpected: {e}", file=sys.stderr)

    return False


def ts() -> str:
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")


# =============================================================================
# Main
# =============================================================================

def main():
    for attempt in range(1, MAX_RETRIES + 1):
        if send_heartbeat():
            sys.exit(0)

        if attempt < MAX_RETRIES:
            print(f"{ts()} [WARN] Attempt {attempt} failed. Retrying in {RETRY_DELAY}s…",
                  file=sys.stderr)
            time.sleep(RETRY_DELAY)

    print(f"{ts()} [ERROR] All {MAX_RETRIES} attempts failed for '{COMPUTER_ID}'.",
          file=sys.stderr)
    sys.exit(1)


if __name__ == "__main__":
    main()
