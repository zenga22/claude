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

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">Manage Events</h1>
    <a href="event-form.php" class="btn btn-success">+ Create Event</a>
</div>

<?php if ($success === 'created'): ?>
    <div class="alert alert-success alert-dismissible fade show">Event created successfully.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php elseif ($success === 'updated'): ?>
    <div class="alert alert-success alert-dismissible fade show">Event updated successfully.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php elseif ($success === 'deleted'): ?>
    <div class="alert alert-info alert-dismissible fade show">Event deleted.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($events)): ?>
            <p class="text-muted text-center p-4 mb-0">No events yet. <a href="event-form.php">Create one</a>.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead class="table-light">
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
                            <td><span class="badge bg-secondary"><?= $ev['signup_count'] ?></span></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="../event.php?id=<?= $ev['id'] ?>" class="btn btn-outline-primary">View</a>
                                    <a href="event-form.php?id=<?= $ev['id'] ?>" class="btn btn-outline-secondary">Edit</a>
                                    <a href="event-report.php?id=<?= $ev['id'] ?>" class="btn btn-outline-info">Report</a>
                                </div>
                                <form method="post" action="event-delete.php" class="d-inline ms-1">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= $ev['id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm"
                                            onclick="return confirm('Delete this event and all its signups?')">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
