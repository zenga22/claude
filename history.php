<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';

$pdo       = db_connect();
$serviceId = (int)($_GET['service_id'] ?? 0);
$page      = max(1, (int)($_GET['p'] ?? 1));
$perPage   = 100;
$offset    = ($page - 1) * $perPage;
$tab       = $_GET['tab'] ?? 'checks';

// Service list for filter dropdown
$services = $pdo->query("SELECT id, name FROM services ORDER BY name")->fetchAll();

// Checks query
$where  = $serviceId ? "WHERE c.service_id = {$serviceId}" : '';
$total  = (int) $pdo->query("SELECT COUNT(*) FROM checks c {$where}")->fetchColumn();
$pages  = max(1, (int) ceil($total / $perPage));

$checks = $pdo->query("SELECT c.*, s.name as service_name, s.type, s.host
                        FROM checks c JOIN services s ON c.service_id=s.id
                        {$where}
                        ORDER BY c.checked_at DESC
                        LIMIT {$perPage} OFFSET {$offset}")->fetchAll();

// Alerts query
$aWhere  = $serviceId ? "WHERE a.service_id = {$serviceId}" : '';
$alerts  = $pdo->query("SELECT a.*, s.name as service_name
                         FROM alerts a JOIN services s ON a.service_id=s.id
                         {$aWhere}
                         ORDER BY a.sent_at DESC LIMIT 200")->fetchAll();

// Chart data: per-service uptime for last 7 days (grouped by day)
$chartData = [];
if ($serviceId) {
    $rows = $pdo->query("
        SELECT date(checked_at,'unixepoch') as day,
               COUNT(*) as total,
               SUM(CASE WHEN status='up' THEN 1 ELSE 0 END) as up_count
        FROM checks
        WHERE service_id={$serviceId}
          AND checked_at >= strftime('%s','now','-7 days')
        GROUP BY day
        ORDER BY day ASC
    ")->fetchAll();
    foreach ($rows as $r) {
        $chartData['labels'][]   = $r['day'];
        $chartData['uptime'][]   = $r['total'] > 0 ? round(($r['up_count']/$r['total'])*100,1) : 0;
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-clock-history"></i> History</h4>

  <!-- Filter -->
  <form method="GET" class="d-flex gap-2 align-items-center">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
    <select name="service_id" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">All Services</option>
      <?php foreach ($services as $s): ?>
      <option value="<?= $s['id'] ?>" <?= $serviceId === (int)$s['id'] ? 'selected' : '' ?>>
        <?= htmlspecialchars($s['name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
    <a href="history.php" class="btn btn-sm btn-outline-secondary">Clear</a>
  </form>
</div>

<?php if ($serviceId && !empty($chartData)): ?>
<!-- Uptime Chart -->
<div class="card shadow-sm mb-4">
  <div class="card-header bg-white fw-semibold">7-Day Uptime % — <?= htmlspecialchars($services[array_search($serviceId, array_column($services,'id'))]['name'] ?? '') ?></div>
  <div class="card-body" style="height:180px">
    <canvas id="uptimeChart"></canvas>
  </div>
</div>
<script>
new Chart(document.getElementById('uptimeChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($chartData['labels'] ?? []) ?>,
        datasets: [{
            label: 'Uptime %',
            data: <?= json_encode($chartData['uptime'] ?? []) ?>,
            backgroundColor: ctx => ctx.raw >= 99 ? '#198754' : ctx.raw >= 90 ? '#fd7e14' : '#dc3545',
            borderRadius: 4,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: { y: { min: 0, max: 100 } },
        plugins: { legend: { display: false } }
    }
});
</script>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3" id="historyTabs">
  <li class="nav-item">
    <a class="nav-link <?= $tab !== 'alerts' ? 'active' : '' ?>"
       href="history.php?<?= $serviceId ? 'service_id='.$serviceId.'&' : '' ?>tab=checks">
      <i class="bi bi-list-check"></i> Check Log
      <span class="badge bg-secondary ms-1"><?= $total ?></span>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'alerts' ? 'active' : '' ?>"
       href="history.php?<?= $serviceId ? 'service_id='.$serviceId.'&' : '' ?>tab=alerts#alerts" id="alerts-link">
      <i class="bi bi-bell"></i> Alerts
      <span class="badge bg-secondary ms-1"><?= count($alerts) ?></span>
    </a>
  </li>
</ul>

<?php if ($tab !== 'alerts'): ?>
<!-- ── Check Log ──────────────────────────────────────────────────────────── -->
<div class="card shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Time</th>
          <?php if (!$serviceId): ?><th>Service</th><?php endif; ?>
          <th>Type</th>
          <th>Status</th>
          <th>Response Time</th>
          <th>Error</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($checks)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No checks recorded yet.</td></tr>
      <?php else: ?>
        <?php foreach ($checks as $c):
          $isUp = $c['status'] === 'up';
          $rt   = $c['response_time'];
          $rtClass = $rt === null ? '' : ($rt < 200 ? 'rt-good' : ($rt < 1000 ? 'rt-ok' : 'rt-slow'));
        ?>
        <tr class="status-<?= $c['status'] ?>">
          <td class="text-muted small"><?= date('Y-m-d H:i:s', $c['checked_at']) ?></td>
          <?php if (!$serviceId): ?>
          <td>
            <a href="history.php?service_id=<?= $c['service_id'] ?>" class="text-decoration-none">
              <?= htmlspecialchars($c['service_name']) ?>
            </a>
          </td>
          <?php endif; ?>
          <td><span class="badge bg-light text-dark border"><?= strtoupper($c['type']) ?></span></td>
          <td>
            <?php if ($isUp): ?>
              <span class="badge bg-success">UP</span>
            <?php else: ?>
              <span class="badge bg-danger">DOWN</span>
            <?php endif; ?>
          </td>
          <td class="<?= $rtClass ?>">
            <?= $rt !== null ? $rt . ' ms' : '—' ?>
          </td>
          <td class="text-danger small"><?= $c['error'] ? htmlspecialchars($c['error']) : '' ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<nav class="mt-3">
  <ul class="pagination pagination-sm justify-content-center">
    <?php for ($i = 1; $i <= min($pages, 20); $i++): ?>
    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
      <a class="page-link" href="?<?= $serviceId ? 'service_id='.$serviceId.'&' : '' ?>p=<?= $i ?>">
        <?= $i ?>
      </a>
    </li>
    <?php endfor; ?>
    <?php if ($pages > 20): ?>
    <li class="page-item disabled"><span class="page-link">…</span></li>
    <?php endif; ?>
  </ul>
</nav>
<?php endif; ?>

<?php else: ?>
<!-- ── Alerts Log ─────────────────────────────────────────────────────────── -->
<div class="card shadow-sm" id="alerts">
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Time</th>
          <?php if (!$serviceId): ?><th>Service</th><?php endif; ?>
          <th>Type</th>
          <th>Message</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($alerts)): ?>
        <tr><td colspan="4" class="text-center text-muted py-4">No alerts recorded.</td></tr>
      <?php else: ?>
        <?php foreach ($alerts as $a): ?>
        <tr>
          <td class="text-muted small"><?= date('Y-m-d H:i:s', $a['sent_at']) ?></td>
          <?php if (!$serviceId): ?>
          <td><?= htmlspecialchars($a['service_name']) ?></td>
          <?php endif; ?>
          <td>
            <?php if ($a['type'] === 'down'): ?>
              <span class="badge bg-danger"><i class="bi bi-arrow-down-circle"></i> DOWN</span>
            <?php else: ?>
              <span class="badge bg-success"><i class="bi bi-arrow-up-circle"></i> RECOVERY</span>
            <?php endif; ?>
          </td>
          <td class="text-muted small"><?= $a['message'] ? htmlspecialchars($a['message']) : '' ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
