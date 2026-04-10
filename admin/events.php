<?php
/**
 * Admin — list and manage events.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$user = auth_require_admin();
$assetsBase = '../';

$events = db()->query('
    SELECT e.*,
           (SELECT COUNT(*) FROM signups s
            JOIN event_periods ep ON s.period_id = ep.id
            JOIN event_functions ef ON ep.function_id = ef.id
            WHERE ef.event_id = e.id) AS signup_count
    FROM events e
    ORDER BY e.event_date DESC
')->fetchAll();

$success = $_GET['success'] ?? '';

$pageTitle = 'Manage Events';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="flex-between mb-2">
    <h1>Manage Events</h1>
    <a href="event-form.php" class="btn btn-success">+ Create Event</a>
</div>

<?php if ($success === 'created'): ?>
    <div class="alert alert-success">Event created successfully.</div>
<?php elseif ($success === 'updated'): ?>
    <div class="alert alert-success">Event updated successfully.</div>
<?php elseif ($success === 'deleted'): ?>
    <div class="alert alert-info">Event deleted.</div>
<?php endif; ?>

<div class="card">
    <?php if (empty($events)): ?>
        <p class="text-muted text-center">No events yet. <a href="event-form.php">Create one</a>.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Date</th>
                    <th>Location</th>
                    <th>Signups</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($events as $ev): ?>
                <tr>
                    <td><?= htmlspecialchars($ev['title']) ?></td>
                    <td><?= date('M j, Y', strtotime($ev['event_date'])) ?></td>
                    <td><?= htmlspecialchars($ev['location']) ?></td>
                    <td><?= $ev['signup_count'] ?></td>
                    <td>
                        <a href="../event.php?id=<?= $ev['id'] ?>" class="btn btn-primary btn-sm">View</a>
                        <a href="event-form.php?id=<?= $ev['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                        <form method="post" action="event-delete.php" style="display:inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $ev['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm"
                                    onclick="return confirm('Delete this event and all its signups?')">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
