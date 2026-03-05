<?php
require_once __DIR__ . '/../db.php';
$siteName = setting('site_name', 'Network Monitor');
$page     = basename($_SERVER['SCRIPT_NAME'], '.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($siteName) ?></title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>

<nav class="navbar navbar-expand-md navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="index.php">
      <i class="bi bi-activity text-success"></i> <?= htmlspecialchars($siteName) ?>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMenu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMenu">
      <ul class="navbar-nav me-auto">
        <li class="nav-item">
          <a class="nav-link <?= $page === 'index' ? 'active' : '' ?>" href="index.php">
            <i class="bi bi-speedometer2"></i> Dashboard
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $page === 'services' ? 'active' : '' ?>" href="services.php">
            <i class="bi bi-hdd-network"></i> Services
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $page === 'history' ? 'active' : '' ?>" href="history.php">
            <i class="bi bi-clock-history"></i> History
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $page === 'settings' ? 'active' : '' ?>" href="settings.php">
            <i class="bi bi-gear"></i> Settings
          </a>
        </li>
      </ul>
      <span class="navbar-text text-muted small">
        <i class="bi bi-clock"></i> <?= date('Y-m-d H:i:s T') ?>
      </span>
    </div>
  </div>
</nav>

<div class="container-fluid py-3">
<?php
// Flash messages
if (!empty($_SESSION['flash'])) {
    foreach ($_SESSION['flash'] as $f) {
        echo '<div class="alert alert-' . $f['type'] . ' alert-dismissible fade show" role="alert">'
           . htmlspecialchars($f['msg'])
           . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
    $_SESSION['flash'] = [];
}
?>
