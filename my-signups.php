<?php
/**
 * My Signups — shows all signups for the logged-in user.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

$user = auth_require_login();

$stmt = db()->prepare('
    SELECT s.id AS signup_id, s.signed_up_at,
           e.id AS event_id, e.title, e.event_date, e.location,
           ef.function_name,
           ep.start_time, ep.end_time
    FROM signups s
    JOIN event_periods ep ON s.period_id = ep.id
    JOIN event_functions ef ON ep.function_id = ef.id
    JOIN events e ON ef.event_id = e.id
    WHERE s.user_id = :uid
    ORDER BY e.event_date DESC, ep.start_time
');
$stmt->execute(['uid' => $user['id']]);
$signups = $stmt->fetchAll();

$success = $_GET['success'] ?? '';

$pageTitle = 'My Signups';
require_once __DIR__ . '/templates/header.php';
?>

<h1 class="mb-2">My Signups</h1>

<?php if ($success === 'cancel'): ?>
    <div class="alert alert-info">Signup cancelled successfully.</div>
<?php endif; ?>

<?php if (empty($signups)): ?>
    <div class="card">
        <p class="text-muted text-center">You have no signups yet. <a href="events.php">Browse events</a> to get started.</p>
    </div>
<?php else: ?>
    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Date</th>
                    <th>Location</th>
                    <th>Function</th>
                    <th>Time</th>
                    <th>Signed Up</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($signups as $s): ?>
                <?php $isPast = strtotime($s['event_date']) < strtotime('today'); ?>
                <tr>
                    <td><a href="event.php?id=<?= $s['event_id'] ?>"><?= htmlspecialchars($s['title']) ?></a></td>
                    <td><?= date('M j, Y', strtotime($s['event_date'])) ?></td>
                    <td><?= htmlspecialchars($s['location']) ?></td>
                    <td><?= htmlspecialchars($s['function_name']) ?></td>
                    <td><?= date('g:i A', strtotime($s['start_time'])) ?> &ndash; <?= date('g:i A', strtotime($s['end_time'])) ?></td>
                    <td><?= date('M j, Y g:i A', strtotime($s['signed_up_at'])) ?></td>
                    <td>
                        <?php if ($isPast): ?>
                            <span class="badge badge-info">Completed</span>
                        <?php else: ?>
                            <form method="post" action="cancel-signup.php" style="display:inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="signup_id" value="<?= $s['signup_id'] ?>">
                                <input type="hidden" name="event_id" value="<?= $s['event_id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm"
                                        onclick="return confirm('Cancel this signup?')">Cancel</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
