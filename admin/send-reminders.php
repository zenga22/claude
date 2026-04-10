<?php
/**
 * Admin — send reminder emails to users signed up for upcoming events.
 * This page provides a web interface; see also cron/send-reminders.php for CLI use.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';

$user = auth_require_admin();
$assetsBase = '../';

$sent    = 0;
$failed  = 0;
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_validate($token)) {
        $results[] = 'Invalid form submission.';
    } else {
        $daysBefore = max(0, (int) ($_POST['days_before'] ?? REMINDER_DAYS_BEFORE));
        $targetDate = date('Y-m-d', strtotime("+{$daysBefore} days"));

        // Find signups for events on the target date that haven't received a reminder
        $stmt = db()->prepare('
            SELECT s.id AS signup_id, u.username, u.name, u.email,
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
            $results[] = "No pending reminders for events on $targetDate.";
        } else {
            $updateStmt = db()->prepare('UPDATE signups SET reminder_sent = 1 WHERE id = :id');

            foreach ($signups as $s) {
                $ok = send_signup_reminder(
                    ['username' => $s['username'], 'name' => $s['name'], 'email' => $s['email']],
                    ['title' => $s['title'], 'event_date' => $s['event_date'], 'location' => $s['location']],
                    ['function_name' => $s['function_name']],
                    ['start_time' => $s['start_time'], 'end_time' => $s['end_time']]
                );

                if ($ok) {
                    $updateStmt->execute(['id' => $s['signup_id']]);
                    $sent++;
                } else {
                    $failed++;
                }
            }
            $results[] = "Sent $sent reminder(s) for events on $targetDate.";
            if ($failed > 0) {
                $results[] = "$failed email(s) failed to send.";
            }
        }
    }
}

$pageTitle = 'Send Reminders';
require_once __DIR__ . '/../templates/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
        <li class="breadcrumb-item active">Send Reminders</li>
    </ol>
</nav>

<div class="card shadow-sm">
    <div class="card-body">
        <h1 class="card-title h4 mb-2">Send Reminder Emails</h1>
        <p class="text-muted">
            Send reminder emails to all users signed up for events happening on a specific date.
            Only users who haven't already received a reminder will be emailed.
        </p>

        <?php foreach ($results as $msg): ?>
            <div class="alert alert-info"><?= htmlspecialchars($msg) ?></div>
        <?php endforeach; ?>

        <form method="post" action="send-reminders.php">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label for="days_before" class="form-label">Send reminders for events happening in this many days:</label>
                <input type="number" id="days_before" name="days_before" class="form-control"
                       value="<?= REMINDER_DAYS_BEFORE ?>" min="0" max="30" style="max-width: 200px;">
                <div class="form-text">0 = today, 1 = tomorrow, etc.</div>
            </div>
            <button type="submit" class="btn btn-primary">Send Reminders</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
