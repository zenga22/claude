<?php
/**
 * Admin — list users, add new users, and manage role assignments.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$user = auth_require_admin();
$assetsBase = '../';

$error   = '';
$success = $_GET['success'] ?? '';

$allRoles = db()->query('SELECT * FROM roles ORDER BY role_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_validate($token)) {
        $error = 'Invalid form submission.';
    } else {
        $action = $_POST['action'] ?? 'create_user';

        if ($action === 'create_user') {
            $newUsername = trim($_POST['username'] ?? '');
            $newName     = trim($_POST['name'] ?? '');
            $newEmail    = trim($_POST['email'] ?? '');
            $newPassword = $_POST['password'] ?? '';
            $isAdmin     = isset($_POST['is_admin']) ? 1 : 0;
            $roleIds     = $_POST['roles'] ?? [];

            if ($newUsername === '' || $newName === '' || $newEmail === '' || $newPassword === '') {
                $error = 'All fields are required.';
            } elseif (strlen($newPassword) < 6) {
                $error = 'Password must be at least 6 characters.';
            } else {
                $stmt = db()->prepare('SELECT id FROM users WHERE username = :u OR email = :e');
                $stmt->execute(['u' => $newUsername, 'e' => $newEmail]);
                if ($stmt->fetch()) {
                    $error = 'Username or email already exists.';
                } else {
                    $pdo = db();
                    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                    $stmt = $pdo->prepare('INSERT INTO users (username, name, email, password_hash, is_admin) VALUES (:u, :n, :e, :p, :a)');
                    $stmt->execute(['u' => $newUsername, 'n' => $newName, 'e' => $newEmail, 'p' => $hash, 'a' => $isAdmin]);
                    $newUserId = (int) $pdo->lastInsertId();

                    foreach ($roleIds as $rid) {
                        $rid = (int) $rid;
                        if ($rid > 0) {
                            $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:uid, :rid)')
                                ->execute(['uid' => $newUserId, 'rid' => $rid]);
                        }
                    }

                    header('Location: users.php?success=created');
                    exit;
                }
            }
        } elseif ($action === 'update_roles') {
            $targetUserId = (int) ($_POST['user_id'] ?? 0);
            $roleIds      = $_POST['roles'] ?? [];

            if ($targetUserId > 0) {
                $pdo = db();
                $pdo->prepare('DELETE FROM user_roles WHERE user_id = :uid')->execute(['uid' => $targetUserId]);
                foreach ($roleIds as $rid) {
                    $rid = (int) $rid;
                    if ($rid > 0) {
                        $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:uid, :rid)')
                            ->execute(['uid' => $targetUserId, 'rid' => $rid]);
                    }
                }
            }
            header('Location: users.php?success=roles_updated');
            exit;
        }
    }
}

// Fetch users with their roles
$users = db()->query('SELECT id, username, name, email, is_admin, created_at FROM users ORDER BY id')->fetchAll();
$userRolesMap = [];
$allUserRoles = db()->query('SELECT ur.user_id, r.id AS role_id, r.role_name FROM user_roles ur JOIN roles r ON ur.role_id = r.id ORDER BY r.role_name')->fetchAll();
foreach ($allUserRoles as $ur) {
    $userRolesMap[$ur['user_id']][] = $ur;
}

$pageTitle = 'Manage Users';
require_once __DIR__ . '/../templates/header.php';
?>

<h1 class="mb-4">Manage Users</h1>

<?php if ($success === 'created'): ?>
    <div class="alert alert-success alert-dismissible fade show">User created successfully.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php elseif ($success === 'roles_updated'): ?>
    <div class="alert alert-success alert-dismissible fade show">User roles updated.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-light">
        <h5 class="mb-0">Current Users</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Admin</th>
                        <th>Roles</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <?php $uRoles = $userRolesMap[$u['id']] ?? []; ?>
                    <tr>
                        <td><?= $u['id'] ?></td>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td><?= htmlspecialchars($u['name']) ?></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td><?= $u['is_admin'] ? '<span class="badge bg-info">Yes</span>' : '' ?></td>
                        <td>
                            <?php if (empty($uRoles)): ?>
                                <span class="text-muted small">None</span>
                            <?php else: ?>
                                <?php foreach ($uRoles as $ur): ?>
                                    <span class="badge bg-primary"><?= htmlspecialchars($ur['role_name']) ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                    data-bs-toggle="modal" data-bs-target="#rolesModal<?= $u['id'] ?>">Edit Roles</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Role-edit modals for each user -->
<?php foreach ($users as $u): ?>
    <?php
        $uRoleIds = array_column($userRolesMap[$u['id']] ?? [], 'role_id');
    ?>
    <div class="modal fade" id="rolesModal<?= $u['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <form method="post" action="users.php" class="modal-content">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_roles">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Roles for <?= htmlspecialchars($u['name'] ?: $u['username']) ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($allRoles)): ?>
                        <p class="text-muted">No roles have been created yet. <a href="roles.php">Create roles first</a>.</p>
                    <?php else: ?>
                        <?php foreach ($allRoles as $r): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="roles[]" value="<?= $r['id'] ?>"
                                       id="role_<?= $u['id'] ?>_<?= $r['id'] ?>"
                                       <?= in_array($r['id'], $uRoleIds) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="role_<?= $u['id'] ?>_<?= $r['id'] ?>"><?= htmlspecialchars($r['role_name']) ?></label>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Roles</button>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<div class="card shadow-sm">
    <div class="card-header bg-light">
        <h5 class="mb-0">Add New User</h5>
    </div>
    <div class="card-body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="users.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_user">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="username" class="form-label">Username</label>
                    <input type="text" id="username" name="username" class="form-control"
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label for="name" class="form-label">Full Name</label>
                    <input type="text" id="name" name="name" class="form-control"
                           value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" id="email" name="email" class="form-control"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                </div>
            </div>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" id="password" name="password" class="form-control" required minlength="6">
                </div>
                <div class="col-md-2 mb-3 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input type="checkbox" class="form-check-input" name="is_admin" value="1" id="is_admin">
                        <label class="form-check-label" for="is_admin">Admin</label>
                    </div>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Roles</label>
                    <select name="roles[]" class="form-select" multiple size="3">
                        <?php foreach ($allRoles as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['role_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-success">Create User</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
