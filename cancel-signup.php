<?php
/**
 * Cancel a signup.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$user = auth_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: events.php');
    exit;
}

$signupId = (int) ($_POST['signup_id'] ?? 0);
$eventId  = (int) ($_POST['event_id'] ?? 0);
$token    = $_POST['csrf_token'] ?? '';

if (!csrf_validate($token)) {
    header("Location: event.php?id=$eventId&error=csrf");
    exit;
}

if ($signupId <= 0) {
    header("Location: event.php?id=$eventId&error=invalid");
    exit;
}

// Only allow users to cancel their own signups
$stmt = db()->prepare('DELETE FROM signups WHERE id = :id AND user_id = :uid');
$stmt->execute(['id' => $signupId, 'uid' => $user['id']]);

$redirect = $eventId > 0 ? "event.php?id=$eventId&success=cancel" : 'my-signups.php?success=cancel';
header("Location: $redirect");
exit;
