<?php
/**
 * Admin — copy an event with its functions and time periods (no signups).
 * Creates a duplicate and redirects to the edit form for the new event.
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

$sourceId = (int) ($_POST['id'] ?? 0);
if ($sourceId <= 0) {
    header('Location: events.php');
    exit;
}

$pdo = db();

// Fetch the source event
$stmt = $pdo->prepare('SELECT * FROM events WHERE id = :id');
$stmt->execute(['id' => $sourceId]);
$source = $stmt->fetch();

if (!$source) {
    header('Location: events.php');
    exit;
}

$pdo->beginTransaction();

try {
    // Insert copied event with "(Copy)" suffix
    $stmt = $pdo->prepare('INSERT INTO events (title, event_date, location, description) VALUES (:t, :d, :l, :desc)');
    $stmt->execute([
        't'    => $source['title'] . ' (Copy)',
        'd'    => $source['event_date'],
        'l'    => $source['location'],
        'desc' => $source['description'],
    ]);
    $newEventId = (int) $pdo->lastInsertId();

    // Copy functions
    $stmt = $pdo->prepare('SELECT * FROM event_functions WHERE event_id = :eid ORDER BY id');
    $stmt->execute(['eid' => $sourceId]);
    $functions = $stmt->fetchAll();

    foreach ($functions as $fn) {
        $stmt = $pdo->prepare('INSERT INTO event_functions (event_id, function_name, description) VALUES (:eid, :name, :desc)');
        $stmt->execute([
            'eid'  => $newEventId,
            'name' => $fn['function_name'],
            'desc' => $fn['description'],
        ]);
        $newFnId = (int) $pdo->lastInsertId();

        // Copy role restrictions for this function
        $rStmt = $pdo->prepare('SELECT role_id FROM event_function_roles WHERE function_id = :fid');
        $rStmt->execute(['fid' => $fn['id']]);
        $roleIds = $rStmt->fetchAll();
        foreach ($roleIds as $r) {
            $pdo->prepare('INSERT INTO event_function_roles (function_id, role_id) VALUES (:fid, :rid)')
                ->execute(['fid' => $newFnId, 'rid' => $r['role_id']]);
        }

        // Copy periods for this function
        $pStmt = $pdo->prepare('SELECT * FROM event_periods WHERE function_id = :fid ORDER BY start_time');
        $pStmt->execute(['fid' => $fn['id']]);
        $periods = $pStmt->fetchAll();

        foreach ($periods as $p) {
            $ins = $pdo->prepare('INSERT INTO event_periods (function_id, start_time, end_time, max_signups) VALUES (:fid, :s, :e, :m)');
            $ins->execute([
                'fid' => $newFnId,
                's'   => $p['start_time'],
                'e'   => $p['end_time'],
                'm'   => $p['max_signups'],
            ]);
        }
    }

    $pdo->commit();

    // Redirect to the edit form so the admin can adjust the copy
    header("Location: event-form.php?id=$newEventId&copied=1");
} catch (Exception $e) {
    $pdo->rollBack();
    header('Location: events.php');
}
exit;
