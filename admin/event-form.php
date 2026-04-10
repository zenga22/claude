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

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="events.php">Manage Events</a></li>
        <li class="breadcrumb-item active"><?= $isEdit ? 'Edit' : 'Create' ?></li>
    </ol>
</nav>

<?php if (!empty($_GET['copied'])): ?>
    <div class="alert alert-success alert-dismissible fade show">Event copied successfully. Update the details below and save.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <h1 class="card-title h4 mb-3"><?= $isEdit ? 'Edit' : 'Create' ?> Event</h1>

        <form method="post" action="event-save.php" id="eventForm">
            <?= csrf_field() ?>
            <?php if ($isEdit): ?>
                <input type="hidden" name="id" value="<?= $eventId ?>">
            <?php endif; ?>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="title" class="form-label">Event Title</label>
                    <input type="text" id="title" name="title" class="form-control"
                           value="<?= htmlspecialchars($event['title']) ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label for="event_date" class="form-label">Date</label>
                    <input type="date" id="event_date" name="event_date" class="form-control"
                           value="<?= htmlspecialchars($event['event_date']) ?>" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label for="location" class="form-label">Location</label>
                    <input type="text" id="location" name="location" class="form-control"
                           value="<?= htmlspecialchars($event['location']) ?>" required>
                </div>
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">Description</label>
                <textarea id="description" name="description" class="form-control" rows="3"><?= htmlspecialchars($event['description'] ?? '') ?></textarea>
            </div>

            <hr>
            <h5>Functions &amp; Time Periods</h5>
            <p class="text-muted small">Define the roles/positions for this event and their time slots.</p>

            <div id="functions-container">
                <?php if (!empty($functions)): ?>
                    <?php foreach ($functions as $fi => $fn): ?>
                    <div class="function-block" data-index="<?= $fi ?>">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0">Function #<?= $fi + 1 ?></h6>
                            <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.function-block').remove()">Remove</button>
                        </div>
                        <div class="row">
                            <div class="col-md-5 mb-3">
                                <label class="form-label">Function Name</label>
                                <input type="text" name="functions[<?= $fi ?>][name]" class="form-control"
                                       value="<?= htmlspecialchars($fn['function_name']) ?>" required>
                            </div>
                            <div class="col-md-7 mb-3">
                                <label class="form-label">Description</label>
                                <input type="text" name="functions[<?= $fi ?>][description]" class="form-control"
                                       value="<?= htmlspecialchars($fn['description'] ?? '') ?>">
                            </div>
                        </div>

                        <label class="form-label fw-semibold">Time Periods</label>
                        <div class="periods-container">
                            <?php foreach ($fn['periods'] as $pi => $p): ?>
                            <div class="period-row">
                                <div class="form-group">
                                    <label class="form-label small">Start</label>
                                    <input type="time" name="functions[<?= $fi ?>][periods][<?= $pi ?>][start]" class="form-control form-control-sm"
                                           value="<?= htmlspecialchars(substr($p['start_time'], 0, 5)) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label small">End</label>
                                    <input type="time" name="functions[<?= $fi ?>][periods][<?= $pi ?>][end]" class="form-control form-control-sm"
                                           value="<?= htmlspecialchars(substr($p['end_time'], 0, 5)) ?>" required>
                                </div>
                                <div class="form-group form-group-max">
                                    <label class="form-label small">Max People</label>
                                    <input type="number" name="functions[<?= $fi ?>][periods][<?= $pi ?>][max]" class="form-control form-control-sm"
                                           value="<?= $p['max_signups'] ?>" min="1" required>
                                </div>
                                <button type="button" class="btn btn-outline-danger btn-sm align-self-end" onclick="this.closest('.period-row').remove()">X</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm mt-1" onclick="addPeriod(this)">+ Add Period</button>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <button type="button" class="btn btn-primary mt-3" id="addFunctionBtn">+ Add Function</button>

            <hr>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success"><?= $isEdit ? 'Update' : 'Create' ?> Event</button>
                <a href="events.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
var funcIndex = <?= !empty($functions) ? count($functions) : 0 ?>;

document.getElementById('addFunctionBtn').addEventListener('click', function() {
    var html = '<div class="function-block" data-index="' + funcIndex + '">' +
        '<div class="d-flex justify-content-between align-items-center mb-2">' +
        '<h6 class="mb-0">Function #' + (funcIndex + 1) + '</h6>' +
        '<button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest(\'.function-block\').remove()">Remove</button></div>' +
        '<div class="row"><div class="col-md-5 mb-3"><label class="form-label">Function Name</label>' +
        '<input type="text" name="functions[' + funcIndex + '][name]" class="form-control" required></div>' +
        '<div class="col-md-7 mb-3"><label class="form-label">Description</label>' +
        '<input type="text" name="functions[' + funcIndex + '][description]" class="form-control"></div></div>' +
        '<label class="form-label fw-semibold">Time Periods</label>' +
        '<div class="periods-container"></div>' +
        '<button type="button" class="btn btn-outline-secondary btn-sm mt-1" onclick="addPeriod(this)">+ Add Period</button></div>';
    document.getElementById('functions-container').insertAdjacentHTML('beforeend', html);
    funcIndex++;
});

function addPeriod(btn) {
    var block = btn.closest('.function-block');
    var fi = block.getAttribute('data-index');
    var container = block.querySelector('.periods-container');
    var pi = container.querySelectorAll('.period-row').length;

    var html = '<div class="period-row">' +
        '<div class="form-group"><label class="form-label small">Start</label>' +
        '<input type="time" name="functions[' + fi + '][periods][' + pi + '][start]" class="form-control form-control-sm" required></div>' +
        '<div class="form-group"><label class="form-label small">End</label>' +
        '<input type="time" name="functions[' + fi + '][periods][' + pi + '][end]" class="form-control form-control-sm" required></div>' +
        '<div class="form-group form-group-max"><label class="form-label small">Max People</label>' +
        '<input type="number" name="functions[' + fi + '][periods][' + pi + '][max]" class="form-control form-control-sm" value="1" min="1" required></div>' +
        '<button type="button" class="btn btn-outline-danger btn-sm align-self-end" onclick="this.closest(\'.period-row\').remove()">X</button></div>';
    container.insertAdjacentHTML('beforeend', html);
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
