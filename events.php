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

<div class="flex-between mb-2">
    <h1>Events</h1>
    <div>
        <a href="events.php?filter=upcoming" class="btn btn-sm <?= $filter !== 'past' ? 'btn-primary' : 'btn-secondary' ?>">Upcoming</a>
        <a href="events.php?filter=past" class="btn btn-sm <?= $filter === 'past' ? 'btn-primary' : 'btn-secondary' ?>">Past</a>
    </div>
</div>

<?php if (empty($events)): ?>
    <div class="card">
        <p class="text-muted text-center">No <?= $filter === 'past' ? 'past' : 'upcoming' ?> events found.</p>
    </div>
<?php else: ?>
    <div class="event-grid">
        <?php foreach ($events as $ev): ?>
        <div class="card event-card">
            <h3><a href="event.php?id=<?= $ev['id'] ?>"><?= htmlspecialchars($ev['title']) ?></a></h3>
            <div class="event-date"><?= date('l, F j, Y', strtotime($ev['event_date'])) ?></div>
            <div class="event-location"><?= htmlspecialchars($ev['location']) ?></div>
            <?php if ($ev['description']): ?>
                <p class="mt-1 text-muted"><?= htmlspecialchars(mb_strimwidth($ev['description'], 0, 150, '...')) ?></p>
            <?php endif; ?>
            <div class="mt-1">
                <a href="event.php?id=<?= $ev['id'] ?>" class="btn btn-primary btn-sm">View Details</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
