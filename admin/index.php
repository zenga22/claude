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

<h1 class="mb-4">Admin Dashboard</h1>

<div class="row row-cols-1 row-cols-md-3 g-3 mb-4">
    <div class="col">
        <div class="card shadow-sm text-center">
            <div class="card-body">
                <h2 class="display-6"><?= $totalEvents ?></h2>
                <p class="text-muted mb-0">Total Events</p>
                <small class="text-muted">(<?= $upcomingEvents ?> upcoming)</small>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card shadow-sm text-center">
            <div class="card-body">
                <h2 class="display-6"><?= $totalUsers ?></h2>
                <p class="text-muted mb-0">Users</p>
            </div>
        </div>
    </div>
    <div class="col">
        <div class="card shadow-sm text-center">
            <div class="card-body">
                <h2 class="display-6"><?= $totalSignups ?></h2>
                <p class="text-muted mb-0">Total Signups</p>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h5 class="card-title">Quick Links</h5>
        <div class="d-flex flex-wrap gap-2">
            <a href="events.php" class="btn btn-primary">Manage Events</a>
            <a href="event-form.php" class="btn btn-success">Create Event</a>
            <a href="users.php" class="btn btn-secondary">Manage Users</a>
            <a href="event-report.php" class="btn btn-info text-white">Staffing Report</a>
            <a href="send-reminders.php" class="btn btn-outline-secondary">Send Reminders</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
