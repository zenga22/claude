<?php
/**
 * Apache 2.4 Error Log Parser
 * PHP 7.4
 *
 * Parses /var/log/apache2/error.log and /var/log/apache2/error.log.1
 * and displays:
 *   - Full list of error messages
 *   - Summary by Client IP
 *   - Summary by Date/Time (hourly buckets)
 *   - Summary by Log Level
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const LOG_FILES = [
    '/var/log/apache2/error.log.1',
    '/var/log/apache2/error.log',
];

// Apache 2.4 error log line format:
// [DayName Mon DD HH:MM:SS.usec YYYY] [module:level] [pid N] [client IP:port] message
// The module part may be absent (legacy lines): [level]
const LOG_PATTERN = '/^\[(?P<datetime>[^\]]+)\]\s+\[(?:(?P<module>[^:\]]+):)?(?P<level>[^\]]+)\]\s+(?:\[pid\s+(?P<pid>\d+)\]\s+)?(?:\[client\s+(?P<client>[^\]]+)\]\s+)?(?P<message>.+)$/';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Parse a single log line into an associative array or null if unparseable.
 *
 * @return array<string,string>|null
 */
function parse_line(string $line): ?array
{
    $line = rtrim($line);
    if ($line === '') {
        return null;
    }

    if (!preg_match(LOG_PATTERN, $line, $m)) {
        // Return a minimal record so we don't silently swallow lines.
        return [
            'raw_datetime' => '',
            'datetime_obj' => null,
            'date'         => 'Unknown',
            'hour_bucket'  => 'Unknown',
            'level'        => 'unknown',
            'module'       => '',
            'pid'          => '',
            'client_full'  => '',
            'client_ip'    => 'Unknown',
            'message'      => $line,
        ];
    }

    $rawDt  = $m['datetime'] ?? '';
    $dt     = parse_apache_datetime($rawDt);
    $date   = $dt ? $dt->format('Y-m-d') : 'Unknown';
    $hourBucket = $dt ? $dt->format('Y-m-d H:00') : 'Unknown';

    // Strip port from client address (IPv4 and IPv6 bracket notation)
    $clientFull = trim($m['client'] ?? '');
    $clientIp   = extract_ip($clientFull);

    return [
        'raw_datetime' => $rawDt,
        'datetime_obj' => $dt,
        'date'         => $date,
        'hour_bucket'  => $hourBucket,
        'level'        => strtolower(trim($m['level'] ?? 'unknown')),
        'module'       => trim($m['module'] ?? ''),
        'pid'          => trim($m['pid'] ?? ''),
        'client_full'  => $clientFull,
        'client_ip'    => $clientIp !== '' ? $clientIp : 'Unknown',
        'message'      => trim($m['message'] ?? ''),
    ];
}

/**
 * Parse Apache datetime string like "Mon Jan 01 12:00:00.123456 2024"
 * or ISO-like "2024-01-01 12:00:00" (some custom formats).
 */
function parse_apache_datetime(string $raw): ?\DateTime
{
    if ($raw === '') {
        return null;
    }
    // Apache 2.4 default: "Mon Jan 01 12:00:00.123456 2024"
    $raw = preg_replace('/\.\d+/', '', $raw); // strip sub-seconds
    $dt  = \DateTime::createFromFormat('D M d H:i:s Y', $raw);
    if ($dt !== false) {
        return $dt;
    }
    // Fallback: let PHP try to parse it
    try {
        return new \DateTime($raw);
    } catch (\Exception $e) {
        return null;
    }
}

/**
 * Extract just the IP address from strings like "192.168.1.1:54321"
 * or "[::1]:54321" (IPv6).
 */
