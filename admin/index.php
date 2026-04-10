<?php
/**
 * Admin dashboard — overview with quick links.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$user = auth_require_admin();
$assetsBase = '../';

$totalEvents  = db()->query('SELECT COUNT(*) FROM events')->fetchColumn();
$totalUsers   = db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalSignups = db()->query('SELECT COUNT(*) FROM signups')->fetchColumn();
$upcomingEvents = db()->query('SELECT COUNT(*) FROM events WHERE event_date >= CURDATE()')->fetchColumn();

$pageTitle = 'Admin Dashboard';
require_once __DIR__ . '/../templates/header.php';
?>

<h1 class="mb-2">Admin Dashboard</h1>

<div class="event-grid">
    <div class="card text-center">
        <h2><?= $totalEvents ?></h2>
        <p class="text-muted">Total Events</p>
        <p class="text-muted">(<?= $upcomingEvents ?> upcoming)</p>
    </div>
    <div class="card text-center">
        <h2><?= $totalUsers ?></h2>
        <p class="text-muted">Users</p>
    </div>
    <div class="card text-center">
        <h2><?= $totalSignups ?></h2>
        <p class="text-muted">Total Signups</p>
    </div>
</div>

<div class="card">
    <h2>Quick Links</h2>
    <p>
        <a href="events.php" class="btn btn-primary">Manage Events</a>
        <a href="event-form.php" class="btn btn-success">Create Event</a>
        <a href="users.php" class="btn btn-secondary">Manage Users</a>
        <a href="send-reminders.php" class="btn btn-secondary">Send Reminders</a>
    </p>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
