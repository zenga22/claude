# AWS Reserved Instances Expiry Monitor

A Python script that checks all **EC2 Reserved Instances** across one or more AWS regions and sends an **email alert** when any RI will expire within a configurable window (default: 30 days).

## Features

- Scans one, several, or **all** AWS regions in a single run
- Configurable expiry threshold (default 30 days)
- **Two email backends**: AWS SES or any SMTP server
- Colour-coded HTML email + plain-text fallback
- Fully configurable via **CLI flags**, **environment variables**, or an **INI config file**
- `--dry-run` mode prints the report to stdout without sending mail
- Suitable for use as a **cron job** or **Lambda function**

## Requirements

- Python 3.8+
- AWS credentials configured (IAM role, `~/.aws/credentials`, or environment variables)
- Required IAM permissions:
  - `ec2:DescribeReservedInstances`
  - `ec2:DescribeRegions` (only needed with `--all-regions`)
  - `ses:SendRawEmail` (only needed with `--email-backend ses`)

```
pip install -r requirements.txt
```

## Quickstart

### Dry run (no email sent)

```bash
python ri_monitor.py \
  --regions us-east-1 eu-west-1 \
  --days 30 \
  --dry-run
```

### Send via AWS SES

```bash
python ri_monitor.py \
  --regions us-east-1 eu-west-1 ap-southeast-1 \
  --days 30 \
  --email-backend ses \
  --ses-region us-east-1 \
  --sender alerts@mycompany.com \
  --recipients ops@mycompany.com finance@mycompany.com
```

### Send via SMTP with STARTTLS (e.g. Gmail, port 587)

```bash
python ri_monitor.py \
  --days 30 \
  --email-backend smtp \
  --smtp-host smtp.gmail.com \
  --smtp-user you@gmail.com \
  --smtp-password "app-password" \
  --sender you@gmail.com \
  --recipients ops@mycompany.com
```

### Send via SMTP with implicit SSL/TLS (SMTPS, port 465)

```bash
python ri_monitor.py \
  --days 30 \
  --email-backend smtp \
  --smtp-ssl \
  --smtp-host smtp.gmail.com \
  --smtp-user you@gmail.com \
  --smtp-password "app-password" \
  --sender you@gmail.com \
  --recipients ops@mycompany.com
```

### Check ALL regions

```bash
python ri_monitor.py --all-regions --days 30 --dry-run
```

## Environment Variables

All CLI options have environment variable equivalents, which is handy for Lambda or Docker deployments.

| Variable | CLI flag | Description |
|---|---|---|
| `RI_MONITOR_REGIONS` | `--regions` | Space-separated list of regions |
| `RI_MONITOR_DAYS` | `--days` | Expiry threshold in days |
| `RI_MONITOR_SENDER` | `--sender` | From address |
| `RI_MONITOR_RECIPIENTS` | `--recipients` | Space-separated recipient list |
| `RI_MONITOR_EMAIL_BACKEND` | `--email-backend` | `ses` or `smtp` |
| `RI_MONITOR_SES_REGION` | `--ses-region` | SES endpoint region |
| `RI_MONITOR_SMTP_HOST` | `--smtp-host` | SMTP hostname |
| `RI_MONITOR_SMTP_PORT` | `--smtp-port` | SMTP port |
| `RI_MONITOR_SMTP_SSL` | `--smtp-ssl` | `1`/`true`/`yes` for implicit SSL/TLS |
| `RI_MONITOR_SMTP_USER` | `--smtp-user` | SMTP username |
| `RI_MONITOR_SMTP_PASSWORD` | `--smtp-password` | SMTP password |
| `RI_MONITOR_DRY_RUN` | `--dry-run` | `1`/`true`/`yes` to enable |
| `AWS_PROFILE` | `--profile` | AWS named profile |

## Configuration File (INI)

Instead of (or in addition to) CLI flags and environment variables you can store settings in an INI file.

**Priority order — highest wins:**

```
CLI flag  >  environment variable  >  INI file  >  built-in default
```

### Generating a sample file

