<?php
/**
 * CLI cron script to send reminder emails.
 *
 * Usage:
 *   php cron/send-reminders.php [days_before]
 *
 * Schedule via crontab, e.g. run daily at 8 AM:
 *   0 8 * * * /usr/bin/php /path/to/cron/send-reminders.php 1
 *
 * The argument is the number of days before the event to send reminders.
 * Defaults to the REMINDER_DAYS_BEFORE config value.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'This script must be run from the command line.';
    exit(1);
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';

$daysBefore = isset($argv[1]) ? max(0, (int) $argv[1]) : REMINDER_DAYS_BEFORE;
$targetDate = date('Y-m-d', strtotime("+{$daysBefore} days"));

echo "Sending reminders for events on {$targetDate}...\n";

$stmt = db()->prepare('
    SELECT s.id AS signup_id, u.username, u.email,
           e.title, e.event_date, e.location,
           ef.function_name,
           ep.start_time, ep.end_time
    FROM signups s
    JOIN users u ON s.user_id = u.id
    JOIN event_periods ep ON s.period_id = ep.id
    JOIN event_functions ef ON ep.function_id = ef.id
    JOIN events e ON ef.event_id = e.id
    WHERE e.event_date = :target_date AND s.reminder_sent = 0
    ORDER BY u.username, ep.start_time
');
$stmt->execute(['target_date' => $targetDate]);
$signups = $stmt->fetchAll();

if (empty($signups)) {
    echo "No pending reminders.\n";
    exit(0);
}

$updateStmt = db()->prepare('UPDATE signups SET reminder_sent = 1 WHERE id = :id');
$sent   = 0;
$failed = 0;

foreach ($signups as $s) {
    $ok = send_signup_reminder(
        ['username' => $s['username'], 'email' => $s['email']],
        ['title' => $s['title'], 'event_date' => $s['event_date'], 'location' => $s['location']],
        ['function_name' => $s['function_name']],
        ['start_time' => $s['start_time'], 'end_time' => $s['end_time']]
    );

    if ($ok) {
        $updateStmt->execute(['id' => $s['signup_id']]);
        $sent++;
        echo "  [OK]   Reminder sent to {$s['username']} ({$s['email']})\n";
    } else {
        $failed++;
        echo "  [FAIL] Could not send to {$s['username']} ({$s['email']})\n";
    }
}

echo "\nDone. Sent: $sent, Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
