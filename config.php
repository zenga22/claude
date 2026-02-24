<?php
// =============================================================================
// config.php — Database and application configuration
// =============================================================================
// Copy this file and adjust values to match your environment.
// Ensure this file is NOT publicly accessible (place outside web root or
// restrict via .htaccess / server config).
// =============================================================================

// --- MySQL database connection -----------------------------------------------
define('DB_HOST',     'localhost');   // Hostname or IP of MySQL server
define('DB_PORT',     3306);          // MySQL port (default: 3306)
define('DB_NAME',     'your_db');     // Database / schema name
define('DB_USER',     'your_user');   // MySQL username
define('DB_PASS',     'your_pass');   // MySQL password
define('DB_CHARSET',  'utf8mb4');     // Character set

// --- Table / column to search -------------------------------------------------
define('DB_TABLE',    'users');       // Table name to search
define('DB_COLUMN',   'email');       // Column that holds e-mail addresses

// --- Email alias / forwarding file -------------------------------------------
// Path to your mail alias file.
// Common locations:
//   /etc/aliases                    (sendmail / postfix local aliases)
//   /etc/postfix/virtual            (postfix virtual alias map)
//   /etc/mail/virtusertable         (sendmail virtual user table)
define('ALIAS_FILE',  '/etc/aliases');

// --- Alias file format --------------------------------------------------------
// 'aliases'  — standard /etc/aliases format:
//                  localname: dest1@example.com, dest2@example.com
//
// 'virtual'  — postfix virtual map / sendmail virtusertable format:
//                  alias@domain.com    dest1@example.com dest2@example.com
//
define('ALIAS_FORMAT', 'aliases');
