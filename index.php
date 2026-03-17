<?php
/**
 * Heartbeat Monitor – Web Dashboard
 *
 * Shows the real-time status of all monitored computers in a browser.
 * Optionally protected by HTTP Basic Auth (configure in config.php).
 *
 * Auto-refreshes every 60 seconds.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/HeartbeatStore.php';

// ----- Load config -----------------------------------------------------------

$cfgFile = file_exists(__DIR__ . '/config.local.php')
    ? __DIR__ . '/config.local.php'
    : __DIR__ . '/config.php';

$config = require $cfgFile;

// ----- Optional HTTP Basic Auth ----------------------------------------------

if (!empty($config['dashboard_auth'])) {
    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW']   ?? '';
    if (
        !hash_equals((string) $config['dashboard_auth_user'],     $user) ||
        !hash_equals((string) $config['dashboard_auth_password'], $pass)
    ) {
        header('WWW-Authenticate: Basic realm="Heartbeat Monitor"');
        http_response_code(401);
        exit('Unauthorized');
    }
}

// ----- Gather data -----------------------------------------------------------

$store      = new HeartbeatStore($config['data_dir']);
$computers  = $config['computers'] ?? [];
$now        = time();
$rows       = [];
$anyProblem = false;

foreach ($computers as $id => $cfg) {
    $rec      = $store->get($id);
    $lastSeen = $rec['last_seen'];
    $name     = $cfg['name'] ?? $id;
    $interval = (int) $cfg['interval'];
    $grace    = (int) ($cfg['grace'] ?? 0);

    if ($lastSeen === null) {
        $statusClass = 'unknown';
        $statusLabel = 'Unknown';
        $ageStr      = '—';
        $nextDue     = '—';
    } else {
        $age      = $now - $lastSeen;
        $deadline = $lastSeen + $interval + $grace;
        $ageStr   = formatAge($age);
        $nextDue  = date('H:i:s', $deadline);

        if ($now < $deadline) {
            $statusClass = 'ok';
            $statusLabel = 'OK';
        } elseif ($now < $deadline + $interval) {
            $statusClass = 'late';
            $statusLabel = 'Late';
            $anyProblem  = true;
        } else {
            $statusClass = 'down';
            $statusLabel = 'Down';
            $anyProblem  = true;
        }
    }

    $rows[] = [
        'id'          => $id,
        'name'        => $name,
        'status'      => $statusClass,
        'statusLabel' => $statusLabel,
        'lastSeen'    => $lastSeen ? date('Y-m-d H:i:s', $lastSeen) : '—',
        'age'         => $ageStr,
        'interval'    => formatAge($interval),
        'nextDue'     => $nextDue,
        'ip'          => $rec['ip']        ?: '—',
        'hostname'    => $rec['hostname']  ?: '—',
        'misses'      => $rec['miss_count']  ?? 0,
        'alerts'      => $rec['alert_count'] ?? 0,
        'lastAlert'   => $rec['last_alert_sent'] ? date('Y-m-d H:i:s', $rec['last_alert_sent']) : '—',
    ];
}

$totalOk   = count(array_filter($rows, fn($r) => $r['status'] === 'ok'));
$totalDown = count(array_filter($rows, fn($r) => in_array($r['status'], ['late', 'down'])));

// ----- Helpers ---------------------------------------------------------------

function formatAge(int $seconds): string
{
    if ($seconds < 60)   return "{$seconds}s";
    if ($seconds < 3600) return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
    if ($seconds < 86400) return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
    return floor($seconds / 86400) . 'd ' . floor(($seconds % 86400) / 3600) . 'h';
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta http-equiv="refresh" content="60">
  <title>Heartbeat Monitor</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      background: #f0f2f5;
      color: #333;
      min-height: 100vh;
    }

    header {
      background: #1a1a2e;
      color: #fff;
      padding: 16px 24px;
      display: flex;
      align-items: center;
      gap: 16px;
    }
    header h1 { font-size: 1.4rem; font-weight: 600; }
    header .subtitle { font-size: 0.85rem; color: #aaa; margin-top: 2px; }

    .pulse {
      width: 12px; height: 12px;
      border-radius: 50%;
      background: <?= $anyProblem ? '#e74c3c' : '#2ecc71' ?>;
      box-shadow: 0 0 0 0 <?= $anyProblem ? 'rgba(231,76,60,0.4)' : 'rgba(46,204,113,0.4)' ?>;
      animation: pulse 1.8s infinite;
      flex-shrink: 0;
    }
    @keyframes pulse {
      0%   { box-shadow: 0 0 0 0 currentColor; }
      70%  { box-shadow: 0 0 0 8px transparent; }
      100% { box-shadow: 0 0 0 0 transparent; }
    }

    .summary-bar {
      display: flex;
      gap: 16px;
      padding: 16px 24px;
      background: #fff;
      border-bottom: 1px solid #e0e0e0;
      flex-wrap: wrap;
    }
    .summary-card {
      display: flex;
      flex-direction: column;
      align-items: center;
      background: #f8f9fa;
      border-radius: 8px;
      padding: 12px 20px;
      min-width: 100px;
      border: 1px solid #e0e0e0;
    }
    .summary-card .count { font-size: 2rem; font-weight: 700; }
    .summary-card .label { font-size: 0.75rem; color: #777; text-transform: uppercase; letter-spacing: 0.05em; }
    .count-ok   { color: #27ae60; }
    .count-down { color: #e74c3c; }
    .count-total { color: #2980b9; }
    .count-unknown { color: #95a5a6; }

    main { padding: 24px; }

    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
      gap: 16px;
    }

    .card {
      background: #fff;
      border-radius: 8px;
      border: 1px solid #e0e0e0;
      overflow: hidden;
      box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    }
    .card-header {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 14px 16px;
      border-bottom: 1px solid #f0f0f0;
    }
    .status-dot {
      width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0;
    }
    .status-ok      .status-dot, .badge-ok      { background: #2ecc71; }
    .status-late    .status-dot, .badge-late    { background: #f39c12; }
    .status-down    .status-dot, .badge-down    { background: #e74c3c; }
    .status-unknown .status-dot, .badge-unknown { background: #bdc3c7; }

    .card-title { font-weight: 600; font-size: 1rem; flex: 1; }
    .card-id    { font-size: 0.75rem; color: #aaa; font-family: monospace; }

    .badge {
      font-size: 0.72rem; font-weight: 700; letter-spacing: 0.05em;
      text-transform: uppercase; padding: 3px 8px; border-radius: 10px;
      color: #fff;
    }

    .card-body { padding: 14px 16px; }
    .meta-row {
      display: flex;
      justify-content: space-between;
      font-size: 0.82rem;
      padding: 4px 0;
      border-bottom: 1px solid #f8f8f8;
    }
    .meta-row:last-child { border-bottom: none; }
    .meta-label { color: #888; }
    .meta-value { font-weight: 500; text-align: right; }

    .card-footer {
      padding: 10px 16px;
      font-size: 0.75rem;
      color: #aaa;
      background: #fafafa;
      border-top: 1px solid #f0f0f0;
    }

    .alert-badge {
      display: inline-block;
      background: #fdecea;
      color: #c0392b;
      border-radius: 4px;
      padding: 1px 6px;
      font-size: 0.72rem;
      font-weight: 600;
    }

    footer {
      text-align: center;
      padding: 20px;
      font-size: 0.78rem;
      color: #aaa;
    }

    @media (max-width: 500px) {
      .grid { grid-template-columns: 1fr; }
      header { flex-direction: column; align-items: flex-start; }
    }
  </style>
</head>
<body>

<header>
  <div class="pulse"></div>
  <div>
    <h1>Heartbeat Monitor</h1>
    <div class="subtitle">Last updated: <?= date('Y-m-d H:i:s T') ?> &bull; auto-refresh every 60s</div>
  </div>
</header>

<div class="summary-bar">
  <div class="summary-card">
    <span class="count count-total"><?= count($rows) ?></span>
    <span class="label">Total</span>
  </div>
  <div class="summary-card">
    <span class="count count-ok"><?= $totalOk ?></span>
    <span class="label">OK</span>
  </div>
  <div class="summary-card">
    <span class="count count-down"><?= $totalDown ?></span>
    <span class="label">Problem</span>
  </div>
  <div class="summary-card">
    <span class="count count-unknown"><?= count(array_filter($rows, fn($r) => $r['status'] === 'unknown')) ?></span>
    <span class="label">Unknown</span>
  </div>
</div>

<main>
  <div class="grid">
    <?php foreach ($rows as $row): ?>
    <div class="card status-<?= h($row['status']) ?>">
      <div class="card-header">
        <span class="status-dot"></span>
        <div style="flex:1;min-width:0">
          <div class="card-title"><?= h($row['name']) ?></div>
          <div class="card-id"><?= h($row['id']) ?></div>
        </div>
        <span class="badge badge-<?= h($row['status']) ?>"><?= h($row['statusLabel']) ?></span>
      </div>
      <div class="card-body">
        <div class="meta-row">
          <span class="meta-label">Last heartbeat</span>
          <span class="meta-value"><?= h($row['lastSeen']) ?></span>
        </div>
        <div class="meta-row">
          <span class="meta-label">Age</span>
          <span class="meta-value"><?= h($row['age']) ?></span>
        </div>
        <div class="meta-row">
          <span class="meta-label">Expected every</span>
          <span class="meta-value"><?= h($row['interval']) ?></span>
        </div>
        <div class="meta-row">
          <span class="meta-label">Next deadline</span>
          <span class="meta-value"><?= h($row['nextDue']) ?></span>
        </div>
        <div class="meta-row">
          <span class="meta-label">IP / Hostname</span>
          <span class="meta-value"><?= h($row['ip']) ?> / <?= h($row['hostname']) ?></span>
        </div>
        <?php if ($row['misses'] > 0): ?>
        <div class="meta-row">
          <span class="meta-label">Consecutive misses</span>
          <span class="meta-value"><span class="alert-badge"><?= (int) $row['misses'] ?></span></span>
        </div>
        <?php endif; ?>
        <?php if ($row['alerts'] > 0): ?>
        <div class="meta-row">
          <span class="meta-label">Alerts sent</span>
          <span class="meta-value"><?= (int) $row['alerts'] ?> (last: <?= h($row['lastAlert']) ?>)</span>
        </div>
        <?php endif; ?>
      </div>
      <div class="card-footer">
        ID: <?= h($row['id']) ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</main>

<footer>Heartbeat Monitor &bull; <?= date('Y') ?></footer>

</body>
</html>
