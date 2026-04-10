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
$functions = $stmt->fetchAll();

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

<div class="mb-1">
    <a href="events.php">&larr; Back to Events</a>
</div>

<div class="card">
    <h1><?= htmlspecialchars($event['title']) ?></h1>
    <p class="event-date"><?= date('l, F j, Y', strtotime($event['event_date'])) ?></p>
    <p class="event-location"><?= htmlspecialchars($event['location']) ?></p>
    <?php if ($event['description']): ?>
        <p class="mt-1"><?= nl2br(htmlspecialchars($event['description'])) ?></p>
    <?php endif; ?>
</div>

<?php if ($success === 'signup'): ?>
    <div class="alert alert-success">You have been signed up successfully! A confirmation email has been sent.</div>
<?php elseif ($success === 'cancel'): ?>
    <div class="alert alert-info">Your signup has been cancelled.</div>
<?php endif; ?>

<?php if ($error === 'full'): ?>
    <div class="alert alert-danger">Sorry, that time slot is already full.</div>
<?php elseif ($error === 'already'): ?>
    <div class="alert alert-warning">You are already signed up for that time slot.</div>
<?php elseif ($error === 'invalid'): ?>
    <div class="alert alert-danger">Invalid request. Please try again.</div>
<?php elseif ($error === 'csrf'): ?>
    <div class="alert alert-danger">Invalid form submission. Please try again.</div>
<?php endif; ?>

<?php if (empty($functions)): ?>
    <div class="card"><p class="text-muted">No functions have been defined for this event yet.</p></div>
<?php endif; ?>

<?php foreach ($functions as $fn): ?>
<div class="card">
    <h2><?= htmlspecialchars($fn['function_name']) ?></h2>
    <?php if ($fn['description']): ?>
        <p class="text-muted mb-1"><?= htmlspecialchars($fn['description']) ?></p>
    <?php endif; ?>

    <?php $periods = $functionPeriods[$fn['id']] ?? []; ?>
    <?php if (empty($periods)): ?>
        <p class="text-muted">No time periods defined.</p>
    <?php else: ?>
        <table>
            <thead>
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
                            <span class="slot-signed-up">Signed Up</span>
                        <?php elseif ($remaining > 0): ?>
                            <span class="slot-available"><?= $remaining ?> of <?= $p['max_signups'] ?> available</span>
                        <?php else: ?>
                            <span class="slot-full">Full</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($signedUp): ?>
                            <form method="post" action="cancel-signup.php" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="signup_id" value="<?= $p['user_signup_id'] ?>">
                                <input type="hidden" name="event_id" value="<?= $eventId ?>">
                                <button type="submit" class="btn btn-danger btn-sm"
                                        onclick="return confirm('Cancel this signup?')">Cancel</button>
                            </form>
                        <?php elseif ($remaining > 0): ?>
                            <form method="post" action="signup.php" style="display:inline">
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
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
