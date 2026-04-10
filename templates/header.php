<?php
/**
 * Shared page header template (Bootstrap 5).
 *
 * Variables expected:
 *   $pageTitle  - string  (page title)
 *   $user       - array|null (current user from auth_current_user())
 */
if (!isset($pageTitle)) {
    $pageTitle = APP_NAME;
}
$currentUser = $user ?? auth_current_user();
$_base = $assetsBase ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= htmlspecialchars($_base) ?>assets/style.css">
</head>
<body class="d-flex flex-column min-vh-100">

<?php if ($currentUser): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="<?= $_base ?>dashboard.php"><?= htmlspecialchars(APP_NAME) ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link" href="<?= $_base ?>dashboard.php">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= $_base ?>events.php">Events</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= $_base ?>my-signups.php">My Signups</a></li>
                <?php if ($currentUser['is_admin']): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Admin</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="<?= $_base ?>admin/index.php">Dashboard</a></li>
                        <li><a class="dropdown-item" href="<?= $_base ?>admin/events.php">Manage Events</a></li>
                        <li><a class="dropdown-item" href="<?= $_base ?>admin/users.php">Manage Users</a></li>
                        <li><a class="dropdown-item" href="<?= $_base ?>admin/event-report.php">Staffing Report</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= $_base ?>admin/send-reminders.php">Send Reminders</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
            <span class="navbar-text">
                <?php
                    $displayName = !empty($currentUser['name']) ? $currentUser['name'] : $currentUser['username'];
                ?>
                <?= htmlspecialchars($displayName) ?>
                <a href="<?= $_base ?>logout.php" class="btn btn-outline-light btn-sm ms-2">Logout</a>
            </span>
        </div>
    </div>
</nav>
<?php endif; ?>

<main class="container py-4 flex-grow-1">
