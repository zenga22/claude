<?php
/**
 * View a single event — shows functions and time periods with signup buttons.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$user = auth_require_login();

$eventId = (int) ($_GET['id'] ?? 0);
if ($eventId <= 0) {
    header('Location: events.php');
    exit;
}

// Fetch the event
$stmt = db()->prepare('SELECT * FROM events WHERE id = :id');
$stmt->execute(['id' => $eventId]);
$event = $stmt->fetch();

if (!$event) {
    header('Location: events.php');
    exit;
}

// Fetch functions for this event
$stmt = db()->prepare('SELECT * FROM event_functions WHERE event_id = :eid ORDER BY id');
$stmt->execute(['eid' => $eventId]);
$allFunctions = $stmt->fetchAll();

// Load role restrictions for each function and filter by user's roles
$userRoleIds = $user['role_ids'] ?? [];
$functions = [];
foreach ($allFunctions as $fn) {
    $stmt = db()->prepare('SELECT role_id FROM event_function_roles WHERE function_id = :fid');
    $stmt->execute(['fid' => $fn['id']]);
    $requiredRoleIds = array_column($stmt->fetchAll(), 'role_id');
    $fn['required_role_ids'] = $requiredRoleIds;

    // If no role restrictions, open to all; otherwise check intersection
    if (!empty($requiredRoleIds) && empty(array_intersect($userRoleIds, $requiredRoleIds))) {
        continue; // user lacks required role
    }
    $functions[] = $fn;
}

// Fetch periods for each function, with signup counts and user's own signups
$functionPeriods = [];
foreach ($functions as $fn) {
    $stmt = db()->prepare('
        SELECT ep.*,
               (SELECT COUNT(*) FROM signups WHERE period_id = ep.id) AS signup_count,
               (SELECT id FROM signups WHERE period_id = ep.id AND user_id = :uid LIMIT 1) AS user_signup_id
        FROM event_periods ep
        WHERE ep.function_id = :fid
        ORDER BY ep.start_time
    ');
    $stmt->execute(['fid' => $fn['id'], 'uid' => $user['id']]);
    $functionPeriods[$fn['id']] = $stmt->fetchAll();
}

// Flash messages from signup/cancel actions
$success = $_GET['success'] ?? '';
$error   = $_GET['error'] ?? '';

$pageTitle = $event['title'];
require_once __DIR__ . '/templates/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="events.php">Events</a></li>
        <li class="breadcrumb-item active"><?= htmlspecialchars($event['title']) ?></li>
    </ol>
</nav>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h1 class="card-title"><?= htmlspecialchars($event['title']) ?></h1>
        <p class="text-muted mb-1"><?= date('l, F j, Y', strtotime($event['event_date'])) ?></p>
        <p class="text-muted mb-2"><?= htmlspecialchars($event['location']) ?></p>
        <?php if ($event['description']): ?>
            <p class="mt-2"><?= nl2br(htmlspecialchars($event['description'])) ?></p>
        <?php endif; ?>
    </div>
</div>

<?php if ($success === 'signup'): ?>
    <div class="alert alert-success alert-dismissible fade show">You have been signed up successfully! A confirmation email has been sent.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php elseif ($success === 'cancel'): ?>
    <div class="alert alert-info alert-dismissible fade show">Your signup has been cancelled.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if ($error === 'full'): ?>
    <div class="alert alert-danger">Sorry, that time slot is already full.</div>
<?php elseif ($error === 'already'): ?>
    <div class="alert alert-warning">You are already signed up for that time slot.</div>
<?php elseif ($error === 'invalid'): ?>
    <div class="alert alert-danger">Invalid request. Please try again.</div>
<?php elseif ($error === 'csrf'): ?>
    <div class="alert alert-danger">Invalid form submission. Please try again.</div>
<?php elseif ($error === 'role'): ?>
    <div class="alert alert-danger">You do not have the required role to sign up for that function.</div>
<?php endif; ?>

<?php if (empty($functions) && empty($allFunctions)): ?>
    <div class="card shadow-sm"><div class="card-body"><p class="text-muted mb-0">No functions have been defined for this event yet.</p></div></div>
<?php elseif (empty($functions)): ?>
    <div class="alert alert-info">There are no functions available for your current role(s). Contact an administrator if you believe this is an error.</div>
<?php endif; ?>

<?php if (count($functions) < count($allFunctions)): ?>
    <div class="alert alert-info alert-dismissible fade show">Some functions are not shown because they require roles you don't have.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php foreach ($functions as $fn): ?>
<div class="card shadow-sm mb-3">
    <div class="card-header bg-light">
        <h5 class="mb-0"><?= htmlspecialchars($fn['function_name']) ?></h5>
        <?php if ($fn['description']): ?>
            <small class="text-muted"><?= htmlspecialchars($fn['description']) ?></small>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php $periods = $functionPeriods[$fn['id']] ?? []; ?>
        <?php if (empty($periods)): ?>
            <p class="text-muted p-3 mb-0">No time periods defined.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Time Period</th>
                            <th>Availability</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($periods as $p): ?>
                        <?php
                            $start     = date('g:i A', strtotime($p['start_time']));
                            $end       = date('g:i A', strtotime($p['end_time']));
                            $remaining = $p['max_signups'] - $p['signup_count'];
                            $signedUp  = !empty($p['user_signup_id']);
                        ?>
                        <tr>
                            <td><?= $start ?> &ndash; <?= $end ?></td>
                            <td>
                                <?php if ($signedUp): ?>
                                    <span class="badge bg-primary">Signed Up</span>
                                <?php elseif ($remaining > 0): ?>
                                    <span class="slot-available"><?= $remaining ?> of <?= $p['max_signups'] ?> available</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Full</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($signedUp): ?>
                                    <form method="post" action="cancel-signup.php" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="signup_id" value="<?= $p['user_signup_id'] ?>">
                                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm"
                                                onclick="return confirm('Cancel this signup?')">Cancel</button>
                                    </form>
                                <?php elseif ($remaining > 0): ?>
                                    <form method="post" action="signup.php" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="period_id" value="<?= $p['id'] ?>">
                                        <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                        <button type="submit" class="btn btn-success btn-sm">Sign Up</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted">&mdash;</span>
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

<?php require_once __DIR__ . '/templates/footer.php'; ?>
