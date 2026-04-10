<?php
/**
 * Logout — destroy session and redirect to login.
 */
require_once __DIR__ . '/includes/auth.php';

auth_logout();
header('Location: login.php');
exit;
