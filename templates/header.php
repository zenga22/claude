<?php
/**
 * Shared page header template.
 *
 * Variables expected:
 *   $pageTitle  - string  (page title)
 *   $user       - array|null (current user from auth_current_user())
 */
if (!isset($pageTitle)) {
    $pageTitle = APP_NAME;
}
$currentUser = $user ?? auth_current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetsBase ?? '') ?>assets/style.css">
</head>
<body>
<header class="site-header">
    <div class="container header-inner">
        <a href="<?= htmlspecialchars($assetsBase ?? '') ?>dashboard.php" class="logo"><?= htmlspecialchars(APP_NAME) ?></a>
        <?php if ($currentUser): ?>
        <nav>
            <a href="<?= htmlspecialchars($assetsBase ?? '') ?>dashboard.php">Dashboard</a>
            <a href="<?= htmlspecialchars($assetsBase ?? '') ?>events.php">Events</a>
            <a href="<?= htmlspecialchars($assetsBase ?? '') ?>my-signups.php">My Signups</a>
            <?php if ($currentUser['is_admin']): ?>
                <a href="<?= htmlspecialchars($assetsBase ?? '') ?>admin/index.php">Admin</a>
            <?php endif; ?>
            <span class="user-info">
                Logged in as <strong><?= htmlspecialchars($currentUser['username']) ?></strong>
                | <a href="<?= htmlspecialchars($assetsBase ?? '') ?>logout.php">Logout</a>
            </span>
        </nav>
        <?php endif; ?>
    </div>
</header>
<main class="container">
