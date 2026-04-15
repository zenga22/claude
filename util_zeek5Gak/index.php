<?php

declare(strict_types=1);

/**
 * Meetup.com GraphQL Introspection — Entry point
 *
 * Credentials: set MEETUP_CLIENT_ID and MEETUP_CLIENT_SECRET in a .env file
 * placed in the same directory as this script (blocked from web access by
 * .htaccess), or as Apache SetEnv directives in the VirtualHost config.
 *
 * Register your OAuth2 app at: https://www.meetup.com/api/oauth/list/
 * Redirect URI must be set to: https://secularhub.org/util_zeek5Gak/callback
 */

session_start();

require_once __DIR__ . '/meetup_lib.php';

// ── Load credentials ─────────────────────────────────────────────────────────
loadDotEnv(__DIR__ . '/.env');

$clientId     = getConfig('MEETUP_CLIENT_ID');
$clientSecret = getConfig('MEETUP_CLIENT_SECRET');

$configError = ($clientId === '' || $clientSecret === '')
    ? 'MEETUP_CLIENT_ID and MEETUP_CLIENT_SECRET must be set in .env or server environment.'
    : null;

// ── Token state ───────────────────────────────────────────────────────────────
$token       = loadCachedToken();
$schemaFiles = [
    'json' => __DIR__ . '/meetup_schema_raw.json',
    'sdl'  => __DIR__ . '/meetup_schema.graphql',
];
$jsonExists  = file_exists($schemaFiles['json']);
$sdlExists   = file_exists($schemaFiles['sdl']);

// ── Handle "Start Authorization" action ──────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'authorize' && $configError === null) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth2_state'] = $state;

    $authUrl = MEETUP_AUTH_URL . '?' . http_build_query([
        'client_id'     => $clientId,
        'response_type' => 'code',
        'redirect_uri'  => MEETUP_REDIRECT_URI,
        'scope'         => 'basic',
        'state'         => $state,
    ]);

    header('Location: ' . $authUrl);
    exit;
}

// ── Handle "Re-run Introspection" POST ────────────────────────────────────────
$introspectionResult = null;
$introspectionError  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action'])
    && $_POST['action'] === 'introspect'
    && $token !== null
) {
    try {
        [$jsonFile, $sdlFile, $typeCount] = runIntrospection($token, $schemaFiles);
        $jsonExists = true;
        $sdlExists  = true;
        $introspectionResult = "Introspection complete — $typeCount types found. Schema files refreshed.";
    } catch (RuntimeException $e) {
        $introspectionError = $e->getMessage();
    }
}

// ── Handle "Clear Token" POST ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear') {
    unset($_SESSION['oauth2_token']);
    $tokenFile = __DIR__ . '/.meetup_token.json';
    if (file_exists($tokenFile)) {
        unlink($tokenFile);
    }
    header('Location: ' . strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?'));
    exit;
}

