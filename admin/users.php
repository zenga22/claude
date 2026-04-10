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
        $newEmail    = trim($_POST['email'] ?? '');
        $newPassword = $_POST['password'] ?? '';
        $isAdmin     = isset($_POST['is_admin']) ? 1 : 0;

        if ($newUsername === '' || $newEmail === '' || $newPassword === '') {
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
                $stmt = db()->prepare('INSERT INTO users (username, email, password_hash, is_admin) VALUES (:u, :e, :p, :a)');
                $stmt->execute(['u' => $newUsername, 'e' => $newEmail, 'p' => $hash, 'a' => $isAdmin]);
                header('Location: users.php?success=created');
                exit;
            }
        }
    }
}

$users = db()->query('SELECT id, username, email, is_admin, created_at FROM users ORDER BY id')->fetchAll();

$pageTitle = 'Manage Users';
require_once __DIR__ . '/../templates/header.php';
?>

<h1 class="mb-2">Manage Users</h1>

<?php if ($success === 'created'): ?>
    <div class="alert alert-success">User created successfully.</div>
<?php endif; ?>

<div class="card">
    <h2>Current Users</h2>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Username</th>
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
                <td><?= htmlspecialchars($u['email']) ?></td>
                <td><?= $u['is_admin'] ? '<span class="badge badge-info">Admin</span>' : 'User' ?></td>
                <td><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h2>Add New User</h2>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post" action="users.php">
        <?= csrf_field() ?>
        <div class="form-group">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" class="form-control"
                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" class="form-control"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" class="form-control" required minlength="6">
        </div>
        <div class="form-group">
            <label>
                <input type="checkbox" name="is_admin" value="1"> Admin privileges
            </label>
        </div>
        <button type="submit" class="btn btn-success">Create User</button>
    </form>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
