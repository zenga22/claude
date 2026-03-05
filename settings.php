<?php
session_start();
require_once __DIR__ . '/db.php';

$pdo = db_connect();

function flash(string $msg, string $type = 'success'): void {
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

// ── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'save_settings') {
        $fields = ['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from','smtp_from_name','smtp_secure','retain_days','site_name','timezone'];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                setting_set($f, trim($_POST[$f]));
            }
        }
        flash('Settings saved.');
    }

    if ($act === 'add_recipient') {
        $email = trim($_POST['email'] ?? '');
        $name  = trim($_POST['rname'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Invalid email address.', 'danger');
        } else {
            try {
                $pdo->prepare("INSERT INTO recipients (email, name) VALUES (?, ?)")->execute([$email, $name]);
                flash("Recipient {$email} added.");
            } catch (PDOException $e) {
                flash('Email already exists.', 'warning');
            }
        }
    }

    if ($act === 'delete_recipient') {
        $rid = (int)($_POST['rid'] ?? 0);
        $pdo->prepare("DELETE FROM recipients WHERE id=?")->execute([$rid]);
        flash('Recipient removed.', 'warning');
    }

    if ($act === 'toggle_recipient') {
        $rid = (int)($_POST['rid'] ?? 0);
        $pdo->prepare("UPDATE recipients SET active = 1 - active WHERE id=?")->execute([$rid]);
    }

    if ($act === 'test_smtp') {
        require_once __DIR__ . '/includes/mailer.php';
        $testEmail = trim($_POST['test_email'] ?? '');
        if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            flash('Enter a valid test email address.', 'danger');
        } else {
            $ok = send_mail($testEmail, 'Test', 'NetMon SMTP Test', '<p>SMTP is working correctly.</p>');
            flash($ok ? "Test email sent to {$testEmail}." : "Failed to send test email. Check SMTP settings.", $ok ? 'success' : 'danger');
        }
    }

    header('Location: settings.php');
    exit;
}

$recipients = $pdo->query("SELECT * FROM recipients ORDER BY email")->fetchAll();
include __DIR__ . '/includes/header.php';
?>

<h4 class="mb-4"><i class="bi bi-gear"></i> Settings</h4>

<!-- General Settings -->
<div class="settings-section">
  <h5 class="mb-3">General</h5>
  <form method="POST">
    <input type="hidden" name="action" value="save_settings">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Site Name</label>
        <input type="text" name="site_name" class="form-control" value="<?= htmlspecialchars(setting('site_name','Network Monitor')) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Timezone</label>
        <select name="timezone" class="form-select">
          <?php
          $currentTz = setting('timezone', 'UTC');
          foreach (DateTimeZone::listIdentifiers() as $tz):
          ?>
          <option value="<?= $tz ?>" <?= $currentTz === $tz ? 'selected' : '' ?>><?= $tz ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Current time: <?= date('Y-m-d H:i:s') ?></div>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Retain History (days)</label>
        <input type="number" name="retain_days" class="form-control" min="1" max="365" value="<?= (int)setting('retain_days','30') ?>">
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save General</button>
      </div>
    </div>
  </form>
</div>

