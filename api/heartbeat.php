<?php
/**
 * Heartbeat API Endpoint
 *
 * Accepts POST or GET requests from monitored computers.
 *
 * POST /api/heartbeat.php
 *   Headers : X-Api-Key: <key>   (or query param ?api_key=<key>)
 *   Body    : JSON  { "id": "server-01", "hostname": "...", "extra": {} }
 *             or form field: id=server-01&hostname=...
 *
 * GET  /api/heartbeat.php?id=server-01&api_key=<key>
 *
 * Response: 200 {"status":"ok","message":"Heartbeat recorded","id":"server-01"}
 *           401 {"status":"error","message":"Unauthorized"}
 *           400 {"status":"error","message":"Missing computer ID"}
 *           404 {"status":"error","message":"Unknown computer ID"}
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/HeartbeatStore.php';

header('Content-Type: application/json');

// ----- Load config -----------------------------------------------------------

$cfgFile = file_exists(__DIR__ . '/../config.local.php')
    ? __DIR__ . '/../config.local.php'
    : __DIR__ . '/../config.php';

$config = require $cfgFile;

// ----- Authenticate ----------------------------------------------------------

$apiKey = $config['api_key'] ?? null;
if ($apiKey !== null) {
    $provided = $_SERVER['HTTP_X_API_KEY']
        ?? $_GET['api_key']
        ?? null;

    if ($provided === null || !hash_equals((string) $apiKey, (string) $provided)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
}

// ----- Parse input -----------------------------------------------------------

$input = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (str_contains($contentType, 'application/json')) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
} else {
    $input = array_merge($_GET, $_POST);
}

$computerId = trim((string) ($input['id'] ?? ''));
$hostname   = trim((string) ($input['hostname'] ?? ''));
$extra      = is_array($input['extra'] ?? null) ? $input['extra'] : [];

if ($computerId === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing computer ID (field: id)']);
    exit;
}

// ----- Validate against known computers --------------------------------------

if (!isset($config['computers'][$computerId])) {
    http_response_code(404);
    echo json_encode([
        'status'  => 'error',
        'message' => "Unknown computer ID: $computerId",
    ]);
    exit;
}

// ----- Record the beat -------------------------------------------------------

$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR']
    ?? $_SERVER['REMOTE_ADDR']
    ?? '';

// Take only the first IP if X-Forwarded-For contains a chain
$clientIp = trim(explode(',', $clientIp)[0]);

try {
    $store = new HeartbeatStore($config['data_dir']);
    $store->recordBeat($computerId, $clientIp, $hostname, $extra);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Storage error: ' . $e->getMessage()]);
    exit;
}

echo json_encode([
    'status'    => 'ok',
    'message'   => 'Heartbeat recorded',
    'id'        => $computerId,
    'timestamp' => time(),
]);
