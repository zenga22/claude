<?php
// =============================================================================
// index.php — Email alias database checker
// =============================================================================
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ---------------------------------------------------------------------------
// Helper: parse alias file
// Returns associative array: [ 'alias' => ['dest1', 'dest2', ...], ... ]
// ---------------------------------------------------------------------------
function parseAliasFile(string $path, string $format): array
{
    $aliases = [];

    if (!is_readable($path)) {
        return $aliases;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lines as $line) {
        // Skip comments
        if (ltrim($line)[0] === '#') {
            continue;
        }

        if ($format === 'virtual') {
            // virtual map: alias@domain   dest1@example.com dest2@example.com
            // whitespace-separated; first token is the alias key
            $parts = preg_split('/\s+/', trim($line), 2);
            if (count($parts) < 2) {
                continue;
            }
            [$key, $rest] = $parts;
            $destinations = array_filter(array_map('trim', preg_split('/[\s,]+/', $rest)));
        } else {
            // aliases format: localname: dest1, dest2
            if (!str_contains($line, ':')) {
                continue;
            }
            [$key, $rest] = explode(':', $line, 2);
            $key          = trim($key);
            $destinations = array_filter(array_map('trim', explode(',', $rest)));
        }

        // Keep only destination tokens that look like e-mail addresses
        $emailDests = array_values(array_filter($destinations, function (string $d): bool {
            return filter_var($d, FILTER_VALIDATE_EMAIL) !== false;
        }));

        if ($emailDests !== []) {
            $aliases[$key] = $emailDests;
        }
    }

    ksort($aliases);
    return $aliases;
}

// ---------------------------------------------------------------------------
// Helper: connect to MySQL via PDO
// ---------------------------------------------------------------------------
function dbConnect(): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,
    ]);
}

// ---------------------------------------------------------------------------
// Helper: check a list of e-mail addresses against the database
// Returns array: [ 'email' => bool $found, ... ]
// ---------------------------------------------------------------------------
function checkEmailsInDb(array $emails): array
{
    $results = [];

    if ($emails === []) {
        return $results;
    }

    $pdo         = dbConnect();
    $placeholders = implode(',', array_fill(0, count($emails), '?'));
    $sql          = sprintf(
        'SELECT %s FROM %s WHERE %s IN (%s)',
        DB_COLUMN, DB_TABLE, DB_COLUMN, $placeholders
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($emails));
    $found = array_column($stmt->fetchAll(), DB_COLUMN);

    foreach ($emails as $email) {
        $results[$email] = in_array($email, $found, true);
    }

    return $results;
}

// ---------------------------------------------------------------------------
// Application logic
// ---------------------------------------------------------------------------
$error        = null;
$aliases      = [];
$selectedKey  = null;
$checkResults = [];

$fileReadable = is_readable(ALIAS_FILE);

if ($fileReadable) {
    $aliases = parseAliasFile(ALIAS_FILE, ALIAS_FORMAT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['alias_key'])) {
    $selectedKey = trim($_POST['alias_key']);

    if (!array_key_exists($selectedKey, $aliases)) {
        $error = 'Selected alias not found in the alias file.';
    } else {
        try {
            $checkResults = checkEmailsInDb($aliases[$selectedKey]);
        } catch (PDOException $e) {
            $error = 'Database error: ' . htmlspecialchars($e->getMessage());
        }
    }
}

