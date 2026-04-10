<?php
/**
 * List all events (upcoming first, then past).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$user = auth_require_login();

$filter = $_GET['filter'] ?? 'upcoming';

if ($filter === 'past') {
    $events = db()->query('SELECT * FROM events WHERE event_date < CURDATE() ORDER BY event_date DESC')->fetchAll();
} else {
    $events = db()->query('SELECT * FROM events WHERE event_date >= CURDATE() ORDER BY event_date')->fetchAll();
}

$pageTitle = 'Events';
require_once __DIR__ . '/templates/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">Events</h1>
    <div class="btn-group">
        <a href="events.php?filter=upcoming" class="btn btn-sm <?= $filter !== 'past' ? 'btn-primary' : 'btn-outline-secondary' ?>">Upcoming</a>
        <a href="events.php?filter=past" class="btn btn-sm <?= $filter === 'past' ? 'btn-primary' : 'btn-outline-secondary' ?>">Past</a>
    </div>
</div>

<?php if (empty($events)): ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <p class="text-muted text-center mb-0">No <?= $filter === 'past' ? 'past' : 'upcoming' ?> events found.</p>
        </div>
    </div>
<?php else: ?>
    <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3">
        <?php foreach ($events as $ev): ?>
        <div class="col">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <h5 class="card-title"><a href="event.php?id=<?= $ev['id'] ?>" class="text-decoration-none text-dark"><?= htmlspecialchars($ev['title']) ?></a></h5>
                    <p class="text-muted small mb-1"><?= date('l, F j, Y', strtotime($ev['event_date'])) ?></p>
                    <p class="text-muted small mb-2"><?= htmlspecialchars($ev['location']) ?></p>
                    <?php if ($ev['description']): ?>
                        <p class="text-muted small"><?= htmlspecialchars(mb_strimwidth($ev['description'], 0, 150, '...')) ?></p>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-transparent border-0 pt-0">
                    <a href="event.php?id=<?= $ev['id'] ?>" class="btn btn-primary btn-sm">View Details</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
