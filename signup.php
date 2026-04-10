<?php
/**
 * Handle signup POST — inserts a signup and sends confirmation email.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mail.php';

$user = auth_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: events.php');
    exit;
}

$periodId = (int) ($_POST['period_id'] ?? 0);
$eventId  = (int) ($_POST['event_id'] ?? 0);
$token    = $_POST['csrf_token'] ?? '';

if (!csrf_validate($token)) {
    header("Location: event.php?id=$eventId&error=csrf");
    exit;
}

if ($periodId <= 0 || $eventId <= 0) {
    header("Location: event.php?id=$eventId&error=invalid");
    exit;
}

$pdo = db();

// Verify the period exists and belongs to the event, and check availability
$stmt = $pdo->prepare('
    SELECT ep.*, ef.event_id, ef.function_name, ef.id AS fn_id,
           e.title, e.event_date, e.location,
           (SELECT COUNT(*) FROM signups WHERE period_id = ep.id) AS signup_count
    FROM event_periods ep
    JOIN event_functions ef ON ep.function_id = ef.id
    JOIN events e ON ef.event_id = e.id
    WHERE ep.id = :pid AND ef.event_id = :eid
');
$stmt->execute(['pid' => $periodId, 'eid' => $eventId]);
$period = $stmt->fetch();

if (!$period) {
    header("Location: event.php?id=$eventId&error=invalid");
    exit;
}

if ($period['signup_count'] >= $period['max_signups']) {
    header("Location: event.php?id=$eventId&error=full");
    exit;
}

// Check if already signed up
$stmt = $pdo->prepare('SELECT id FROM signups WHERE period_id = :pid AND user_id = :uid');
$stmt->execute(['pid' => $periodId, 'uid' => $user['id']]);
if ($stmt->fetch()) {
    header("Location: event.php?id=$eventId&error=already");
    exit;
}

// Insert signup
$stmt = $pdo->prepare('INSERT INTO signups (period_id, user_id) VALUES (:pid, :uid)');
$stmt->execute(['pid' => $periodId, 'uid' => $user['id']]);

// Fetch full user record for email (includes name)
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute(['id' => $user['id']]);
$fullUser = $stmt->fetch();

// Send confirmation email
send_signup_confirmation(
    $fullUser,
    ['title' => $period['title'], 'event_date' => $period['event_date'], 'location' => $period['location']],
    ['function_name' => $period['function_name']],
    ['start_time' => $period['start_time'], 'end_time' => $period['end_time']]
);

header("Location: event.php?id=$eventId&success=signup");
exit;
