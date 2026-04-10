<?php
/**
 * Admin — list users and add new users.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

$user = auth_require_admin();
$assetsBase = '../';

$error   = '';
$success = $_GET['success'] ?? '';

// Handle new user creation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!csrf_validate($token)) {
        $error = 'Invalid form submission.';
    } else {
        $newUsername = trim($_POST['username'] ?? '');
        $newName     = trim($_POST['name'] ?? '');
        $newEmail    = trim($_POST['email'] ?? '');
        $newPassword = $_POST['password'] ?? '';
        $isAdmin     = isset($_POST['is_admin']) ? 1 : 0;

        if ($newUsername === '' || $newName === '' || $newEmail === '' || $newPassword === '') {
            $error = 'All fields are required.';
        } elseif (strlen($newPassword) < 6) {
            $error = 'Password must be at least 6 characters.';
        } else {
            // Check for duplicates
            $stmt = db()->prepare('SELECT id FROM users WHERE username = :u OR email = :e');
            $stmt->execute(['u' => $newUsername, 'e' => $newEmail]);
            if ($stmt->fetch()) {
                $error = 'Username or email already exists.';
            } else {
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                $stmt = db()->prepare('INSERT INTO users (username, name, email, password_hash, is_admin) VALUES (:u, :n, :e, :p, :a)');
                $stmt->execute(['u' => $newUsername, 'n' => $newName, 'e' => $newEmail, 'p' => $hash, 'a' => $isAdmin]);
                header('Location: users.php?success=created');
                exit;
            }
        }
    }
}

$users = db()->query('SELECT id, username, name, email, is_admin, created_at FROM users ORDER BY id')->fetchAll();

$pageTitle = 'Manage Users';
require_once __DIR__ . '/../templates/header.php';
?>

<h1 class="mb-4">Manage Users</h1>

<?php if ($success === 'created'): ?>
    <div class="alert alert-success alert-dismissible fade show">User created successfully.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
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
                        <th>Role</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= $u['id'] ?></td>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td><?= htmlspecialchars($u['name']) ?></td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td><?= $u['is_admin'] ? '<span class="badge bg-info">Admin</span>' : '<span class="badge bg-light text-dark">User</span>' ?></td>
                        <td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

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
                <div class="col-md-4 mb-3">
                    <label for="password" class="form-label">Password</label>
                    <input type="password" id="password" name="password" class="form-control" required minlength="6">
                </div>
                <div class="col-md-4 mb-3 d-flex align-items-end">
                    <div class="form-check mb-2">
                        <input type="checkbox" class="form-check-input" name="is_admin" value="1" id="is_admin">
                        <label class="form-check-label" for="is_admin">Admin privileges</label>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-success">Create User</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
