<?php
require_once __DIR__ . '/db.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$record = $id ? get_file_record($id) : null;

if (!$record) {
    http_response_code(404);
    $error = 'Record not found.';
}

// Load raw JSON for full field display
$json_data   = null;
$json_error  = null;
if ($record) {
    $filepath = JSON_DIR . '/' . $record['filename'];
    if (file_exists($filepath)) {
        $raw = file_get_contents($filepath);
        $json_data = json_decode($raw, true);
        if ($json_data === null) {
            $json_error = 'Could not parse JSON: ' . json_last_error_msg();
        }
    } else {
        $json_error = 'JSON file not found on disk: ' . htmlspecialchars($record['filename']);
    }
}

/**
 * Recursively render an array/object value as an HTML snippet.
 * Simple scalars are returned as plain escaped strings.
 */
function render_value(mixed $value): string {
    if (is_array($value)) {
        $rows = '';
        $is_list = array_is_list($value);
        foreach ($value as $k => $v) {
            $key_html = $is_list
                ? '<span class="badge bg-secondary me-2">' . ((int)$k + 1) . '</span>'
                : '<strong class="me-2">' . htmlspecialchars((string)$k) . ':</strong>';
            $rows .= '<li class="list-group-item py-1 px-2">' . $key_html . render_value($v) . '</li>';
        }
        return '<ul class="list-group list-group-flush border rounded mt-1">' . $rows . '</ul>';
    }
    if (is_bool($value)) {
        return $value
            ? '<span class="badge bg-success">true</span>'
            : '<span class="badge bg-danger">false</span>';
    }
    if (is_null($value)) {
        return '<span class="text-muted fst-italic">null</span>';
    }
    return '<span>' . htmlspecialchars((string)$value) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>File Detail – <?= htmlspecialchars(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .field-key {
            width: 220px;
            min-width: 160px;
            font-weight: 600;
            color: #495057;
            vertical-align: top;
        }
        pre.json-raw {
            background: #212529;
            color: #f8f9fa;
            border-radius: .5rem;
            font-size: .82rem;
            max-height: 420px;
            overflow: auto;
        }
        .badge-indexed { font-size: .78rem; }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-dark bg-primary shadow-sm">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="index.php">
            <i class="bi bi-file-earmark-text me-1"></i><?= htmlspecialchars(APP_NAME) ?>
        </a>
        <a href="index.php" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back to List
        </a>
    </div>
</nav>

<div class="container py-4">

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($error) ?>
    </div>
    <a href="index.php" class="btn btn-primary">
        <i class="bi bi-arrow-left me-1"></i>Return to list
    </a>

<?php else: ?>

    <!-- Breadcrumb -->
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">Submissions</a></li>
            <li class="breadcrumb-item active" aria-current="page">
                <?= htmlspecialchars($record['filename']) ?>
            </li>
        </ol>
    </nav>

    <!-- Header card -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                <div>
                    <h5 class="mb-1">
                        <i class="bi bi-file-earmark-code me-1 text-primary"></i>
                        <?= htmlspecialchars($record['filename']) ?>
                    </h5>
                    <span class="badge bg-light text-dark border badge-indexed">
                        <i class="bi bi-database me-1"></i>DB record #<?= (int)$record['id'] ?>
                    </span>
                    <span class="badge bg-light text-dark border badge-indexed ms-1">
                        <i class="bi bi-clock me-1"></i>Indexed: <?= htmlspecialchars($record['synced_at']) ?>
                    </span>
                </div>
                <?php if (!$json_error): ?>
                <button class="btn btn-sm btn-outline-secondary"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#rawJson">
                    <i class="bi bi-braces me-1"></i>Raw JSON
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($json_error): ?>
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-circle-fill me-1"></i><?= $json_error ?>
        <hr>
        <p class="mb-0 small">Showing database-cached fields only.</p>
    </div>
    <?php endif; ?>

    <!-- Indexed fields summary -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-primary text-white fw-semibold">
            <i class="bi bi-database me-1"></i>Indexed Fields (SQLite Cache)
        </div>
        <div class="table-responsive">
            <table class="table table-bordered align-middle mb-0">
                <tbody>
                    <tr>
                        <td class="field-key">Date Submitted</td>
                        <td><?= htmlspecialchars($record['date_submitted'] ?? '—') ?></td>
                    </tr>
                    <tr>
                        <td class="field-key">Name</td>
                        <td><?= htmlspecialchars($record['name'] ?? '—') ?></td>
                    </tr>
                    <tr>
                        <td class="field-key">Email</td>
                        <td>
                            <?php if (!empty($record['email'])): ?>
                                <a href="mailto:<?= htmlspecialchars($record['email']) ?>">
                                    <?= htmlspecialchars($record['email']) ?>
                                </a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($json_data !== null): ?>
    <!-- All fields table -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-list-columns me-1"></i>All JSON Fields
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-top mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:220px;">Field</th>
                        <th>Value</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($json_data as $key => $value): ?>
                    <tr>
                        <td class="field-key"><code><?= htmlspecialchars((string)$key) ?></code></td>
                        <td><?= render_value($value) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Raw JSON collapsible -->
    <div class="collapse mb-4" id="rawJson">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-white fw-semibold">
                <i class="bi bi-braces me-1"></i>Raw JSON
            </div>
            <div class="card-body p-0">
                <pre class="json-raw p-3 mb-0"><?= htmlspecialchars(
                    json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                ) ?></pre>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <a href="index.php" class="btn btn-primary">
        <i class="bi bi-arrow-left me-1"></i>Back to List
    </a>

<?php endif; ?>

</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
