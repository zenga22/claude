#!/usr/bin/env php
<?php
/**
 * Heartbeat Monitor – Cron Script
 *
 * Run this script every minute via cron to detect missed heartbeats
 * and dispatch email alerts.
 *
 * Recommended crontab entry:
 *   * * * * * /usr/bin/php /path/to/heartbeat-monitor/monitor.php >> /var/log/heartbeat-monitor.log 2>&1
 *
 * Usage:
 *   php monitor.php          # Normal run
 *   php monitor.php --test   # Send a test email and exit
 *   php monitor.php --status # Print current status table and exit
 */

declare(strict_types=1);

// CLI-only guard
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require_once __DIR__ . '/lib/HeartbeatStore.php';
require_once __DIR__ . '/lib/Mailer.php';

// ----- Load config -----------------------------------------------------------

$cfgFile = file_exists(__DIR__ . '/config.local.php')
    ? __DIR__ . '/config.local.php'
    : __DIR__ . '/config.php';

$config = require $cfgFile;

$store   = new HeartbeatStore($config['data_dir']);
$mailer  = new Mailer($config['email'] ?? []);
$cooldown = (int) ($config['alert_cooldown'] ?? 3600);

// ----- Handle CLI flags ------------------------------------------------------

$args = array_slice($argv ?? [], 1);