```bash
# writes ri_monitor.ini in the current directory
python ri_monitor.py --write-config

# or write to a custom path
python ri_monitor.py --write-config ~/.ri_monitor.ini
```

### Auto-discovery

When `--config` is not specified the script searches these locations in order and uses the first file found:

1. `./ri_monitor.ini` (current working directory)
2. `~/.ri_monitor.ini`
3. `~/.config/ri_monitor/ri_monitor.ini`

### Using a specific file

```bash
python ri_monitor.py --config /etc/ri_monitor/production.ini
```

### Example INI file

```ini
[ri_monitor]
regions    = us-east-1 eu-west-1 ap-southeast-1
days       = 30
email_backend = ses
ses_region = us-east-1
sender     = alerts@mycompany.com
recipients = ops@mycompany.com finance@mycompany.com
```

A fully commented template is available in `ri_monitor.ini.example`.

### INI key reference

| INI key | Equivalent CLI flag | Notes |
|---|---|---|
| `regions` | `--regions` | Space- or comma-separated |
| `all_regions` | `--all-regions` | Boolean |
| `days` | `--days` | Integer |
| `profile` | `--profile` | AWS named profile |
| `email_backend` | `--email-backend` | `ses` or `smtp` |
| `sender` | `--sender` | |
| `recipients` | `--recipients` | Space- or comma-separated |
| `ses_region` | `--ses-region` | |
| `smtp_host` | `--smtp-host` | |
| `smtp_port` | `--smtp-port` | Integer; default 587 (STARTTLS) or 465 (SSL) |
| `smtp_ssl` | `--smtp-ssl` | Boolean; implicit SSL/TLS (SMTPS, port 465) |
| `smtp_no_tls` | `--smtp-no-tls` | Boolean; plain connection, no encryption |
| `smtp_user` | `--smtp-user` | |
| `smtp_password` | `--smtp-password` | |
| `dry_run` | `--dry-run` | Boolean |
| `always_send` | `--always-send` | Boolean |

## Scheduling with Cron

Run once a day at 08:00:

Put your settings in `/etc/ri_monitor.ini` (see the INI section above), then:

```cron
0 8 * * * /usr/bin/python3 /opt/ri_monitor/ri_monitor.py \
  --config /etc/ri_monitor.ini >> /var/log/ri_monitor.log 2>&1
```

Or keep everything on one line without a config file:

```cron
0 8 * * * /usr/bin/python3 /opt/ri_monitor/ri_monitor.py \
  --regions us-east-1 eu-west-1 --days 30 \
  --email-backend ses --sender alerts@mycompany.com \
  --recipients ops@mycompany.com >> /var/log/ri_monitor.log 2>&1
```

## AWS Lambda Deployment

Wrap `main()` in a Lambda handler:

```python
import ri_monitor

def lambda_handler(event, context):
    ri_monitor.main()
```

Package with dependencies:

```bash
pip install -r requirements.txt -t package/
cp ri_monitor.py package/
cd package && zip -r ../ri_monitor.zip .
```

Upload `ri_monitor.zip` and set environment variables in the Lambda configuration.
Use **EventBridge (CloudWatch Events)** to trigger the function on a schedule.

## IAM Policy

Minimum IAM policy needed to run the monitor:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "ec2:DescribeReservedInstances",
        "ec2:DescribeRegions"
      ],
      "Resource": "*"
    },
    {
      "Effect": "Allow",
      "Action": "ses:SendRawEmail",
      "Resource": "*"
    }
  ]
}
```

## Sample Email Output

```
AWS Reserved Instances Expiry Report
Generated: 2026-02-19 08:00 UTC
Threshold: 30 days

Region               Type            Count Platform             Scope        AZ              Expires      Days Left
-------------------------------------------------------------------------------------------------------------------
us-east-1            m5.xlarge           2 Linux/UNIX          Availability  us-east-1a      2026-02-25           6
eu-west-1            r5.2xlarge          1 Windows             Region                        2026-03-10          19
us-east-1            c5.large            4 Linux/UNIX          Region                        2026-03-15          24

Total: 3 Reserved Instance(s) expiring within 30 days.

Action: Review and renew these instances to avoid on-demand pricing.
```
