<?php

declare(strict_types=1);

/**
 * Meetup.com GraphQL Introspection — OAuth2 callback handler
 *
 * Accessible at: https://secularhub.org/util_zeek5Gak/callback
 * (routed here from the extensionless URL by .htaccess)
 */

session_start();

require_once __DIR__ . '/meetup_lib.php';

loadDotEnv(__DIR__ . '/.env');

$clientId     = getConfig('MEETUP_CLIENT_ID');
$clientSecret = getConfig('MEETUP_CLIENT_SECRET');

// ── Validate OAuth2 state (CSRF protection) ───────────────────────────────────
$receivedState = $_GET['state'] ?? '';
$expectedState = $_SESSION['oauth2_state'] ?? '';

unset($_SESSION['oauth2_state']); // consume immediately

if ($receivedState === '' || $receivedState !== $expectedState) {
    renderError('Invalid or missing OAuth2 state parameter. Please try again.', 400);
}

// ── Handle OAuth2 error response ──────────────────────────────────────────────
if (isset($_GET['error'])) {
    $desc = $_GET['error_description'] ?? $_GET['error'];
    renderError('Meetup authorization denied: ' . $desc, 403);
}

// ── Exchange authorization code for tokens ────────────────────────────────────
$code = $_GET['code'] ?? '';
if ($code === '') {
    renderError('No authorization code received from Meetup.', 400);
}

try {
    $token = exchangeCode($code, $clientId, $clientSecret);
} catch (RuntimeException $e) {
    renderError('Token exchange failed: ' . $e->getMessage(), 502);
}

saveToken($token);

// ── Run GraphQL introspection ─────────────────────────────────────────────────
$schemaFiles = [
    'json' => __DIR__ . '/meetup_schema_raw.json',
    'sdl'  => __DIR__ . '/meetup_schema.graphql',
];

try {
    [$jsonFile, $sdlFile, $typeCount] = runIntrospection($token, $schemaFiles);
} catch (RuntimeException $e) {
    renderError('Introspection failed: ' . $e->getMessage(), 502);
}

// ── Read a snippet of the SDL for preview ────────────────────────────────────
$sdlPreview = '';
if (file_exists($sdlFile)) {
    $lines      = file($sdlFile, FILE_IGNORE_NEW_LINES);
    $sdlPreview = implode("\n", array_slice($lines ?? [], 0, 60));
}

$jsonSize = round(filesize($jsonFile) / 1024, 1);
$sdlSize  = round(filesize($sdlFile)  / 1024, 1);
$stamp    = date('Y-m-d H:i:s T');

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Introspection Complete — Meetup GraphQL</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; padding: 2rem; }
    .wrap { max-width: 820px; margin: 0 auto; }
    .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 2rem; margin-bottom: 1.5rem; }
    h1 { font-size: 1.4rem; font-weight: 700; margin-bottom: 0.25rem; }
    h2 { font-size: 1rem; font-weight: 600; margin-bottom: 1rem; color: #94a3b8; }
    .success-banner { display: flex; align-items: center; gap: 0.75rem; background: #052e16; border: 1px solid #14532d; border-radius: 8px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; color: #86efac; font-weight: 600; }
    .check { font-size: 1.4rem; }
    .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
    .stat { background: #0f172a; border-radius: 8px; padding: 1rem; }
    .stat-value { font-size: 1.5rem; font-weight: 700; color: #f97316; }
    .stat-label { font-size: 0.8rem; color: #64748b; margin-top: 0.25rem; }
    .file-row { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid #334155; font-size: 0.875rem; }
    .file-row:last-child { border-bottom: none; }
    .file-name { font-family: monospace; color: #7dd3fc; }
    .file-size { color: #64748b; }
    pre { background: #0f172a; border: 1px solid #334155; border-radius: 8px; padding: 1.25rem; overflow-x: auto; font-size: 0.8rem; line-height: 1.6; color: #cbd5e1; max-height: 400px; overflow-y: auto; white-space: pre; font-family: 'Cascadia Code', 'Fira Code', monospace; }
    .btn { display: inline-block; padding: 0.6rem 1.25rem; border-radius: 8px; font-size: 0.875rem; font-weight: 600; cursor: pointer; border: none; text-decoration: none; margin-top: 1rem; }
    .btn-secondary { background: #334155; color: #e2e8f0; }
    .btn-secondary:hover { background: #3f5068; }
    .timestamp { font-size: 0.75rem; color: #475569; margin-top: 0.5rem; }
  </style>
</head>
<body>
<div class="wrap">

  <div class="card">
    <h1>Meetup GraphQL Introspection</h1>
    <p class="timestamp">Completed <?= htmlspecialchars($stamp) ?></p>

    <br>

    <div class="success-banner">
      <span class="check">&#10003;</span>
      Schema fetched and saved successfully.
    </div>

    <div class="stats">
      <div class="stat">
        <div class="stat-value"><?= $typeCount ?></div>
        <div class="stat-label">GraphQL types</div>
      </div>
      <div class="stat">
        <div class="stat-value"><?= $jsonSize ?> KB</div>
        <div class="stat-label">JSON introspection</div>
      </div>
      <div class="stat">
        <div class="stat-value"><?= $sdlSize ?> KB</div>
        <div class="stat-label">SDL schema</div>
      </div>
    </div>

    <h2>Output Files (server-side)</h2>
    <div class="file-row">
      <span class="file-name">meetup_schema_raw.json</span>
      <span class="file-size"><?= $jsonSize ?> KB &mdash; raw introspection JSON</span>
    </div>
    <div class="file-row">
      <span class="file-name">meetup_schema.graphql</span>
      <span class="file-size"><?= $sdlSize ?> KB &mdash; GraphQL SDL</span>
    </div>

    <a href="index.php" class="btn btn-secondary">&larr; Back</a>
  </div>

  <?php if ($sdlPreview !== ''): ?>
  <div class="card">
    <h2>Schema Preview (first 60 lines)</h2>
    <pre><?= htmlspecialchars($sdlPreview) ?></pre>
  </div>
  <?php endif; ?>

</div>
</body>
</html>
<?php

// ── Helper: render a fatal error page and exit ────────────────────────────────
function renderError(string $message, int $httpStatus = 500): never
{
    http_response_code($httpStatus);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Error</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;'
        . 'align-items:center;justify-content:center;min-height:100vh;padding:2rem;}'
        . '.box{background:#1e293b;border:1px solid #7f1d1d;border-radius:12px;padding:2rem;max-width:520px;}'
        . 'h1{color:#f87171;margin-bottom:1rem;}p{color:#cbd5e1;margin-bottom:1.5rem;font-size:.9rem;}'
        . 'a{color:#7dd3fc;}</style></head><body>'
        . '<div class="box"><h1>Authorization Error</h1>'
        . '<p>' . htmlspecialchars($message) . '</p>'
        . '<p><a href="index.php">&larr; Try again</a></p></div></body></html>';
    exit;
}