function extract_ip(string $client): string
{
    if ($client === '') {
        return '';
    }
    // IPv6 bracket notation: [::1]:port  or  [::1]
    if (preg_match('/^\[([^\]]+)\]/', $client, $m)) {
        return $m[1];
    }
    // IPv4 with port: 1.2.3.4:port
    if (preg_match('/^([\d.]+)(?::\d+)?$/', $client, $m)) {
        return $m[1];
    }
    // Return as-is (already plain IP or hostname)
    return $client;
}

/**
 * Load and parse all configured log files.
 *
 * @return array<int,array<string,mixed>>
 */
function load_logs(): array
{
    $entries = [];
    foreach (LOG_FILES as $path) {
        if (!is_readable($path)) {
            continue;
        }
        $fh = fopen($path, 'r');
        if ($fh === false) {
            continue;
        }
        while (($line = fgets($fh)) !== false) {
            $entry = parse_line($line);
            if ($entry !== null) {
                $entry['source'] = basename($path);
                $entries[] = $entry;
            }
        }
        fclose($fh);
    }

    // Sort chronologically (nulls last)
    usort($entries, static function (array $a, array $b): int {
        if ($a['datetime_obj'] === null && $b['datetime_obj'] === null) {
            return 0;
        }
        if ($a['datetime_obj'] === null) {
            return 1;
        }
        if ($b['datetime_obj'] === null) {
            return -1;
        }
        return $a['datetime_obj'] <=> $b['datetime_obj'];
    });

    return $entries;
}

/**
 * Build summary tables from parsed entries.
 *
 * @param array<int,array<string,mixed>> $entries
 * @return array{by_ip: array, by_hour: array, by_level: array}
 */
function build_summaries(array $entries): array
{
    $byIp    = [];
    $byHour  = [];
    $byLevel = [];

    foreach ($entries as $e) {
        $ip    = $e['client_ip'];
        $hour  = $e['hour_bucket'];
        $level = $e['level'];

        // By IP
        if (!isset($byIp[$ip])) {
            $byIp[$ip] = ['count' => 0, 'levels' => []];
        }
        $byIp[$ip]['count']++;
        $byIp[$ip]['levels'][$level] = ($byIp[$ip]['levels'][$level] ?? 0) + 1;

        // By hour bucket
        if (!isset($byHour[$hour])) {
            $byHour[$hour] = ['count' => 0, 'levels' => []];
        }
        $byHour[$hour]['count']++;
        $byHour[$hour]['levels'][$level] = ($byHour[$hour]['levels'][$level] ?? 0) + 1;

        // By level
        $byLevel[$level] = ($byLevel[$level] ?? 0) + 1;
    }

    arsort($byLevel);
    arsort($byIp); // will sort by array value — re-sort by count below
    uasort($byIp,  static fn($a, $b) => $b['count'] <=> $a['count']);
    ksort($byHour);

    return ['by_ip' => $byIp, 'by_hour' => $byHour, 'by_level' => $byLevel];
}

// ---------------------------------------------------------------------------
// CSS level colours
// ---------------------------------------------------------------------------

/**
 * Return a CSS class name for a log level.
 */
function level_class(string $level): string
{
    return match (true) {
        in_array($level, ['emerg', 'alert', 'crit'], true)  => 'level-crit',
        $level === 'error'                                   => 'level-error',
        $level === 'warn'                                    => 'level-warn',
        $level === 'notice'                                  => 'level-notice',
        $level === 'info'                                    => 'level-info',
        $level === 'debug'                                   => 'level-debug',
        default                                              => 'level-unknown',
    };
}

