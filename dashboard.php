<?php
/**
 * User dashboard — shows upcoming signups and available events.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$user = auth_require_login();

// Upcoming signups for this user
$stmt = db()->prepare('
    SELECT s.id AS signup_id, e.id AS event_id, e.title, e.event_date, e.location,
           ef.function_name, ep.start_time, ep.end_time
    FROM signups s
    JOIN event_periods ep ON s.period_id = ep.id
    JOIN event_functions ef ON ep.function_id = ef.id
    JOIN events e ON ef.event_id = e.id
    WHERE s.user_id = :uid AND e.event_date >= CURDATE()
    ORDER BY e.event_date, ep.start_time
    LIMIT 10
');
$stmt->execute(['uid' => $user['id']]);
$upcomingSignups = $stmt->fetchAll();

// Upcoming events
$upcomingEvents = db()->query('
    SELECT * FROM events WHERE event_date >= CURDATE() ORDER BY event_date LIMIT 6
')->fetchAll();

$pageTitle = 'Dashboard';
require_once __DIR__ . '/templates/header.php';
?>

<h1 class="mb-2">Dashboard</h1>

<?php if (isset($_GET['error']) && $_GET['error'] === 'access_denied'): ?>
    <div class="alert alert-danger">You do not have permission to access that page.</div>
<?php endif; ?>

<div class="card">
    <h2>Your Upcoming Signups</h2>
    <?php if (empty($upcomingSignups)): ?>
        <p class="text-muted">You have no upcoming signups. <a href="events.php">Browse events</a> to sign up.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Date</th>
                    <th>Location</th>
                    <th>Function</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($upcomingSignups as $s): ?>
                <tr>
                    <td><a href="event.php?id=<?= $s['event_id'] ?>"><?= htmlspecialchars($s['title']) ?></a></td>
                    <td><?= date('M j, Y', strtotime($s['event_date'])) ?></td>
                    <td><?= htmlspecialchars($s['location']) ?></td>
                    <td><?= htmlspecialchars($s['function_name']) ?></td>
                    <td><?= date('g:i A', strtotime($s['start_time'])) ?> &ndash; <?= date('g:i A', strtotime($s['end_time'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <a href="my-signups.php" class="btn btn-secondary btn-sm">View All Signups</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="flex-between mb-1">
        <h2>Upcoming Events</h2>
        <a href="events.php" class="btn btn-primary btn-sm">View All Events</a>
    </div>
    <?php if (empty($upcomingEvents)): ?>
        <p class="text-muted">No upcoming events at this time.</p>
    <?php else: ?>
        <div class="event-grid">
            <?php foreach ($upcomingEvents as $ev): ?>
            <div class="card event-card">
                <h3><a href="event.php?id=<?= $ev['id'] ?>"><?= htmlspecialchars($ev['title']) ?></a></h3>
                <div class="event-date"><?= date('l, F j, Y', strtotime($ev['event_date'])) ?></div>
                <div class="event-location"><?= htmlspecialchars($ev['location']) ?></div>
                <?php if ($ev['description']): ?>
                    <p class="mt-1 text-muted"><?= htmlspecialchars(mb_strimwidth($ev['description'], 0, 120, '...')) ?></p>
                <?php endif; ?>
                <div class="mt-1">
                    <a href="event.php?id=<?= $ev['id'] ?>" class="btn btn-primary btn-sm">View &amp; Sign Up</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
