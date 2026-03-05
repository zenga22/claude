#!/usr/bin/env php
<?php
/**
 * NetMon CLI monitoring daemon.
 *
 * Usage:
 *   php monitor.php             # Run checks once (for cron)
 *   php monitor.php --loop      # Loop forever (for systemd/supervisor)
 *   php monitor.php --purge     # Purge old check records and exit
 *
 * Recommended cron (every minute):
 *   * * * * * /usr/bin/php /path/to/netmon/monitor.php >> /var/log/netmon.log 2>&1
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

$loop  = in_array('--loop',  $argv ?? []);
$purge = in_array('--purge', $argv ?? []);
$quiet = in_array('--quiet', $argv ?? []);

function log_msg(string $msg, bool $quiet = false): void {
    if (!$quiet) echo "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
}

if ($purge) {
    $days    = (int) setting('retain_days', '30');
    $deleted = purge_old_checks($days);
    log_msg("Purged {$deleted} check records older than {$days} days.", $quiet);
    exit(0);
}

// Prevent overlapping runs via a lock file
$lockFile = sys_get_temp_dir() . '/netmon.lock';

function acquire_lock(string $lockFile): bool {
    $fp = fopen($lockFile, 'c');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }
    fwrite($fp, getmypid());
    fflush($fp);
    // Store handle in global to prevent GC releasing the lock
    $GLOBALS['_lock_fp'] = $fp;
    return true;
}

function release_lock(string $lockFile): void {
    if (isset($GLOBALS['_lock_fp'])) {
        flock($GLOBALS['_lock_fp'], LOCK_UN);
        fclose($GLOBALS['_lock_fp']);
        unset($GLOBALS['_lock_fp']);
    }
    @unlink($lockFile);
}

function run_all_checks(bool $quiet): void {
    $services = get_services_due();
    if (empty($services)) {
        log_msg("No services due for checking.", $quiet);
        return;
    }

    log_msg("Checking " . count($services) . " service(s)...", $quiet);

    foreach ($services as $service) {
        $result = run_check($service);
        $rt     = $result['response_time'] ?? '-';
        $status = strtoupper($result['status']);
        $err    = $result['error'] ? " | " . $result['error'] : '';
        log_msg("  [{$status}] {$service['name']} ({$service['type']}://{$service['host']}) {$rt}ms{$err}", $quiet);
    }
}

if ($loop) {
    log_msg("NetMon loop started (interval: " . setting('check_interval', '60') . "s).", $quiet);
    while (true) {
        if (acquire_lock($lockFile)) {
            try {
                run_all_checks($quiet);
            } finally {
                release_lock($lockFile);
            }
        } else {
            log_msg("Another check is already running; skipping.", $quiet);
        }
        $interval = max(10, (int) setting('check_interval', '60'));
        sleep($interval);
    }
} else {
    if (!acquire_lock($lockFile)) {
        log_msg("Another instance is running; exiting.");
        exit(0);
    }
    try {
        run_all_checks($quiet);
    } finally {
        release_lock($lockFile);
    }
    exit(0);
}
