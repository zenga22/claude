<?php
/**
 * Heartbeat Monitor - Configuration
 *
 * Copy this file to config.local.php and adjust settings.
 * config.local.php takes precedence over config.php.
 */

return [

    // -------------------------------------------------------------------------
    // Security
    // -------------------------------------------------------------------------
    // API key clients must send in the X-Api-Key header (or ?api_key= query).
    // Set to null to disable authentication (not recommended for production).
    'api_key' => 'change-me-to-a-strong-secret',

    // -------------------------------------------------------------------------
    // Base URL of this installation (used in alert emails)
    // -------------------------------------------------------------------------
    'base_url' => 'http://localhost/heartbeat-monitor',

    // -------------------------------------------------------------------------
    // Storage
    // -------------------------------------------------------------------------
    'data_dir' => __DIR__ . '/data',

    // -------------------------------------------------------------------------
    // Monitored computers
    //
    // Each key is a unique computer ID that client scripts will send.
    // 'interval'    - Expected seconds between heartbeats.
    // 'grace'       - Extra seconds allowed past the interval before alerting.
    // 'alert_after' - Number of consecutive missed beats before first alert.
    // 'name'        - Human-readable label shown in the dashboard and emails.
    // -------------------------------------------------------------------------
    'computers' => [
        'web-server-01' => [
            'name'        => 'Web Server 01',
            'interval'    => 300,   // beat every 5 min
            'grace'       => 60,    // alert if 6 min have passed with no beat
            'alert_after' => 1,
        ],
        'db-server-01' => [
            'name'        => 'Database Server 01',
            'interval'    => 300,
            'grace'       => 60,
            'alert_after' => 1,
        ],
        'backup-server' => [
            'name'        => 'Backup Server',
            'interval'    => 3600,  // hourly check
            'grace'       => 300,
            'alert_after' => 1,
        ],
    ],

    // -------------------------------------------------------------------------
    // Email alerts
    // -------------------------------------------------------------------------
    'email' => [
        'enabled'        => true,

        // Delivery method: 'mail' (PHP mail()) or 'smtp'
        'driver'         => 'smtp',

        // SMTP settings (used when driver = 'smtp')
        'smtp_host'      => 'smtp.example.com',
        'smtp_port'      => 587,
        'smtp_encryption'=> 'tls',  // 'tls', 'ssl', or '' for none
        'smtp_user'      => 'alerts@example.com',
        'smtp_pass'      => 'smtp-password',

        // Sender
        'from_address'   => 'heartbeat@example.com',
        'from_name'      => 'Heartbeat Monitor',

        // Recipients – one or more email addresses
        'to'             => [
            'admin@example.com',
            'ops-team@example.com',
        ],

        'subject_prefix' => '[Heartbeat]',
    ],

    // Minimum seconds between repeat alerts for the same computer.
    // Prevents alert flooding: 3600 = re-alert at most once per hour.
    'alert_cooldown' => 3600,

    // -------------------------------------------------------------------------
    // Dashboard
    // -------------------------------------------------------------------------
    // Set to true to require HTTP Basic Auth on the dashboard (index.php).
    'dashboard_auth'          => false,
    'dashboard_auth_user'     => 'admin',
    'dashboard_auth_password' => 'change-me',
];
