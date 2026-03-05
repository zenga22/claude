<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';

$pdo    = db_connect();
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// ── Helper ──────────────────────────────────────────────────────────────────
function flash(string $msg, string $type = 'success'): void {
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function redirect(string $url): never {
    header("Location: $url");
    exit;
}

// ── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'save') {
        $name      = trim($_POST['name'] ?? '');
        $type      = $_POST['type'] ?? 'http';
        $host      = trim($_POST['host'] ?? '');
        $port      = (int)($_POST['port'] ?? 0) ?: null;
        $path      = trim($_POST['path'] ?? '/') ?: '/';
        $interval  = max(10, (int)($_POST['interval'] ?? 60));
        $threshold = max(1,  (int)($_POST['threshold'] ?? 3));
        $timeout   = max(1,  min(60, (int)($_POST['timeout'] ?? 10)));
        $enabled   = isset($_POST['enabled']) ? 1 : 0;
        $notes     = trim($_POST['notes'] ?? '');
        $editId    = (int)($_POST['id'] ?? 0);

        if (!$name || !$host) {
            flash('Name and host are required.', 'danger');
            redirect('services.php?action=' . ($editId ? 'edit&id=' . $editId : 'add'));
        }

        if ($editId) {
            $pdo->prepare("UPDATE services SET name=?,type=?,host=?,port=?,path=?,interval=?,threshold=?,timeout=?,enabled=?,notes=? WHERE id=?")
                ->execute([$name,$type,$host,$port,$path,$interval,$threshold,$timeout,$enabled,$notes,$editId]);
            flash("Service '{$name}' updated.");
        } else {
            $pdo->prepare("INSERT INTO services (name,type,host,port,path,interval,threshold,timeout,enabled,notes) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$name,$type,$host,$port,$path,$interval,$threshold,$timeout,$enabled,$notes]);
            flash("Service '{$name}' added.");
        }
        redirect('services.php');
    }

    if ($act === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        $row   = $pdo->prepare("SELECT name FROM services WHERE id=?");
        $row->execute([$delId]);
        $svc   = $row->fetch();
        $pdo->prepare("DELETE FROM services WHERE id=?")->execute([$delId]);
        flash("Service '{$svc['name']}' deleted.", 'warning');
        redirect('services.php');
    }

    if ($act === 'toggle') {
        $togId = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE services SET enabled = 1 - enabled WHERE id=?")->execute([$togId]);
        redirect('services.php');
    }
}