if (in_array('--test', $args, true)) {
    echo "[test] Sending test email…\n";
    try {
        $mailer->send(
            'Test Alert',
            "This is a test alert from Heartbeat Monitor.\nIf you received this, email delivery is working.",
            '<p>This is a <strong>test alert</strong> from Heartbeat Monitor.</p>'
            . '<p>If you received this, email delivery is working correctly.</p>'
        );
        echo "[test] Test email sent successfully.\n";
    } catch (\Throwable $e) {
        echo "[test] ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
    exit(0);
}

if (in_array('--status', $args, true)) {
    printStatusTable($config, $store);
    exit(0);
}

// ----- Main monitoring loop --------------------------------------------------

$now = time();
log_msg("Checking " . count($config['computers']) . " computer(s)…");

foreach ($config['computers'] as $id => $cfg) {
    $rec      = $store->get($id);
    $lastSeen = $rec['last_seen'];
    $name     = $cfg['name'] ?? $id;

    // Determine if the beat is overdue
    if ($lastSeen === null) {
        $overdue = true;
        $age     = null;
    } else {
        $deadline = $lastSeen + $cfg['interval'] + $cfg['grace'];
        $overdue  = ($now >= $deadline);
        $age      = $now - $lastSeen;
    }

    if ($overdue) {
        $store->recordMiss($id);
    }

    if (!$store->shouldAlert($id, $cfg, $cooldown)) {
        $statusLabel = $overdue ? 'OVERDUE (cooldown active or threshold not met)' : 'OK';
        log_msg("  [$id] $statusLabel");
        continue;
    }

    // Build and send alert
    log_msg("  [$id] ALERT – sending email…");

    $ageStr = $age !== null ? formatDuration($age) : 'never';
    $subject = "ALERT: $name is not responding";

    $text = buildTextAlert($id, $name, $cfg, $rec, $ageStr, $config['base_url'] ?? '');
    $html = buildHtmlAlert($id, $name, $cfg, $rec, $ageStr, $config['base_url'] ?? '');

    try {
        $mailer->send($subject, $text, $html);
        $store->recordAlertSent($id);
        log_msg("  [$id] Alert email sent.");
    } catch (\Throwable $e) {
        log_msg("  [$id] ERROR sending alert: " . $e->getMessage());
    }
}

log_msg("Done.");
exit(0);

// =============================================================================
// Helpers
// =============================================================================

function log_msg(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

function formatDuration(int $seconds): string
{
    if ($seconds < 60) return "{$seconds}s";
    if ($seconds < 3600) return round($seconds / 60) . 'm';
    if ($seconds < 86400) return round($seconds / 3600, 1) . 'h';
    return round($seconds / 86400, 1) . 'd';
}

function buildTextAlert(string $id, string $name, array $cfg, array $rec, string $ageStr, string $baseUrl): string
{
    $lastSeen  = $rec['last_seen']  ? date('Y-m-d H:i:s T', $rec['last_seen']) : 'Never';
    $missCount = $rec['miss_count'] ?? 0;
    $ip        = $rec['ip']        ?: 'unknown';
    $hostname  = $rec['hostname']  ?: 'unknown';
    $interval  = $cfg['interval'];

    $lines = [
        "HEARTBEAT ALERT",
        str_repeat("=", 40),
        "",
        "Computer : $name ($id)",
        "IP       : $ip",
        "Hostname : $hostname",
        "Last seen: $lastSeen ($ageStr ago)",
        "Expected : every " . formatDuration($interval),
        "Misses   : $missCount consecutive",
        "",
        "No heartbeat has been received within the configured window.",
        "Please check the computer immediately.",
        "",
    ];

    if ($baseUrl) {
        $lines[] = "Dashboard: $baseUrl";
    }

    return implode("\n", $lines);
}

function buildHtmlAlert(string $id, string $name, array $cfg, array $rec, string $ageStr, string $baseUrl): string
{
    $lastSeen  = $rec['last_seen']  ? date('Y-m-d H:i:s T', $rec['last_seen']) : 'Never';
    $missCount = $rec['miss_count'] ?? 0;
    $ip        = htmlspecialchars($rec['ip']       ?: 'unknown', ENT_QUOTES);
    $hostname  = htmlspecialchars($rec['hostname'] ?: 'unknown', ENT_QUOTES);
    $interval  = formatDuration($cfg['interval']);
    $safeName  = htmlspecialchars($name, ENT_QUOTES);
    $safeId    = htmlspecialchars($id,   ENT_QUOTES);
    $dashLink  = $baseUrl
        ? "<p><a href=\"" . htmlspecialchars($baseUrl, ENT_QUOTES) . "\">Open Dashboard</a></p>"
        : '';

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto;padding:20px">
  <div style="background:#c0392b;color:#fff;padding:16px 20px;border-radius:4px 4px 0 0">
    <h2 style="margin:0">&#9888; Heartbeat Alert</h2>
  </div>
  <div style="border:1px solid #ddd;border-top:none;padding:20px;border-radius:0 0 4px 4px">
    <p style="font-size:16px">The computer <strong>{$safeName}</strong> (<code>{$safeId}</code>) has
    <strong>stopped sending heartbeats</strong>.</p>

    <table style="width:100%;border-collapse:collapse;margin:16px 0">
      <tr style="background:#f5f5f5">
        <th style="text-align:left;padding:8px;border:1px solid #ddd;width:35%">Field</th>
        <th style="text-align:left;padding:8px;border:1px solid #ddd">Value</th>
      </tr>
      <tr>
        <td style="padding:8px;border:1px solid #ddd">Computer</td>
        <td style="padding:8px;border:1px solid #ddd">{$safeName} ({$safeId})</td>
      </tr>
      <tr style="background:#f9f9f9">
        <td style="padding:8px;border:1px solid #ddd">IP Address</td>
        <td style="padding:8px;border:1px solid #ddd">{$ip}</td>
      </tr>
      <tr>
        <td style="padding:8px;border:1px solid #ddd">Hostname</td>
        <td style="padding:8px;border:1px solid #ddd">{$hostname}</td>
      </tr>
      <tr style="background:#f9f9f9">
        <td style="padding:8px;border:1px solid #ddd">Last Seen</td>
        <td style="padding:8px;border:1px solid #ddd">{$lastSeen} ({$ageStr} ago)</td>
      </tr>
      <tr>
        <td style="padding:8px;border:1px solid #ddd">Expected Interval</td>
        <td style="padding:8px;border:1px solid #ddd">{$interval}</td>
      </tr>
      <tr style="background:#f9f9f9">
        <td style="padding:8px;border:1px solid #ddd">Consecutive Misses</td>
        <td style="padding:8px;border:1px solid #ddd;color:#c0392b;font-weight:bold">{$missCount}</td>
      </tr>
    </table>

    <p style="color:#c0392b;font-weight:bold">Please check this computer immediately.</p>
    {$dashLink}
    <hr style="border:none;border-top:1px solid #eee;margin:20px 0">
    <p style="font-size:12px;color:#999">Sent by Heartbeat Monitor &bull; {$lastSeen}</p>
  </div>
</body>
</html>
HTML;
}

function printStatusTable(array $config, HeartbeatStore $store): void
{
    $now = time();
    printf("%-20s %-18s %-25s %-8s %-8s\n", 'ID', 'Status', 'Last Seen', 'Misses', 'Alerts');
    echo str_repeat('-', 85) . "\n";

    foreach ($config['computers'] as $id => $cfg) {
        $rec      = $store->get($id);
        $lastSeen = $rec['last_seen'] ? date('Y-m-d H:i:s', $rec['last_seen']) : 'never';
        $status   = strtoupper($rec['status'] ?? 'unknown');
        $misses   = $rec['miss_count']   ?? 0;
        $alerts   = $rec['alert_count']  ?? 0;
        printf("%-20s %-18s %-25s %-8d %-8d\n", $id, $status, $lastSeen, $misses, $alerts);
    }
}
