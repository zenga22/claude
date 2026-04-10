<?php
/**
 * Application Configuration
 *
 * To override defaults, create config.local.php in this directory and define
 * only the constants you need to change. The local file is loaded first, and
 * the defaults below use defined() guards so they won't collide.
 */

// Load local overrides first (before any define calls)
$localConfig = __DIR__ . '/config.local.php';
if (file_exists($localConfig)) {
    require_once $localConfig;
}

// Database
if (!defined('DB_HOST'))    define('DB_HOST', 'localhost');
if (!defined('DB_NAME'))    define('DB_NAME', 'staffing_events');
if (!defined('DB_USER'))    define('DB_USER', 'root');
if (!defined('DB_PASS'))    define('DB_PASS', '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// Email (SMTP via PHP mail() by default)
if (!defined('MAIL_FROM_ADDRESS')) define('MAIL_FROM_ADDRESS', 'noreply@example.com');
if (!defined('MAIL_FROM_NAME'))    define('MAIL_FROM_NAME', 'Staffing Events');
if (!defined('MAIL_USE_SMTP'))     define('MAIL_USE_SMTP', false);
if (!defined('SMTP_HOST'))         define('SMTP_HOST', 'smtp.example.com');
if (!defined('SMTP_PORT'))         define('SMTP_PORT', 587);
if (!defined('SMTP_USER'))         define('SMTP_USER', '');
if (!defined('SMTP_PASS'))         define('SMTP_PASS', '');

// Application
if (!defined('APP_NAME'))          define('APP_NAME', 'Staffing Event Signup');
if (!defined('APP_URL'))           define('APP_URL', 'http://localhost/staffing');
if (!defined('SESSION_LIFETIME'))  define('SESSION_LIFETIME', 3600); // 1 hour

// Reminders — send this many days before the event
if (!defined('REMINDER_DAYS_BEFORE')) define('REMINDER_DAYS_BEFORE', 1);