// ── GET: list / add / edit ───────────────────────────────────────────────────
$service = null;
if (in_array($action, ['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM services WHERE id=?");
    $stmt->execute([$id]);
    $service = $stmt->fetch();
    if (!$service) redirect('services.php');
}

$services = $pdo->query("SELECT * FROM services ORDER BY name ASC")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0"><i class="bi bi-hdd-network"></i> Services</h4>
  <?php if ($action === 'list'): ?>
  <a href="services.php?action=add" class="btn btn-primary">
    <i class="bi bi-plus-lg"></i> Add Service
  </a>
  <?php endif; ?>
</div>

<?php if ($action === 'add' || $action === 'edit'): ?>
<!-- ── Add / Edit Form ──────────────────────────────────────────────────── -->
<div class="card shadow-sm mb-4">
  <div class="card-header bg-white fw-semibold">
    <?= $action === 'edit' ? 'Edit Service' : 'Add New Service' ?>
  </div>
  <div class="card-body">
    <form method="POST" action="services.php">
      <input type="hidden" name="action" value="save">
      <?php if ($service): ?>
      <input type="hidden" name="id" value="<?= $service['id'] ?>">
      <?php endif; ?>

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control" required maxlength="100"
                 value="<?= htmlspecialchars($service['name'] ?? '') ?>" placeholder="My Website">
        </div>

        <div class="col-md-6">
          <label class="form-label fw-semibold">Check Type <span class="text-danger">*</span></label>
          <select name="type" class="form-select" id="typeSelect">
            <?php foreach (['http' => 'HTTP/HTTPS', 'tcp' => 'TCP Port', 'ping' => 'ICMP Ping'] as $v => $l): ?>
            <option value="<?= $v ?>" <?= ($service['type'] ?? 'http') === $v ? 'selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label fw-semibold">Host / IP <span class="text-danger">*</span></label>
          <input type="text" name="host" class="form-control" required
                 value="<?= htmlspecialchars($service['host'] ?? '') ?>" placeholder="example.com or 192.168.1.1">
        </div>

        <div class="col-md-3" id="portField">
          <label class="form-label fw-semibold">Port</label>
          <input type="number" name="port" class="form-control" min="1" max="65535"
                 value="<?= $service['port'] ?? '' ?>" placeholder="80">
          <div class="form-text">Leave blank for default (HTTP:80, HTTPS:443)</div>
        </div>

        <div class="col-md-3" id="pathField">
          <label class="form-label fw-semibold">Path</label>
          <input type="text" name="path" class="form-control"
                 value="<?= htmlspecialchars($service['path'] ?? '/') ?>" placeholder="/">
        </div>

        <div class="col-md-4">
          <label class="form-label fw-semibold">Check Interval (seconds)</label>
          <input type="number" name="interval" class="form-control" min="10" max="86400"
                 value="<?= $service['interval'] ?? 60 ?>">
          <div class="form-text">Minimum 10 s. Recommend 60 s.</div>
        </div>

        <div class="col-md-4">
          <label class="form-label fw-semibold">Alert Threshold (failures)</label>
          <input type="number" name="threshold" class="form-control" min="1" max="100"
                 value="<?= $service['threshold'] ?? 3 ?>">
          <div class="form-text">Consecutive failures before sending an alert.</div>
        </div>

        <div class="col-md-4">
          <label class="form-label fw-semibold">Timeout (seconds)</label>
          <input type="number" name="timeout" class="form-control" min="1" max="60"
                 value="<?= $service['timeout'] ?? 10 ?>">
        </div>

        <div class="col-12">
          <label class="form-label fw-semibold">Notes</label>
          <textarea name="notes" class="form-control" rows="2"><?= htmlspecialchars($service['notes'] ?? '') ?></textarea>
        </div>

        <div class="col-12">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="enabled" id="enabled"
                   <?= ($service['enabled'] ?? 1) ? 'checked' : '' ?>>
            <label class="form-check-label" for="enabled">Enabled</label>
          </div>
        </div>

        <div class="col-12 d-flex gap-2">
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-save"></i> Save
          </button>
          <a href="services.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
document.getElementById('typeSelect').addEventListener('change', function() {
    const isHttp = this.value === 'http';
    const isTcp  = this.value === 'tcp';
    document.getElementById('portField').style.display = (isHttp || isTcp) ? '' : 'none';
    document.getElementById('pathField').style.display = isHttp ? '' : 'none';
});
document.getElementById('typeSelect').dispatchEvent(new Event('change'));
</script>

<?php else: ?>
<!-- ── Service List ──────────────────────────────────────────────────────── -->
<?php if (empty($services)): ?>
  <div class="card shadow-sm">
    <div class="card-body text-center py-5 text-muted">
      <i class="bi bi-hdd-network display-4"></i>
      <p class="mt-2">No services yet. <a href="services.php?action=add">Add one</a>.</p>
    </div>
  </div>
<?php else: ?>
  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Status</th>
            <th>Name</th>
            <th>Type</th>
            <th>Host</th>
            <th>Interval</th>
            <th>Threshold</th>
            <th>24h Uptime</th>
            <th>Last Checked</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($services as $s):
          $status = $s['last_status'] ?? 'unknown';
          $uptime = uptime_percent((int)$s['id'], 24);
        ?>
          <tr class="<?= !$s['enabled'] ? 'table-secondary' : '' ?>">
            <td>
              <?php if ($status === 'up'): ?>
                <span class="badge bg-success">UP</span>
              <?php elseif ($status === 'down'): ?>
                <span class="badge bg-danger">DOWN</span>
              <?php else: ?>
                <span class="badge bg-secondary">?</span>
              <?php endif; ?>
            </td>
            <td class="fw-semibold"><?= htmlspecialchars($s['name']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= strtoupper($s['type']) ?></span></td>
            <td class="text-muted small"><?= htmlspecialchars($s['host']) ?><?= $s['port'] ? ':' . $s['port'] : '' ?></td>
            <td class="text-muted small"><?= $s['interval'] ?>s</td>
            <td class="text-muted small"><?= $s['threshold'] ?> fails</td>
            <td>
              <span class="<?= $uptime >= 99 ? 'status-up' : ($uptime >= 90 ? 'text-warning' : 'status-down') ?>">
                <?= $uptime ?>%
              </span>
            </td>
            <td class="text-muted small">
              <?= $s['last_check'] ? date('Y-m-d H:i', $s['last_check']) : 'Never' ?>
            </td>
            <td>
              <div class="btn-group btn-group-sm">
                <a href="services.php?action=edit&id=<?= $s['id'] ?>" class="btn btn-outline-primary" title="Edit">
                  <i class="bi bi-pencil"></i>
                </a>
                <a href="api.php?action=check&id=<?= $s['id'] ?>" class="btn btn-outline-success" title="Check now">
                  <i class="bi bi-play-fill"></i>
                </a>
                <a href="history.php?service_id=<?= $s['id'] ?>" class="btn btn-outline-secondary" title="History">
                  <i class="bi bi-clock-history"></i>
                </a>
                <!-- Toggle enable -->
                <form method="POST" class="d-inline">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= $s['id'] ?>">
                  <button type="submit" class="btn btn-outline-<?= $s['enabled'] ? 'warning' : 'success' ?>" title="<?= $s['enabled'] ? 'Disable' : 'Enable' ?>">
                    <i class="bi bi-<?= $s['enabled'] ? 'pause-fill' : 'play-fill' ?>"></i>
                  </button>
                </form>
                <!-- Delete -->
                <button type="button" class="btn btn-outline-danger" title="Delete"
                        onclick="if(confirm('Delete service &quot;<?= htmlspecialchars(addslashes($s['name'])) ?>&quot;?')) this.closest('form.del').submit()">
                  <i class="bi bi-trash"></i>
                </button>
              </div>
              <form class="del d-none" method="POST">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $s['id'] ?>">
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
