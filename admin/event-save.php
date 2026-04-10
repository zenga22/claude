<?php
/**
 * Admin — save (create or update) an event with functions and periods.
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

$eventId     = (int) ($_POST['id'] ?? 0);
$title       = trim($_POST['title'] ?? '');
$eventDate   = trim($_POST['event_date'] ?? '');
$location    = trim($_POST['location'] ?? '');
$description = trim($_POST['description'] ?? '');
$functionsData = $_POST['functions'] ?? [];

if ($title === '' || $eventDate === '' || $location === '') {
    header('Location: event-form.php' . ($eventId ? "?id=$eventId" : ''));
    exit;
}

$pdo = db();
$pdo->beginTransaction();

try {
    if ($eventId > 0) {
        // Update existing event
        $stmt = $pdo->prepare('UPDATE events SET title = :t, event_date = :d, location = :l, description = :desc WHERE id = :id');
        $stmt->execute(['t' => $title, 'd' => $eventDate, 'l' => $location, 'desc' => $description, 'id' => $eventId]);

        // Remove old functions and periods (cascade will handle periods)
        $pdo->prepare('DELETE FROM event_functions WHERE event_id = :eid')->execute(['eid' => $eventId]);

        $action = 'updated';
    } else {
        // Create new event
        $stmt = $pdo->prepare('INSERT INTO events (title, event_date, location, description) VALUES (:t, :d, :l, :desc)');
        $stmt->execute(['t' => $title, 'd' => $eventDate, 'l' => $location, 'desc' => $description]);
        $eventId = (int) $pdo->lastInsertId();

        $action = 'created';
    }

    // Insert functions and periods
    foreach ($functionsData as $fnData) {
        $fnName = trim($fnData['name'] ?? '');
        if ($fnName === '') {
            continue;
        }
        $fnDesc = trim($fnData['description'] ?? '');

        $stmt = $pdo->prepare('INSERT INTO event_functions (event_id, function_name, description) VALUES (:eid, :name, :desc)');
        $stmt->execute(['eid' => $eventId, 'name' => $fnName, 'desc' => $fnDesc]);
        $fnId = (int) $pdo->lastInsertId();

        $periods = $fnData['periods'] ?? [];
        foreach ($periods as $pData) {
            $start = trim($pData['start'] ?? '');
            $end   = trim($pData['end'] ?? '');
            $max   = max(1, (int) ($pData['max'] ?? 1));

            if ($start === '' || $end === '') {
                continue;
            }

            $stmt = $pdo->prepare('INSERT INTO event_periods (function_id, start_time, end_time, max_signups) VALUES (:fid, :s, :e, :m)');
            $stmt->execute(['fid' => $fnId, 's' => $start, 'e' => $end, 'm' => $max]);
        }
    }

    $pdo->commit();
    header("Location: events.php?success=$action");
} catch (Exception $e) {
    $pdo->rollBack();
    header('Location: event-form.php' . ($eventId ? "?id=$eventId" : ''));
}
exit;
