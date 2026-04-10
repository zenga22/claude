<?php
/**
 * Admin — manage roles (create, delete).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$user = auth_require_admin();
$assetsBase = '../';

$error   = '';
$success = $_GET['success'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_validate($token)) {
        $error = 'Invalid form submission.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $roleName = trim($_POST['role_name'] ?? '');
            if ($roleName === '') {
                $error = 'Role name is required.';
            } else {
                $stmt = db()->prepare('SELECT id FROM roles WHERE role_name = :n');
                $stmt->execute(['n' => $roleName]);
                if ($stmt->fetch()) {
                    $error = 'A role with that name already exists.';
                } else {
                    $stmt = db()->prepare('INSERT INTO roles (role_name) VALUES (:n)');
                    $stmt->execute(['n' => $roleName]);
                    header('Location: roles.php?success=created');
                    exit;
                }
            }
        } elseif ($action === 'delete') {
            $roleId = (int) ($_POST['role_id'] ?? 0);
            if ($roleId > 0) {
                $stmt = db()->prepare('DELETE FROM roles WHERE id = :id');
                $stmt->execute(['id' => $roleId]);
            }
            header('Location: roles.php?success=deleted');
            exit;
        }
    }
}

$roles = db()->query('
    SELECT r.*,
           (SELECT COUNT(*) FROM user_roles WHERE role_id = r.id) AS user_count,
           (SELECT COUNT(*) FROM event_function_roles WHERE role_id = r.id) AS function_count
    FROM roles r
    ORDER BY r.role_name
')->fetchAll();

$pageTitle = 'Manage Roles';
require_once __DIR__ . '/../templates/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
        <li class="breadcrumb-item active">Roles</li>
    </ol>
</nav>

<h1 class="mb-4">Manage Roles</h1>

<?php if ($success === 'created'): ?>
    <div class="alert alert-success alert-dismissible fade show">Role created.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php elseif ($success === 'deleted'): ?>
    <div class="alert alert-info alert-dismissible fade show">Role deleted.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-light"><h5 class="mb-0">Current Roles</h5></div>
    <div class="card-body p-0">
        <?php if (empty($roles)): ?>
            <p class="text-muted text-center p-4 mb-0">No roles defined yet.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Role Name</th>
                            <th>Users</th>
                            <th>Functions Using</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($roles as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['role_name']) ?></td>
                            <td><span class="badge bg-secondary"><?= $r['user_count'] ?></span></td>
                            <td><span class="badge bg-secondary"><?= $r['function_count'] ?></span></td>
                            <td>
                                <form method="post" action="roles.php" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="role_id" value="<?= $r['id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm"
                                            onclick="return confirm('Delete this role? It will be removed from all users and functions.')">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-light"><h5 class="mb-0">Add New Role</h5></div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post" action="roles.php" class="row g-2 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="col-md-6">
                <label for="role_name" class="form-label">Role Name</label>
                <input type="text" id="role_name" name="role_name" class="form-control"
                       value="<?= htmlspecialchars($_POST['role_name'] ?? '') ?>" required>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-success">Create Role</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
