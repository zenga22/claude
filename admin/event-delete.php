<?php
/**
 * Admin — delete an event (cascade deletes functions, periods, and signups).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$user = auth_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: events.php');
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!csrf_validate($token)) {
    header('Location: events.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
if ($id > 0) {
    $stmt = db()->prepare('DELETE FROM events WHERE id = :id');
    $stmt->execute(['id' => $id]);
}

header('Location: events.php?success=deleted');
exit;
