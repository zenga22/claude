<?php
/**
 * Entry point — redirects to dashboard or login.
 */
require_once __DIR__ . '/includes/auth.php';

auth_start_session();

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
