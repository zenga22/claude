<?php
/**
 * Admin — create or edit an event with functions and time periods.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$user = auth_require_admin();
$assetsBase = '../';

$eventId = (int) ($_GET['id'] ?? 0);
$isEdit  = $eventId > 0;

$event     = ['title' => '', 'event_date' => '', 'location' => '', 'description' => ''];
$functions = [];

if ($isEdit) {
    $stmt = db()->prepare('SELECT * FROM events WHERE id = :id');
    $stmt->execute(['id' => $eventId]);
    $event = $stmt->fetch();
    if (!$event) {
        header('Location: events.php');
        exit;
    }

    // Load functions and their periods
    $stmt = db()->prepare('SELECT * FROM event_functions WHERE event_id = :eid ORDER BY id');
    $stmt->execute(['eid' => $eventId]);
    $fns = $stmt->fetchAll();

    foreach ($fns as $fn) {
        $stmt = db()->prepare('SELECT * FROM event_periods WHERE function_id = :fid ORDER BY start_time');
        $stmt->execute(['fid' => $fn['id']]);
        $fn['periods'] = $stmt->fetchAll();
        $functions[] = $fn;
    }
}

$pageTitle = $isEdit ? 'Edit Event' : 'Create Event';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="mb-1"><a href="events.php">&larr; Back to Events</a></div>

<div class="card">
    <h1><?= $isEdit ? 'Edit' : 'Create' ?> Event</h1>

    <form method="post" action="event-save.php" id="eventForm">
        <?= csrf_field() ?>
        <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= $eventId ?>">
        <?php endif; ?>

        <div class="form-group">
            <label for="title">Event Title</label>
            <input type="text" id="title" name="title" class="form-control"
                   value="<?= htmlspecialchars($event['title']) ?>" required>
        </div>

        <div class="form-group">
            <label for="event_date">Date</label>
            <input type="date" id="event_date" name="event_date" class="form-control"
                   value="<?= htmlspecialchars($event['event_date']) ?>" required>
        </div>

        <div class="form-group">
            <label for="location">Location</label>
            <input type="text" id="location" name="location" class="form-control"
                   value="<?= htmlspecialchars($event['location']) ?>" required>
        </div>

        <div class="form-group">
            <label for="description">Description</label>
            <textarea id="description" name="description" class="form-control"><?= htmlspecialchars($event['description'] ?? '') ?></textarea>
        </div>

        <hr style="margin: 1.5rem 0;">
        <h2>Functions &amp; Time Periods</h2>
        <p class="text-muted mb-1">Define the roles/positions for this event and their time slots.</p>

        <div id="functions-container">
            <?php if (!empty($functions)): ?>
                <?php foreach ($functions as $fi => $fn): ?>
                <div class="card function-block" data-index="<?= $fi ?>">
                    <div class="flex-between mb-1">
                        <h3>Function #<?= $fi + 1 ?></h3>
                        <button type="button" class="btn btn-danger btn-sm" onclick="this.closest('.function-block').remove()">Remove Function</button>
                    </div>
                    <div class="form-group">
                        <label>Function Name</label>
                        <input type="text" name="functions[<?= $fi ?>][name]" class="form-control"
                               value="<?= htmlspecialchars($fn['function_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <input type="text" name="functions[<?= $fi ?>][description]" class="form-control"
                               value="<?= htmlspecialchars($fn['description'] ?? '') ?>">
                    </div>

                    <h4>Time Periods</h4>
                    <div class="periods-container">
                        <?php foreach ($fn['periods'] as $pi => $p): ?>
                        <div class="period-row" style="display:flex; gap:0.5rem; align-items:end; margin-bottom:0.5rem; flex-wrap:wrap;">
                            <div class="form-group" style="margin-bottom:0; flex:1; min-width:120px;">
                                <label>Start</label>
                                <input type="time" name="functions[<?= $fi ?>][periods][<?= $pi ?>][start]" class="form-control"
                                       value="<?= htmlspecialchars(substr($p['start_time'], 0, 5)) ?>" required>
                            </div>
                            <div class="form-group" style="margin-bottom:0; flex:1; min-width:120px;">
                                <label>End</label>
                                <input type="time" name="functions[<?= $fi ?>][periods][<?= $pi ?>][end]" class="form-control"
                                       value="<?= htmlspecialchars(substr($p['end_time'], 0, 5)) ?>" required>
                            </div>
                            <div class="form-group" style="margin-bottom:0; flex:0 0 100px;">
                                <label>Max People</label>
                                <input type="number" name="functions[<?= $fi ?>][periods][<?= $pi ?>][max]" class="form-control"
                                       value="<?= $p['max_signups'] ?>" min="1" required>
                            </div>
                            <button type="button" class="btn btn-danger btn-sm" style="margin-bottom:2px;" onclick="this.closest('.period-row').remove()">X</button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm mt-1" onclick="addPeriod(this)">+ Add Period</button>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <button type="button" class="btn btn-primary mt-2" id="addFunctionBtn">+ Add Function</button>

        <hr style="margin: 1.5rem 0;">
        <button type="submit" class="btn btn-success"><?= $isEdit ? 'Update' : 'Create' ?> Event</button>
        <a href="events.php" class="btn btn-secondary">Cancel</a>
    </form>
</div>

<script>
var funcIndex = <?= !empty($functions) ? count($functions) : 0 ?>;

document.getElementById('addFunctionBtn').addEventListener('click', function() {
    var html = '<div class="card function-block" data-index="' + funcIndex + '">' +
        '<div class="flex-between mb-1">' +
        '<h3>Function #' + (funcIndex + 1) + '</h3>' +
        '<button type="button" class="btn btn-danger btn-sm" onclick="this.closest(\'.function-block\').remove()">Remove Function</button>' +
        '</div>' +
        '<div class="form-group"><label>Function Name</label>' +
        '<input type="text" name="functions[' + funcIndex + '][name]" class="form-control" required></div>' +
        '<div class="form-group"><label>Description</label>' +
        '<input type="text" name="functions[' + funcIndex + '][description]" class="form-control"></div>' +
        '<h4>Time Periods</h4>' +
        '<div class="periods-container"></div>' +
        '<button type="button" class="btn btn-secondary btn-sm mt-1" onclick="addPeriod(this)">+ Add Period</button>' +
        '</div>';
    document.getElementById('functions-container').insertAdjacentHTML('beforeend', html);
    funcIndex++;
});

function addPeriod(btn) {
    var block = btn.closest('.function-block');
    var fi = block.getAttribute('data-index');
    var container = block.querySelector('.periods-container');
    var pi = container.querySelectorAll('.period-row').length;

    var html = '<div class="period-row" style="display:flex; gap:0.5rem; align-items:end; margin-bottom:0.5rem; flex-wrap:wrap;">' +
        '<div class="form-group" style="margin-bottom:0; flex:1; min-width:120px;">' +
        '<label>Start</label><input type="time" name="functions[' + fi + '][periods][' + pi + '][start]" class="form-control" required></div>' +
        '<div class="form-group" style="margin-bottom:0; flex:1; min-width:120px;">' +
        '<label>End</label><input type="time" name="functions[' + fi + '][periods][' + pi + '][end]" class="form-control" required></div>' +
        '<div class="form-group" style="margin-bottom:0; flex:0 0 100px;">' +
        '<label>Max People</label><input type="number" name="functions[' + fi + '][periods][' + pi + '][max]" class="form-control" value="1" min="1" required></div>' +
        '<button type="button" class="btn btn-danger btn-sm" style="margin-bottom:2px;" onclick="this.closest(\'.period-row\').remove()">X</button>' +
        '</div>';
    container.insertAdjacentHTML('beforeend', html);
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
