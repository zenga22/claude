<?php
/**
 * Application Configuration
 *
 * Copy this file to config.local.php and update values for your environment.
 * config.local.php is loaded automatically if it exists and overrides these defaults.
 */

// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'staffing_events');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Email (SMTP via PHP mail() by default)
define('MAIL_FROM_ADDRESS', 'noreply@example.com');
define('MAIL_FROM_NAME', 'Staffing Events');
define('MAIL_USE_SMTP', false);
define('SMTP_HOST', 'smtp.example.com');
define('SMTP_PORT', 587);
define('SMTP_USER', '');
define('SMTP_PASS', '');

// Application
define('APP_NAME', 'Staffing Event Signup');
define('APP_URL', 'http://localhost/staffing');
define('SESSION_LIFETIME', 3600); // 1 hour

// Reminders — send this many days before the event
define('REMINDER_DAYS_BEFORE', 1);

// Load local overrides if present
$localConfig = __DIR__ . '/config.local.php';
if (file_exists($localConfig)) {
    require_once $localConfig;
}
