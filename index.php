<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';

$pdo = db_connect();

// Summary counts
$total   = (int) $pdo->query("SELECT COUNT(*) FROM services WHERE enabled=1")->fetchColumn();
$up      = (int) $pdo->query("SELECT COUNT(*) FROM services WHERE enabled=1 AND last_status='up'")->fetchColumn();
$down    = (int) $pdo->query("SELECT COUNT(*) FROM services WHERE enabled=1 AND last_status='down'")->fetchColumn();
$unknown = $total - $up - $down;

// All services with last check info
$services = $pdo->query("SELECT * FROM services ORDER BY last_status='down' DESC, name ASC")->fetchAll();

// Recent alerts (last 10)
$alerts = $pdo->query("SELECT a.*, s.name as service_name
                        FROM alerts a JOIN services s ON a.service_id=s.id
                        ORDER BY a.sent_at DESC LIMIT 10")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="row g-3 mb-4">
  <!-- Summary cards -->
  <div class="col-6 col-md-3">
    <div class="card text-center shadow-sm">
      <div class="card-body">
        <div class="display-6 fw-bold"><?= $total ?></div>
        <div class="text-muted small">Total Services</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center shadow-sm border-success">
      <div class="card-body">
        <div class="display-6 fw-bold text-success"><?= $up ?></div>
        <div class="text-muted small"><i class="bi bi-check-circle-fill text-success"></i> Up</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center shadow-sm border-danger">
      <div class="card-body">
        <div class="display-6 fw-bold text-danger"><?= $down ?></div>
        <div class="text-muted small"><i class="bi bi-x-circle-fill text-danger"></i> Down</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card text-center shadow-sm">
      <div class="card-body">
        <div class="display-6 fw-bold text-secondary"><?= $unknown ?></div>
        <div class="text-muted small"><i class="bi bi-question-circle text-secondary"></i> Unknown</div>
      </div>
    </div>
  </div>
</div>

<?php if ($down > 0): ?>
<div class="alert alert-danger d-flex align-items-center mb-4" role="alert">
  <i class="bi bi-exclamation-triangle-fill me-2 fs-5 pulse"></i>
  <strong><?= $down ?> service<?= $down > 1 ? 's are' : ' is' ?> currently DOWN.</strong>
</div>
<?php endif; ?>

<div class="row g-4">
  <!-- Service grid -->
  <div class="col-lg-8">
    <h5 class="mb-3"><i class="bi bi-hdd-network"></i> Services</h5>
    <?php if (empty($services)): ?>
      <div class="card shadow-sm">
        <div class="card-body text-center text-muted py-5">
          <i class="bi bi-hdd-network display-4"></i>
          <p class="mt-2">No services configured yet.</p>
          <a href="services.php?action=add" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> Add your first service
          </a>
        </div>
      </div>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach ($services as $s):
          $status   = $s['last_status'] ?? 'unknown';
          $uptime   = uptime_percent((int)$s['id'], 24);
          $avgRt    = avg_response_time((int)$s['id'], 24);
          $lastSeen = $s['last_check'] ? date('H:i:s', $s['last_check']) : 'Never';
          $icon     = match($s['type']) { 'http'=>'bi-globe', 'tcp'=>'bi-plug', 'ping'=>'bi-reception-4', default=>'bi-hdd' };
          $rtClass  = $avgRt === null ? '' : ($avgRt < 200 ? 'rt-good' : ($avgRt < 1000 ? 'rt-ok' : 'rt-slow'));
        ?>
        <div class="col-sm-6 col-xl-4">
          <div class="card shadow-sm service-card <?= $status ?>">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start mb-1">
                <h6 class="card-title mb-0 text-truncate">
                  <i class="bi <?= $icon ?>"></i>
                  <?= htmlspecialchars($s['name']) ?>
                </h6>
                <?php if ($status === 'up'): ?>
                  <span class="badge bg-success">UP</span>
                <?php elseif ($status === 'down'): ?>
                  <span class="badge bg-danger">DOWN</span>
                <?php else: ?>
                  <span class="badge bg-secondary">?</span>
                <?php endif; ?>
              </div>
              <div class="text-muted small text-truncate mb-2"><?= htmlspecialchars($s['host']) ?><?= $s['port'] ? ':' . $s['port'] : '' ?></div>

              <!-- Uptime bar -->
              <div class="d-flex justify-content-between small mb-1">
                <span>24h uptime</span>
                <span class="<?= $uptime >= 99 ? 'status-up' : ($uptime >= 90 ? 'text-warning' : 'status-down') ?>"><?= $uptime ?>%</span>
              </div>
              <div class="bg-secondary bg-opacity-25 rounded mb-2" style="height:6px">
                <div class="<?= $uptime >= 90 ? 'uptime-bar' : 'uptime-bar low' ?>" style="width:<?= min(100,$uptime) ?>%"></div>
              </div>

              <div class="d-flex justify-content-between small text-muted">
                <span><?= $avgRt !== null ? '<span class="'.$rtClass.'">'.$avgRt.'ms avg</span>' : '<span>—</span>' ?></span>
                <span>Checked <?= $lastSeen ?></span>
              </div>
            </div>
            <div class="card-footer bg-transparent border-top-0 py-2 d-flex gap-2">
              <a href="history.php?service_id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-secondary flex-fill">
                <i class="bi bi-clock-history"></i> History
              </a>
              <a href="services.php?action=edit&id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary flex-fill">
                <i class="bi bi-pencil"></i> Edit
              </a>
              <a href="api.php?action=check&id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-success flex-fill" title="Check now">
                <i class="bi bi-play-fill"></i>
              </a>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Recent alerts sidebar -->
  <div class="col-lg-4">
    <h5 class="mb-3"><i class="bi bi-bell"></i> Recent Alerts</h5>
    <?php if (empty($alerts)): ?>
      <div class="card shadow-sm">
        <div class="card-body text-center text-muted py-4">
          <i class="bi bi-bell-slash display-5"></i>
          <p class="mt-2 mb-0">No alerts yet.</p>
        </div>
      </div>
    <?php else: ?>
      <div class="list-group shadow-sm">
        <?php foreach ($alerts as $a):
          $isDown = $a['type'] === 'down';
        ?>
        <div class="list-group-item list-group-item-action px-3 py-2">
          <div class="d-flex w-100 justify-content-between">
            <span class="<?= $isDown ? 'text-danger' : 'text-success' ?> fw-semibold small">
              <i class="bi bi-<?= $isDown ? 'arrow-down-circle-fill' : 'arrow-up-circle-fill' ?>"></i>
              <?= htmlspecialchars($a['service_name']) ?>
            </span>
            <small class="text-muted"><?= date('m/d H:i', $a['sent_at']) ?></small>
          </div>
          <?php if ($a['message']): ?>
          <small class="text-muted text-truncate d-block"><?= htmlspecialchars($a['message']) ?></small>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="text-end mt-2">
        <a href="history.php#alerts" class="text-muted small">View all &rarr;</a>
      </div>
    <?php endif; ?>

    <!-- Quick add -->
    <div class="card shadow-sm mt-4">
      <div class="card-body text-center">
        <a href="services.php?action=add" class="btn btn-primary w-100">
          <i class="bi bi-plus-circle"></i> Add Service
        </a>
        <a href="api.php?action=check_all" class="btn btn-outline-secondary w-100 mt-2">
          <i class="bi bi-arrow-clockwise"></i> Run All Checks Now
        </a>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
