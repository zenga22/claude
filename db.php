<?php
/**
 * Database connection and schema initialization.
 * Supports SQLite (default) or MySQL via DB_TYPE constant.
 */

define('DB_TYPE', getenv('DB_TYPE') ?: 'sqlite');
define('DB_PATH', __DIR__ . '/data/netmon.db');   // SQLite only
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'netmon');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

function db_connect(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (DB_TYPE === 'mysql') {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } else {
        $dir = dirname(DB_PATH);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
    }

    db_migrate($pdo);
    return $pdo;
}

function db_migrate(PDO $pdo): void {
    // Services being monitored
    $pdo->exec("CREATE TABLE IF NOT EXISTS services (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL,
        type        TEXT    NOT NULL DEFAULT 'http',   -- http | tcp | ping
        host        TEXT    NOT NULL,
        port        INTEGER,
        path        TEXT    DEFAULT '/',
        interval    INTEGER NOT NULL DEFAULT 60,        -- seconds between checks
        threshold   INTEGER NOT NULL DEFAULT 3,         -- failures before alert
        timeout     INTEGER NOT NULL DEFAULT 10,        -- seconds
        enabled     INTEGER NOT NULL DEFAULT 1,
        last_status TEXT    DEFAULT 'unknown',          -- up | down | unknown
        last_check  INTEGER,                            -- unix timestamp
        fail_count  INTEGER NOT NULL DEFAULT 0,
        alert_sent  INTEGER NOT NULL DEFAULT 0,
        created_at  INTEGER NOT NULL DEFAULT (strftime('%s','now')),
        notes       TEXT
    )");

    // Individual check results
    $pdo->exec("CREATE TABLE IF NOT EXISTS checks (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        service_id    INTEGER NOT NULL REFERENCES services(id) ON DELETE CASCADE,
        status        TEXT    NOT NULL,   -- up | down
        response_time INTEGER,            -- milliseconds
        error         TEXT,
        checked_at    INTEGER NOT NULL DEFAULT (strftime('%s','now'))
    )");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_checks_service ON checks(service_id, checked_at DESC)");

    // Alerts / notifications sent
    $pdo->exec("CREATE TABLE IF NOT EXISTS alerts (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        service_id INTEGER NOT NULL REFERENCES services(id) ON DELETE CASCADE,
        type       TEXT    NOT NULL,   -- down | recovery
        message    TEXT,
        sent_at    INTEGER NOT NULL DEFAULT (strftime('%s','now'))
    )");

    // Notification recipients
    $pdo->exec("CREATE TABLE IF NOT EXISTS recipients (
        id    INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL UNIQUE,
        name  TEXT,
        active INTEGER NOT NULL DEFAULT 1
    )");

    // Application settings (key-value)
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        key   TEXT PRIMARY KEY,
        value TEXT
    )");

    // Seed default settings if table is empty
    $count = $pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
    if ($count == 0) {
        $defaults = [
            'smtp_host'     => '',
            'smtp_port'     => '587',
            'smtp_user'     => '',
            'smtp_pass'     => '',
            'smtp_from'     => '',
            'smtp_from_name'=> 'NetMon',
            'smtp_secure'   => 'tls',   // tls | ssl | none
            'check_interval'=> '60',
            'retain_days'   => '30',
            'site_name'     => 'Network Monitor',
            'timezone'      => 'UTC',
        ];
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
        foreach ($defaults as $k => $v) $stmt->execute([$k, $v]);
    }
}

function setting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        $rows  = db_connect()->query("SELECT key, value FROM settings")->fetchAll();
        $cache = array_column($rows, 'value', 'key');
        // Apply timezone globally as soon as settings are first loaded
        $tz = $cache['timezone'] ?? 'UTC';
        if ($tz && @date_default_timezone_set($tz) === false) {
            date_default_timezone_set('UTC');
        }
    }
    return $cache[$key] ?? $default;
}

function setting_set(string $key, string $value): void {
    $pdo = db_connect();
    $pdo->prepare("INSERT INTO settings (key, value) VALUES (?, ?)
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value")
        ->execute([$key, $value]);
}
