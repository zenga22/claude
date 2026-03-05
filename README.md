# Claude Repository
A repository used for use with Claude Code while learning to use this AI resource.

Branches are created by Claude Code for each coding project.  This may not work for serious coding projects.

**To use this for multiple projects, do not merge branches with main.**

---
## PHP Network Monitoring Application

A self-hosted PHP web application that monitors web pages, TCP ports, and servers via ICMP ping. Sends email alerts when a service goes down (or recovers) and stores all results in SQLite or MySQL.

Code for this project is in the branch **claude/php-network-monitoring-SBsMv**

### Features
- **HTTP/HTTPS checks** — validates response codes (2xx/3xx = up)
- **TCP port checks** — tests raw socket connectivity
- **ICMP ping checks** — uses system `ping` command
- **Configurable thresholds** — alert only after N consecutive failures
- **Email notifications** — SMTP (STARTTLS/SSL) or PHP `mail()` fallback
- **Recovery alerts** — notified when a service comes back online
- **SQLite** (default, zero-config) or **MySQL** support
- **Web dashboard** — live service cards with uptime % and response time
- **History viewer** — paginated check log and alert log with 7-day uptime chart
- **Service manager** — add/edit/delete/enable/disable services
- **Settings page** — SMTP config, notification recipients, data retention

### Requirements
- PHP 8.1+ with extensions: `pdo_sqlite` (or `pdo_mysql`), `openssl`
- Web server: Apache or Nginx
- (Optional) `ping` binary in PATH for ICMP checks

### Quick Start

1. Clone/copy files into your web root
2. Ensure `data/` directory is writable by the web server
3. Visit the site — SQLite DB is auto-created on first load
4. Go to **Settings** → configure SMTP and add notification recipients
5. Go to **Services** → add your first service
6. Set up the cron job (see below)

### Cron Setup

```cron
* * * * * php /path/to/netmon/monitor.php --quiet >> /var/log/netmon.log 2>&1
```

Or run as a continuous daemon: `php monitor.php --loop`

### MySQL Setup (optional)

```bash
export DB_TYPE=mysql DB_HOST=localhost DB_NAME=netmon DB_USER=netmon DB_PASS=secret
```

### File Structure
```
netmon/
├── index.php          # Dashboard
├── services.php       # Service CRUD
├── history.php        # Check & alert history
├── settings.php       # SMTP, recipients, general config
├── api.php            # Action API (manual checks, JSON status)
├── monitor.php        # CLI monitoring script (cron/daemon)
├── db.php             # DB connection & auto-migration
├── includes/
│   ├── functions.php  # HTTP/TCP/ping check logic
│   ├── mailer.php     # SMTP mailer
│   ├── header.php     # Nav/header
│   └── footer.php     # Footer
├── assets/style.css   # Custom styles
└── data/              # SQLite DB (git-ignored)
```

---
## AWS Reserved Instances Expiry Monitor

A Python script that checks all EC2 Reserved Instances across one or more AWS regions and sends an email alert when any RI will expire within a configurable window (default: 30 days).

Code for this project is in the branch **claude/aws-reserved-instances-monitor-DmFzK**

---