function h(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$entries    = load_logs();
$total      = count($entries);
$summaries  = build_summaries($entries);

// Pagination for the message list
$perPage    = 100;
$page       = max(1, (int)($_GET['page'] ?? 1));
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;
$pageSlice  = array_slice($entries, $offset, $perPage);

// Active tab
$tab = $_GET['tab'] ?? 'messages';
$validTabs = ['messages', 'by_ip', 'by_hour', 'by_level'];
if (!in_array($tab, $validTabs, true)) {
    $tab = 'messages';
}

function tab_url(string $tabName, int $page = 1): string
{
    $params = http_build_query(['tab' => $tabName, 'page' => $page]);
    return '?' . $params;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Apache Error Log Parser</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 13px;
    background: #f4f6f9;
    color: #333;
  }

  header {
    background: #c0392b;
    color: #fff;
    padding: 14px 24px;
  }
  header h1 { font-size: 1.4rem; font-weight: 600; }
  header p  { font-size: 0.8rem; opacity: .75; margin-top: 2px; }

  .container { max-width: 1400px; margin: 0 auto; padding: 16px 24px; }

  /* stat cards */
  .stats { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
  .stat-card {
    background: #fff;
    border-radius: 6px;
    padding: 12px 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,.1);
    min-width: 140px;
    text-align: center;
  }
  .stat-card .num { font-size: 1.8rem; font-weight: 700; color: #c0392b; }
  .stat-card .lbl { font-size: .75rem; color: #666; margin-top: 2px; }

  /* tab nav */
  .tabs { display: flex; gap: 4px; margin-bottom: 0; border-bottom: 2px solid #ddd; }
  .tab-btn {
    padding: 8px 18px;
    border: none;
    background: none;
    cursor: pointer;
    font-size: 13px;
    border-radius: 4px 4px 0 0;
    color: #555;
    text-decoration: none;
    display: inline-block;
  }
  .tab-btn:hover { background: #eee; }
  .tab-btn.active {
    background: #fff;
    border: 2px solid #ddd;
    border-bottom: 2px solid #fff;
    margin-bottom: -2px;
    color: #c0392b;
    font-weight: 600;
  }

  /* panels */
  .panel { background: #fff; border-radius: 0 6px 6px 6px; box-shadow: 0 1px 3px rgba(0,0,0,.1); padding: 16px; overflow-x: auto; }
  .panel.hidden { display: none; }

  /* tables */
  table { width: 100%; border-collapse: collapse; }
  th, td { padding: 7px 10px; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
  th { background: #f9fafb; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #555; position: sticky; top: 0; }
  tr:hover td { background: #fafafa; }

  td.message { max-width: 680px; word-break: break-word; font-family: monospace; font-size: 12px; }
  td.datetime { white-space: nowrap; font-family: monospace; font-size: 12px; }
  td.num { text-align: right; font-weight: 600; }

  /* level badges */
  .badge {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    white-space: nowrap;
  }
  .level-crit    { background: #7d0000; color: #fff; }
  .level-error   { background: #e74c3c; color: #fff; }
  .level-warn    { background: #f39c12; color: #fff; }
  .level-notice  { background: #3498db; color: #fff; }
  .level-info    { background: #27ae60; color: #fff; }
  .level-debug   { background: #95a5a6; color: #fff; }
  .level-unknown { background: #bdc3c7; color: #333; }

  /* pagination */
  .pagination { display: flex; align-items: center; gap: 6px; margin-top: 14px; flex-wrap: wrap; }
  .pagination a, .pagination span {
    padding: 5px 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    text-decoration: none;
    color: #333;
    font-size: 12px;
  }
  .pagination a:hover { background: #eee; }
  .pagination .current { background: #c0392b; color: #fff; border-color: #c0392b; font-weight: 600; }
  .pagination .disabled { color: #aaa; pointer-events: none; }

  .no-data { color: #888; font-style: italic; padding: 20px; text-align: center; }

  /* mini level bar inside summary tables */
  .level-pills { display: flex; flex-wrap: wrap; gap: 4px; }

  .source-badge {
    font-size: 10px;
    padding: 1px 5px;
    border-radius: 3px;
    background: #ecf0f1;
    color: #555;
    white-space: nowrap;
  }

  @media (max-width: 600px) {
    .stats { flex-direction: column; }
    .stat-card { min-width: unset; }
  }
</style>
</head>
<body>

<header>
  <h1>Apache Error Log Parser</h1>
  <p>Sources: <?= h(implode(', ', LOG_FILES)) ?></p>
</header>

<div class="container">

  <!-- Stat cards -->
  <div class="stats">
    <div class="stat-card">
      <div class="num"><?= number_format($total) ?></div>
      <div class="lbl">Total Entries</div>
    </div>
    <?php foreach ($summaries['by_level'] as $lvl => $cnt): ?>
    <div class="stat-card">
      <div class="num" style="font-size:1.4rem">
        <span class="badge <?= level_class($lvl) ?>"><?= h($lvl) ?></span>
        <br><?= number_format($cnt) ?>
      </div>
      <div class="lbl">entries</div>
    </div>
    <?php endforeach; ?>
    <div class="stat-card">
      <div class="num"><?= number_format(count($summaries['by_ip'])) ?></div>
      <div class="lbl">Unique Client IPs</div>
    </div>
  </div>

  <!-- Tabs -->
  <nav class="tabs">
    <a href="<?= tab_url('messages') ?>" class="tab-btn <?= $tab === 'messages' ? 'active' : '' ?>">Error Messages</a>
    <a href="<?= tab_url('by_ip') ?>"   class="tab-btn <?= $tab === 'by_ip'    ? 'active' : '' ?>">By Client IP</a>
    <a href="<?= tab_url('by_hour') ?>" class="tab-btn <?= $tab === 'by_hour'  ? 'active' : '' ?>">By Date/Time</a>
    <a href="<?= tab_url('by_level') ?>" class="tab-btn <?= $tab === 'by_level' ? 'active' : '' ?>">By Log Level</a>
  </nav>

  <!-- ================================================================
       TAB: Error Messages
  ================================================================ -->
  <div class="panel <?= $tab !== 'messages' ? 'hidden' : '' ?>">
    <?php if ($total === 0): ?>
      <p class="no-data">No log entries found. Check that the log files exist and are readable.</p>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Date / Time</th>
          <th>Level</th>
          <th>Client IP</th>
          <th>Module / PID</th>
          <th>Message</th>
          <th>Source</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($pageSlice as $i => $e): ?>
        <tr>
          <td><?= $offset + $i + 1 ?></td>
          <td class="datetime"><?= h($e['raw_datetime']) ?></td>
          <td><span class="badge <?= level_class($e['level']) ?>"><?= h($e['level']) ?></span></td>
          <td><?= h($e['client_ip'] !== 'Unknown' ? $e['client_ip'] : '') ?></td>
          <td>
            <?php if ($e['module'] !== ''): ?><small><?= h($e['module']) ?></small><br><?php endif; ?>
            <?php if ($e['pid']    !== ''): ?><small style="color:#999">pid <?= h($e['pid']) ?></small><?php endif; ?>
          </td>
          <td class="message"><?= h($e['message']) ?></td>
          <td><span class="source-badge"><?= h($e['source']) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
      <?php if ($page > 1): ?>
        <a href="<?= tab_url('messages', 1) ?>">&laquo; First</a>
        <a href="<?= tab_url('messages', $page - 1) ?>">&lsaquo; Prev</a>
      <?php else: ?>
        <span class="disabled">&laquo; First</span>
        <span class="disabled">&lsaquo; Prev</span>
      <?php endif; ?>

      <?php
        $window = 3;
        $start  = max(1, $page - $window);
        $end    = min($totalPages, $page + $window);
        if ($start > 1): ?><span>…</span><?php endif;
        for ($p = $start; $p <= $end; $p++):
      ?>
        <?php if ($p === $page): ?>
          <span class="current"><?= $p ?></span>
        <?php else: ?>
          <a href="<?= tab_url('messages', $p) ?>"><?= $p ?></a>
        <?php endif; ?>
      <?php endfor;
        if ($end < $totalPages): ?><span>…</span><?php endif; ?>

      <?php if ($page < $totalPages): ?>
        <a href="<?= tab_url('messages', $page + 1) ?>">Next &rsaquo;</a>
        <a href="<?= tab_url('messages', $totalPages) ?>">Last &raquo;</a>
      <?php else: ?>
        <span class="disabled">Next &rsaquo;</span>
        <span class="disabled">Last &raquo;</span>
      <?php endif; ?>

      <span style="color:#888; font-size:11px;">
        Page <?= $page ?> of <?= $totalPages ?> &nbsp;&mdash;&nbsp; <?= number_format($total) ?> entries
      </span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- ================================================================
       TAB: By Client IP
  ================================================================ -->
  <div class="panel <?= $tab !== 'by_ip' ? 'hidden' : '' ?>">
    <?php if (empty($summaries['by_ip'])): ?>
      <p class="no-data">No data.</p>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Client IP</th>
          <th class="num">Total Entries</th>
          <th>Level Breakdown</th>
        </tr>
      </thead>
      <tbody>
        <?php $rank = 1; foreach ($summaries['by_ip'] as $ip => $info): ?>
        <tr>
          <td><?= $rank++ ?></td>
          <td><code><?= h($ip) ?></code></td>
          <td class="num"><?= number_format($info['count']) ?></td>
          <td>
            <div class="level-pills">
            <?php foreach ($info['levels'] as $lvl => $cnt): ?>
              <span class="badge <?= level_class($lvl) ?>"><?= h($lvl) ?>: <?= number_format($cnt) ?></span>
            <?php endforeach; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- ================================================================
       TAB: By Date/Time (hourly)
  ================================================================ -->
  <div class="panel <?= $tab !== 'by_hour' ? 'hidden' : '' ?>">
    <?php if (empty($summaries['by_hour'])): ?>
      <p class="no-data">No data.</p>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Hour (UTC)</th>
          <th class="num">Total Entries</th>
          <th>Level Breakdown</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($summaries['by_hour'] as $hour => $info): ?>
        <tr>
          <td class="datetime"><?= h($hour) ?></td>
          <td class="num"><?= number_format($info['count']) ?></td>
          <td>
            <div class="level-pills">
            <?php foreach ($info['levels'] as $lvl => $cnt): ?>
              <span class="badge <?= level_class($lvl) ?>"><?= h($lvl) ?>: <?= number_format($cnt) ?></span>
            <?php endforeach; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- ================================================================
       TAB: By Log Level
  ================================================================ -->
  <div class="panel <?= $tab !== 'by_level' ? 'hidden' : '' ?>">
    <?php if (empty($summaries['by_level'])): ?>
      <p class="no-data">No data.</p>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Log Level</th>
          <th class="num">Count</th>
          <th>Share</th>
          <th>Visual</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($summaries['by_level'] as $lvl => $cnt): ?>
        <?php $pct = $total > 0 ? round($cnt / $total * 100, 1) : 0; ?>
        <tr>
          <td><span class="badge <?= level_class($lvl) ?>"><?= h($lvl) ?></span></td>
          <td class="num"><?= number_format($cnt) ?></td>
          <td><?= $pct ?>%</td>
          <td>
            <div style="background:#eee; border-radius:3px; height:14px; width:200px; overflow:hidden;">
              <div style="height:100%; width:<?= min(100, $pct) ?>%; background: var(--bar-color, #c0392b);
                          <?php
                            $colors = [
                              'crit'    => '#7d0000', 'emerg' => '#7d0000', 'alert' => '#7d0000',
                              'error'   => '#e74c3c', 'warn'  => '#f39c12',
                              'notice'  => '#3498db', 'info'  => '#27ae60',
                              'debug'   => '#95a5a6',
                            ];
                            $bar = $colors[$lvl] ?? '#bdc3c7';
                          ?>
                          background: <?= $bar ?>;">
              </div>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

</div><!-- /container -->
</body>
</html>