$foundCount   = count(array_filter($checkResults));
$missingCount = count($checkResults) - $foundCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Alias DB Checker</title>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          rel="stylesheet"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
          rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .card { border: none; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
        .result-found    { color: #198754; }
        .result-missing  { color: #dc3545; }
        .badge-found     { background-color: #198754; }
        .badge-missing   { background-color: #dc3545; }
        .alias-count     { font-size: .8rem; color: #6c757d; }
        .table th        { background-color: #343a40; color: #fff; }
        .summary-bar     { border-left: 4px solid #0d6efd; }
    </style>
</head>
<body>
<div class="container py-4">

    <!-- Header -->
    <div class="row mb-4">
        <div class="col">
            <h2 class="mb-0">
                <i class="bi bi-envelope-check me-2 text-primary"></i>Email Alias DB Checker
            </h2>
            <p class="text-muted mt-1 mb-0">
                Parse a mail alias file and verify forwarding addresses against a database.
            </p>
        </div>
    </div>

    <!-- Config info row -->
    <div class="row mb-4 g-3">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2 text-muted">
                        <i class="bi bi-file-text me-1"></i>Alias File
                    </h6>
                    <code><?= htmlspecialchars(ALIAS_FILE) ?></code>
                    <?php if ($fileReadable): ?>
                        <span class="badge bg-success ms-2">readable</span>
                        <div class="alias-count mt-1">
                            <?= count($aliases) ?> alias<?= count($aliases) !== 1 ? 'es' : '' ?> found
                            &nbsp;&bull;&nbsp; format: <strong><?= htmlspecialchars(ALIAS_FORMAT) ?></strong>
                        </div>
                    <?php else: ?>
                        <span class="badge bg-danger ms-2">not readable</span>
                        <div class="text-danger small mt-1">
                            Check the path and file permissions in <code>config.php</code>.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2 text-muted">
                        <i class="bi bi-database me-1"></i>Database Target
                    </h6>
                    <code><?= htmlspecialchars(DB_HOST) ?>:<?= DB_PORT ?> / <?= htmlspecialchars(DB_NAME) ?></code>
                    <div class="alias-count mt-1">
                        table: <strong><?= htmlspecialchars(DB_TABLE) ?></strong>
                        &nbsp;&bull;&nbsp; column: <strong><?= htmlspecialchars(DB_COLUMN) ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Error alert -->
    <?php if ($error !== null): ?>
        <div class="alert alert-danger d-flex align-items-center" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <div><?= $error ?></div>
        </div>
    <?php endif; ?>

    <!-- Selection form -->
    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title mb-3">
                <i class="bi bi-search me-1 text-primary"></i>Select Forwarding Alias
            </h5>

            <?php if (!$fileReadable): ?>
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-circle me-1"></i>
                    Cannot read the alias file. Please update <code>ALIAS_FILE</code>
                    in <code>config.php</code>.
                </div>
            <?php elseif ($aliases === []): ?>
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    No parseable aliases with e-mail destinations were found in the file.
                </div>
            <?php else: ?>
                <form method="post" action="">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-8">
                            <label for="alias_key" class="form-label fw-semibold">
                                Forwarding alias / key
                            </label>
                            <select name="alias_key" id="alias_key" class="form-select" required>
                                <option value="" disabled <?= $selectedKey === null ? 'selected' : '' ?>>
                                    — choose an alias —
                                </option>
                                <?php foreach ($aliases as $key => $dests): ?>
                                    <option value="<?= htmlspecialchars($key) ?>"
                                        <?= $selectedKey === $key ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($key) ?>
                                        (<?= count($dests) ?> address<?= count($dests) !== 1 ? 'es' : '' ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search me-1"></i>Check Database
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Results -->
    <?php if ($checkResults !== []): ?>
        <?php
            $alias = $selectedKey;
            $total = count($checkResults);
        ?>

        <!-- Summary bar -->
        <div class="card summary-bar mb-3">
            <div class="card-body py-2">
                <div class="d-flex flex-wrap gap-3 align-items-center">
                    <span class="fw-semibold">
                        Results for: <code><?= htmlspecialchars($alias) ?></code>
                    </span>
                    <span class="badge bg-secondary"><?= $total ?> total</span>
                    <span class="badge badge-found text-white">
                        <i class="bi bi-check-circle me-1"></i><?= $foundCount ?> found
                    </span>
                    <span class="badge badge-missing text-white">
                        <i class="bi bi-x-circle me-1"></i><?= $missingCount ?> not found
                    </span>
                </div>
            </div>
        </div>

        <!-- Results table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-bordered mb-0">
                        <thead>
                            <tr>
                                <th style="width:3rem">#</th>
                                <th>Forward-to Email Address</th>
                                <th style="width:12rem" class="text-center">Database Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $i = 1; foreach ($checkResults as $email => $found): ?>
                                <tr class="<?= $found ? 'table-success' : 'table-danger' ?>">
                                    <td class="text-muted"><?= $i++ ?></td>
                                    <td>
                                        <i class="bi bi-envelope me-1 text-secondary"></i>
                                        <?= htmlspecialchars($email) ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($found): ?>
                                            <span class="result-found fw-semibold">
                                                <i class="bi bi-check-circle-fill me-1"></i>Found
                                            </span>
                                        <?php else: ?>
                                            <span class="result-missing fw-semibold">
                                                <i class="bi bi-x-circle-fill me-1"></i>Not Found
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <td colspan="2" class="text-end fw-semibold">Totals</td>
                                <td class="text-center">
                                    <span class="text-success fw-semibold"><?= $foundCount ?> found</span>
                                    &nbsp;/&nbsp;
                                    <span class="text-danger fw-semibold"><?= $missingCount ?> missing</span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

    <?php endif; ?>

    <footer class="text-center text-muted small mt-5">
        Email Alias DB Checker &mdash; config: <code>config.php</code>
    </footer>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmE6VHgCmCaC7/MbqHY2TiRic"
        crossorigin="anonymous"></script>
</body>
</html>
