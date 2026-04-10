<?php
/**
 * Admin — Event Staffing Report.
 *
 * Shows who is signed up for each function and time period of an event.
 * If no event ID is given, shows a picker for all events.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$user = auth_require_admin();
$assetsBase = '../';

$eventId = (int) ($_GET['id'] ?? 0);
$event   = null;

// Fetch all events for the dropdown
$allEvents = db()->query('SELECT id, title, event_date, location FROM events ORDER BY event_date DESC')->fetchAll();

if ($eventId > 0) {
    $stmt = db()->prepare('SELECT * FROM events WHERE id = :id');
    $stmt->execute(['id' => $eventId]);
    $event = $stmt->fetch();
}

// If an event is selected, build the full staffing data
$reportData = [];
$totalSignups = 0;
$totalSlots   = 0;

if ($event) {
    $stmt = db()->prepare('SELECT * FROM event_functions WHERE event_id = :eid ORDER BY id');
    $stmt->execute(['eid' => $eventId]);
    $functions = $stmt->fetchAll();

    foreach ($functions as $fn) {
        $stmt = db()->prepare('SELECT * FROM event_periods WHERE function_id = :fid ORDER BY start_time');
        $stmt->execute(['fid' => $fn['id']]);
        $periods = $stmt->fetchAll();

        $fnData = [
            'function'  => $fn,
            'periods'   => [],
        ];

        foreach ($periods as $p) {
            $stmt = db()->prepare('
                SELECT u.id, u.username, u.name, u.email, s.signed_up_at
                FROM signups s
                JOIN users u ON s.user_id = u.id
                WHERE s.period_id = :pid
                ORDER BY u.name, u.username
            ');
            $stmt->execute(['pid' => $p['id']]);
            $signedUpUsers = $stmt->fetchAll();

            $totalSlots   += $p['max_signups'];
            $totalSignups += count($signedUpUsers);

            $fnData['periods'][] = [
                'period' => $p,
                'users'  => $signedUpUsers,
            ];
        }

        $reportData[] = $fnData;
    }
}

$pageTitle = 'Staffing Report';
require_once __DIR__ . '/../templates/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
        <li class="breadcrumb-item active">Staffing Report</li>
    </ol>
</nav>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="mb-0">Staffing Report</h1>
    <?php if ($event): ?>
        <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">Print Report</button>
    <?php endif; ?>
</div>

<!-- Event Selector -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="get" action="event-report.php" class="row g-2 align-items-end">
            <div class="col-md-8">
                <label for="eventSelect" class="form-label">Select an Event</label>
                <select id="eventSelect" name="id" class="form-select">
                    <option value="">-- Choose an event --</option>
                    <?php foreach ($allEvents as $ev): ?>
                        <option value="<?= $ev['id'] ?>" <?= $ev['id'] == $eventId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ev['title']) ?> (<?= date('M j, Y', strtotime($ev['event_date'])) ?> &mdash; <?= htmlspecialchars($ev['location']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary">View Report</button>
            </div>
        </form>
    </div>
</div>

<?php if ($eventId > 0 && !$event): ?>
    <div class="alert alert-danger">Event not found.</div>
<?php endif; ?>

<?php if ($event): ?>

<!-- Event Summary -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <h4><?= htmlspecialchars($event['title']) ?></h4>
                <p class="mb-1"><strong>Date:</strong> <?= date('l, F j, Y', strtotime($event['event_date'])) ?></p>
                <p class="mb-1"><strong>Location:</strong> <?= htmlspecialchars($event['location']) ?></p>
                <?php if ($event['description']): ?>
                    <p class="text-muted small mt-2"><?= htmlspecialchars($event['description']) ?></p>
                <?php endif; ?>
            </div>
            <div class="col-md-6">
                <div class="row text-center">
                    <div class="col-4">
                        <div class="border rounded p-3">
                            <h3 class="mb-0"><?= $totalSignups ?></h3>
                            <small class="text-muted">Signed Up</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="border rounded p-3">
                            <h3 class="mb-0"><?= $totalSlots ?></h3>
                            <small class="text-muted">Total Slots</small>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="border rounded p-3">
                            <h3 class="mb-0"><?= $totalSlots - $totalSignups ?></h3>
                            <small class="text-muted">Open</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Staffing Details by Function -->
<?php if (empty($reportData)): ?>
    <div class="alert alert-info">No functions or time periods have been defined for this event.</div>
<?php endif; ?>

<?php foreach ($reportData as $fnBlock): ?>
    <?php $fn = $fnBlock['function']; ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-light">
            <h5 class="mb-0"><?= htmlspecialchars($fn['function_name']) ?></h5>
            <?php if ($fn['description']): ?>
                <small class="text-muted"><?= htmlspecialchars($fn['description']) ?></small>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if (empty($fnBlock['periods'])): ?>
                <p class="text-muted p-3 mb-0">No time periods defined.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 180px;">Time Period</th>
                                <th style="width: 120px;">Status</th>
                                <th>Signed-Up Staff</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($fnBlock['periods'] as $pBlock): ?>
                            <?php
                                $p     = $pBlock['period'];
                                $users = $pBlock['users'];
                                $start = date('g:i A', strtotime($p['start_time']));
                                $end   = date('g:i A', strtotime($p['end_time']));
                                $count = count($users);
                                $max   = $p['max_signups'];
                                $open  = $max - $count;
                            ?>
                            <tr>
                                <td class="fw-semibold"><?= $start ?> &ndash; <?= $end ?></td>
                                <td>
                                    <?php if ($open <= 0): ?>
                                        <span class="badge bg-success">Full (<?= $count ?>/<?= $max ?>)</span>
                                    <?php elseif ($count > 0): ?>
                                        <span class="badge bg-warning text-dark"><?= $count ?>/<?= $max ?> filled</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Empty (0/<?= $max ?>)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (empty($users)): ?>
                                        <span class="text-muted fst-italic">No one signed up yet</span>
                                    <?php else: ?>
                                        <ul class="list-unstyled mb-0">
                                            <?php foreach ($users as $u): ?>
                                                <li>
                                                    <strong><?= htmlspecialchars($u['name'] ?: $u['username']) ?></strong>
                                                    <span class="text-muted">(<?= htmlspecialchars($u['username']) ?>)</span>
                                                    &mdash;
                                                    <a href="mailto:<?= htmlspecialchars($u['email']) ?>"><?= htmlspecialchars($u['email']) ?></a>
                                                    <small class="text-muted ms-1">signed up <?= date('M j, g:i A', strtotime($u['signed_up_at'])) ?></small>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php endif; /* end if $event */ ?>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