<!-- SMTP Settings -->
<div class="settings-section">
  <h5 class="mb-3"><i class="bi bi-envelope"></i> SMTP / Email</h5>
  <form method="POST">
    <input type="hidden" name="action" value="save_settings">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold">SMTP Host</label>
        <input type="text" name="smtp_host" class="form-control" value="<?= htmlspecialchars(setting('smtp_host')) ?>" placeholder="smtp.gmail.com">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Port</label>
        <input type="number" name="smtp_port" class="form-control" value="<?= (int)setting('smtp_port','587') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Encryption</label>
        <select name="smtp_secure" class="form-select">
          <?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL (465)', 'none' => 'None (plain)'] as $v => $l): ?>
          <option value="<?= $v ?>" <?= setting('smtp_secure','tls') === $v ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">SMTP Username</label>
        <input type="text" name="smtp_user" class="form-control" value="<?= htmlspecialchars(setting('smtp_user')) ?>" autocomplete="off">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">SMTP Password</label>
        <input type="password" name="smtp_pass" class="form-control" value="<?= htmlspecialchars(setting('smtp_pass')) ?>" autocomplete="new-password">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">From Email</label>
        <input type="email" name="smtp_from" class="form-control" value="<?= htmlspecialchars(setting('smtp_from')) ?>" placeholder="alerts@example.com">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">From Name</label>
        <input type="text" name="smtp_from_name" class="form-control" value="<?= htmlspecialchars(setting('smtp_from_name','NetMon')) ?>">
      </div>
      <div class="col-12 d-flex gap-2 align-items-start">
        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save SMTP</button>
      </div>
    </div>
  </form>

  <!-- Test SMTP -->
  <hr>
  <h6>Test Email</h6>
  <form method="POST" class="d-flex gap-2">
    <input type="hidden" name="action" value="test_smtp">
    <input type="email" name="test_email" class="form-control w-auto" placeholder="you@example.com" required>
    <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-send"></i> Send Test</button>
  </form>
</div>

<!-- Notification Recipients -->
<div class="settings-section">
  <h5 class="mb-3"><i class="bi bi-people"></i> Notification Recipients</h5>

  <form method="POST" class="mb-3">
    <input type="hidden" name="action" value="add_recipient">
    <div class="row g-2 align-items-end">
      <div class="col-md-5">
        <label class="form-label fw-semibold">Email Address</label>
        <input type="email" name="email" class="form-control" required placeholder="ops@example.com">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Name (optional)</label>
        <input type="text" name="rname" class="form-control" placeholder="Ops Team">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-success"><i class="bi bi-person-plus"></i> Add</button>
      </div>
    </div>
  </form>

  <?php if (empty($recipients)): ?>
    <div class="alert alert-info mb-0">No recipients configured. Add at least one email to receive alerts.</div>
  <?php else: ?>
    <table class="table table-sm table-hover mb-0">
      <thead class="table-light">
        <tr><th>Email</th><th>Name</th><th>Active</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($recipients as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['email']) ?></td>
          <td class="text-muted"><?= htmlspecialchars($r['name'] ?? '') ?></td>
          <td>
            <form method="POST" class="d-inline">
              <input type="hidden" name="action" value="toggle_recipient">
              <input type="hidden" name="rid" value="<?= $r['id'] ?>">
              <button type="submit" class="btn btn-sm btn-<?= $r['active'] ? 'success' : 'secondary' ?>">
                <?= $r['active'] ? 'Active' : 'Paused' ?>
              </button>
            </form>
          </td>
          <td>
            <form method="POST" class="d-inline" onsubmit="return confirm('Remove this recipient?')">
              <input type="hidden" name="action" value="delete_recipient">
              <input type="hidden" name="rid" value="<?= $r['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- Cron Help -->
<div class="settings-section">
  <h5 class="mb-3"><i class="bi bi-terminal"></i> Cron / Scheduler Setup</h5>
  <p class="text-muted">Add the following line to your crontab (<code>crontab -e</code>) to run checks every minute:</p>
  <pre class="bg-dark text-light p-3 rounded"><code>* * * * * php <?= __DIR__ ?>/monitor.php --quiet >> /var/log/netmon.log 2>&amp;1</code></pre>
  <p class="text-muted mb-0">
    Or run as a loop daemon (e.g., via systemd or supervisor):
  </p>
  <pre class="bg-dark text-light p-3 rounded mt-2"><code>php <?= __DIR__ ?>/monitor.php --loop</code></pre>
  <p class="text-muted mt-2 mb-0">
    Purge old records (run weekly):
  </p>
  <pre class="bg-dark text-light p-3 rounded mt-2"><code>0 3 * * 0 php <?= __DIR__ ?>/monitor.php --purge >> /var/log/netmon.log 2>&amp;1</code></pre>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