// ── HTML output ───────────────────────────────────────────────────────────────
$tokenStatus = match (true) {
    $token !== null  => ['label' => 'Valid', 'color' => '#22c55e',
                         'detail' => 'Expires ' . date('Y-m-d H:i T', $token->expiresAt)],
    default          => ['label' => 'None', 'color' => '#94a3b8', 'detail' => 'Not authorized yet'],
};

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Meetup GraphQL Introspection</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem; }
    .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 2.5rem; width: 100%; max-width: 560px; }
    h1 { font-size: 1.4rem; font-weight: 700; margin-bottom: 0.25rem; }
    .subtitle { color: #94a3b8; font-size: 0.875rem; margin-bottom: 2rem; }
    .section { margin-bottom: 1.5rem; }
    .label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; margin-bottom: 0.5rem; }
    .status-row { display: flex; align-items: center; gap: 0.75rem; }
    .dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
    .status-detail { font-size: 0.875rem; color: #cbd5e1; }
    .file-list { list-style: none; }
    .file-list li { font-size: 0.875rem; color: #cbd5e1; padding: 0.35rem 0; border-bottom: 1px solid #1e293b; display: flex; align-items: center; gap: 0.5rem; }
    .file-list li:last-child { border-bottom: none; }
    .badge { font-size: 0.7rem; padding: 0.1rem 0.4rem; border-radius: 4px; font-weight: 600; }
    .badge-ok { background: #14532d; color: #86efac; }
    .badge-missing { background: #1c1917; color: #78716c; }
    .btn { display: inline-block; padding: 0.65rem 1.4rem; border-radius: 8px; font-size: 0.9rem; font-weight: 600; cursor: pointer; border: none; text-decoration: none; }
    .btn-primary { background: #f97316; color: #fff; }
    .btn-primary:hover { background: #ea6c0a; }
    .btn-secondary { background: #334155; color: #e2e8f0; }
    .btn-secondary:hover { background: #3f5068; }
    .btn-danger { background: #7f1d1d; color: #fca5a5; }
    .btn-danger:hover { background: #991b1b; }
    .actions { display: flex; gap: 0.75rem; flex-wrap: wrap; }
    .alert { padding: 0.85rem 1rem; border-radius: 8px; font-size: 0.875rem; margin-bottom: 1.5rem; }
    .alert-error { background: #450a0a; border: 1px solid #7f1d1d; color: #fca5a5; }
    .alert-success { background: #052e16; border: 1px solid #14532d; color: #86efac; }
    .divider { border: none; border-top: 1px solid #334155; margin: 1.5rem 0; }
    code { font-family: monospace; font-size: 0.8rem; background: #0f172a; padding: 0.1rem 0.3rem; border-radius: 4px; color: #7dd3fc; }
  </style>
</head>
<body>
<div class="card">
  <h1>Meetup GraphQL Introspection</h1>
  <p class="subtitle">Fetches the full Meetup.com GraphQL schema via OAuth2 + introspection.</p>

  <?php if ($configError !== null): ?>
  <div class="alert alert-error"><?= htmlspecialchars($configError) ?></div>
  <?php endif; ?>

  <?php if ($introspectionError !== null): ?>
  <div class="alert alert-error"><?= htmlspecialchars($introspectionError) ?></div>
  <?php endif; ?>

  <?php if ($introspectionResult !== null): ?>
  <div class="alert alert-success"><?= htmlspecialchars($introspectionResult) ?></div>
  <?php endif; ?>

  <div class="section">
    <div class="label">Access Token</div>
    <div class="status-row">
      <div class="dot" style="background:<?= $tokenStatus['color'] ?>"></div>
      <div>
        <strong><?= $tokenStatus['label'] ?></strong>
        <div class="status-detail"><?= htmlspecialchars($tokenStatus['detail']) ?></div>
      </div>
    </div>
  </div>

  <div class="section">
    <div class="label">Output Files</div>
    <ul class="file-list">
      <li>
        <span class="badge <?= $jsonExists ? 'badge-ok' : 'badge-missing' ?>"><?= $jsonExists ? 'exists' : 'missing' ?></span>
        <code>meetup_schema_raw.json</code> — raw introspection JSON
      </li>
      <li>
        <span class="badge <?= $sdlExists ? 'badge-ok' : 'badge-missing' ?>"><?= $sdlExists ? 'exists' : 'missing' ?></span>
        <code>meetup_schema.graphql</code> — SDL representation
      </li>
    </ul>
  </div>

  <hr class="divider">

  <div class="actions">
    <?php if ($token === null): ?>
      <?php if ($configError === null): ?>
      <a href="?action=authorize" class="btn btn-primary">Authorize with Meetup</a>
      <?php endif; ?>
    <?php else: ?>
      <form method="POST" style="display:inline">
        <input type="hidden" name="action" value="introspect">
        <button type="submit" class="btn btn-primary">Run Introspection</button>
      </form>
      <a href="?action=authorize" class="btn btn-secondary">Re-authorize</a>
      <form method="POST" style="display:inline" onsubmit="return confirm('Clear saved token?')">
        <input type="hidden" name="action" value="clear">
        <button type="submit" class="btn btn-danger">Clear Token</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
