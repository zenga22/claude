<?php
/**
 * Login page.
 */
require_once __DIR__ . '/includes/auth.php';

auth_start_session();

// Already logged in
if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        $user = auth_login($username, $password);
        if ($user) {
            header('Location: dashboard.php');
            exit;
        }
        $error = 'Invalid username or password.';
    }
}

$pageTitle = 'Login';
require_once __DIR__ . '/templates/header.php';
?>

<div class="login-wrapper">
    <div class="login-box card">
        <h1>Login</h1>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" class="form-control"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Log In</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
