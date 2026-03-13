<?php
require_once __DIR__ . '/db.php';

// Run sync on every page load so new files are picked up automatically
$sync_result = sync_json_files();
$files       = get_all_files();
$new_count   = count($sync_result['added']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .navbar-brand i { margin-right: .35rem; }
        .table-hover tbody tr:hover { cursor: default; }
        .badge-new { animation: fadeIn .6s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-dark bg-primary shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="index.php">
            <i class="bi bi-file-earmark-text"></i><?= htmlspecialchars(APP_NAME) ?>
        </a>
        <button id="syncBtn" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-repeat me-1"></i>Sync Now
        </button>
    </div>
</nav>

<div class="container py-4">

    <!-- Sync alert -->
    <div id="syncAlert"></div>

    <?php if ($new_count > 0): ?>
    <div class="alert alert-success alert-dismissible fade show badge-new" role="alert">
        <i class="bi bi-check-circle-fill me-1"></i>
        <strong><?= $new_count ?> new file<?= $new_count > 1 ? 's' : '' ?></strong> added to the database on page load.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Stats row -->
    <div class="row g-3 mb-4">
        <div class="col-sm-4">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="display-6 fw-bold text-primary"><?= count($files) ?></div>
                <div class="text-muted small">Total Submissions</div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="display-6 fw-bold text-success"><?= count(glob(JSON_DIR . '/*.json') ?: []) ?></div>
                <div class="text-muted small">JSON Files on Disk</div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="display-6 fw-bold text-secondary"><?= $sync_result['skipped'] ?></div>
                <div class="text-muted small">Already Indexed</div>
            </div>
        </div>
    </div>

    <!-- File list table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex align-items-center justify-content-between">
            <span class="fw-semibold"><i class="bi bi-table me-1"></i>Submission Files</span>
            <span class="badge bg-secondary"><?= count($files) ?> records</span>
        </div>

        <?php if (empty($files)): ?>
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-inbox display-4 d-block mb-2"></i>
            No JSON files found in <code><?= htmlspecialchars(JSON_DIR) ?></code>.<br>
            Add <code>.json</code> files to that directory and click <strong>Sync Now</strong>.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th><i class="bi bi-calendar3 me-1"></i>Date Submitted</th>
                        <th><i class="bi bi-person me-1"></i>Name</th>
                        <th><i class="bi bi-envelope me-1"></i>Email</th>
                        <th><i class="bi bi-file-earmark-code me-1"></i>Filename</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($files as $i => $file): ?>
                    <tr>
                        <td class="text-muted small"><?= $i + 1 ?></td>
                        <td><?= htmlspecialchars($file['date_submitted'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($file['name'] ?? '—') ?></td>
                        <td>
                            <?php if (!empty($file['email'])): ?>
                                <a href="mailto:<?= htmlspecialchars($file['email']) ?>">
                                    <?= htmlspecialchars($file['email']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><code class="small"><?= htmlspecialchars($file['filename']) ?></code></td>
                        <td class="text-end">
                            <a href="detail.php?id=<?= (int)$file['id'] ?>"
                               class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye me-1"></i>View Details
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('syncBtn').addEventListener('click', function () {
    const btn   = this;
    const alert = document.getElementById('syncAlert');

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Syncing…';

    fetch('sync.php')
        .then(r => r.json())
        .then(data => {
            const cls = data.added.length > 0 ? 'alert-success' : 'alert-info';
            const icon = data.added.length > 0 ? 'check-circle-fill' : 'info-circle-fill';
            alert.innerHTML = `
                <div class="alert ${cls} alert-dismissible fade show" role="alert">
                    <i class="bi bi-${icon} me-1"></i>${data.message}
                    ${data.added.length > 0 ? '<br><small>Reloading…</small>' : ''}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>`;
            if (data.added.length > 0) {
                setTimeout(() => location.reload(), 1200);
            }
        })
        .catch(() => {
            alert.innerHTML = `
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>Sync request failed.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>`;
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Sync Now';
        });
});
</script>
</body>
</html>
