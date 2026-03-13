<?php
require_once __DIR__ . '/config.php';

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dir = dirname(DB_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $pdo = new PDO('sqlite:' . DB_PATH);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        init_db($pdo);
    }
    return $pdo;
}

function init_db(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS files (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            filename      TEXT    UNIQUE NOT NULL,
            date_submitted TEXT,
            name          TEXT,
            email         TEXT,
            synced_at     DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
}

function sync_json_files(): array {
    $pdo = get_db();
    $added = [];
    $skipped = 0;

    $json_files = glob(JSON_DIR . '/*.json');
    if ($json_files === false) {
        return ['added' => [], 'skipped' => 0, 'error' => 'Could not read JSON directory.'];
    }

    $stmt_check  = $pdo->prepare("SELECT id FROM files WHERE filename = ?");
    $stmt_insert = $pdo->prepare("
        INSERT INTO files (filename, date_submitted, name, email)
        VALUES (?, ?, ?, ?)
    ");

    foreach ($json_files as $filepath) {
        $filename = basename($filepath);

        $stmt_check->execute([$filename]);
        if ($stmt_check->fetch()) {
            $skipped++;
            continue;
        }

        $raw = file_get_contents($filepath);
        $data = json_decode($raw, true);
        if ($data === null) {
            continue; // skip malformed JSON
        }

        $date_submitted = $data['date-submitted'] ?? $data['date_submitted'] ?? null;
        $name           = $data['name']           ?? null;
        $email          = $data['email']          ?? $data['email_address'] ?? $data['email-address'] ?? null;

        $stmt_insert->execute([$filename, $date_submitted, $name, $email]);
        $added[] = $filename;
    }

    return ['added' => $added, 'skipped' => $skipped];
}

function get_all_files(): array {
    $pdo  = get_db();
    $stmt = $pdo->query("SELECT * FROM files ORDER BY date_submitted DESC, id DESC");
    return $stmt->fetchAll();
}

function get_file_record(int $id): ?array {
    $pdo  = get_db();
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}
