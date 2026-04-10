<?php
/**
 * Authentication helpers.
 */

require_once __DIR__ . '/db.php';

function auth_start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Attempt to log in with the given credentials.
 * Returns the user row on success, or null on failure.
 */
function auth_login(string $username, string $password): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        auth_start_session();
        $_SESSION['user_id']  = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['is_admin'] = (bool) $user['is_admin'];
        return $user;
    }
    return null;
}

function auth_logout(): void
{
    auth_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/**
 * Require the user to be logged in. Redirects to login page if not.
 */
function auth_require_login(): array
{
    auth_start_session();
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
    return [
        'id'       => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'is_admin' => $_SESSION['is_admin'],
    ];
}

/**
 * Require admin privileges. Redirects to dashboard with error if not admin.
 */
function auth_require_admin(): array
{
    $user = auth_require_login();
    if (!$user['is_admin']) {
        header('Location: dashboard.php?error=access_denied');
        exit;
    }
    return $user;
}

function auth_current_user(): ?array
{
    auth_start_session();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id'       => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'is_admin' => $_SESSION['is_admin'],
    ];
}

/**
 * Generate a CSRF token and store it in the session.
 */
function csrf_token(): string
{
    auth_start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate the submitted CSRF token.
 */
function csrf_validate(string $token): bool
{
    auth_start_session();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token()) . '">';
}
