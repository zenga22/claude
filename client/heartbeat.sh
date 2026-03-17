#!/usr/bin/env bash
# =============================================================================
# heartbeat.sh – Send a heartbeat to the Heartbeat Monitor server
#
# Usage:
#   ./heartbeat.sh                        # uses defaults below
#   COMPUTER_ID=my-server ./heartbeat.sh  # override via env
#
# Add to crontab (every 5 minutes):
#   */5 * * * * /path/to/heartbeat.sh >> /var/log/heartbeat.log 2>&1
#
# Dependencies: curl (standard on most systems)
# =============================================================================

set -euo pipefail

# ----- Configuration (edit or override via environment variables) ------------

MONITOR_URL="${MONITOR_URL:-http://your-monitor-server.example.com/api/heartbeat.php}"
API_KEY="${API_KEY:-change-me-to-a-strong-secret}"
COMPUTER_ID="${COMPUTER_ID:-$(hostname -s)}"
HOSTNAME_VAL="${HOSTNAME_VAL:-$(hostname -f 2>/dev/null || hostname)}"

# Retry settings
MAX_RETRIES=3
RETRY_DELAY=5   # seconds between retries

# ----- Send heartbeat --------------------------------------------------------

PAYLOAD=$(cat <<EOF
{
  "id":       "$(printf '%s' "$COMPUTER_ID" | sed 's/"/\\"/g')",
  "hostname": "$(printf '%s' "$HOSTNAME_VAL" | sed 's/"/\\"/g')",
  "extra": {
    "uptime": "$(uptime -p 2>/dev/null || uptime | awk '{print $3,$4}' | tr -d ',')",
    "load":   "$(cut -d' ' -f1-3 /proc/loadavg 2>/dev/null || uptime | awk -F'load average' '{print $2}' | tr -d ': ')"
  }
}
EOF
)

attempt=0
while [ $attempt -le $MAX_RETRIES ]; do
    if response=$(curl -fsS \
        --max-time 10 \
        -X POST \
        -H "Content-Type: application/json" \
        -H "X-Api-Key: $API_KEY" \
        -d "$PAYLOAD" \
        "$MONITOR_URL" 2>&1); then

        echo "$(date '+%Y-%m-%d %H:%M:%S') [OK] Heartbeat sent for '$COMPUTER_ID'. Response: $response"
        exit 0
    else
        attempt=$((attempt + 1))
        if [ $attempt -le $MAX_RETRIES ]; then
            echo "$(date '+%Y-%m-%d %H:%M:%S') [WARN] Attempt $attempt failed. Retrying in ${RETRY_DELAY}s…"
            sleep "$RETRY_DELAY"
        else
            echo "$(date '+%Y-%m-%d %H:%M:%S') [ERROR] All $MAX_RETRIES attempts failed for '$COMPUTER_ID'."
            exit 1
        fi
    fi
done
