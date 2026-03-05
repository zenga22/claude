<?php
/**
 * Lightweight API / action endpoint.
 * Used by the web UI for: manual checks, run-all, AJAX status polling.
 *
 * Routes (GET params):
 *   ?action=check&id=N          — run a single service check, redirect back
 *   ?action=check_all            — run all enabled services, redirect back
 *   ?action=status               — JSON status of all services (for polling)
 *   ?action=service_status&id=N  — JSON status of one service
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mailer.php';

$action = $_GET['action'] ?? '';
$id     = (int)($_GET['id'] ?? 0);
$ref    = $_SERVER['HTTP_REFERER'] ?? 'index.php';

function json_out(mixed $data): never {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function redirect_back(string $fallback = 'index.php'): never {
    $ref = $_SERVER['HTTP_REFERER'] ?? $fallback;
    // Safety: only redirect to same origin
    header('Location: ' . $ref);
    exit;
}

// ── action=check ─────────────────────────────────────────────────────────────
if ($action === 'check') {
    if (!$id) redirect_back();
    $pdo  = db_connect();
    $stmt = $pdo->prepare("SELECT * FROM services WHERE id=?");
    $stmt->execute([$id]);
    $svc = $stmt->fetch();
    if (!$svc) {
        session_start();
        $_SESSION['flash'][] = ['msg' => 'Service not found.', 'type' => 'danger'];
        redirect_back();
    }
    run_check($svc);
    session_start();
    $_SESSION['flash'][] = ['msg' => "Check run for '{$svc['name']}'.", 'type' => 'success'];
    redirect_back();
}

// ── action=check_all ─────────────────────────────────────────────────────────
if ($action === 'check_all') {
    $pdo      = db_connect();
    $services = $pdo->query("SELECT * FROM services WHERE enabled=1")->fetchAll();
    foreach ($services as $svc) {
        run_check($svc);
    }
    session_start();
    $_SESSION['flash'][] = ['msg' => 'All checks completed (' . count($services) . ' services).', 'type' => 'success'];
    redirect_back();
}

// ── action=status (JSON) ─────────────────────────────────────────────────────
if ($action === 'status') {
    $services = db_connect()->query("SELECT id, name, type, host, port, last_status, last_check, fail_count, enabled FROM services")->fetchAll();
    $out = [];
    foreach ($services as $s) {
        $out[] = [
            'id'          => $s['id'],
            'name'        => $s['name'],
            'status'      => $s['last_status'] ?? 'unknown',
            'last_check'  => $s['last_check'] ? date('H:i:s', $s['last_check']) : null,
            'fail_count'  => $s['fail_count'],
            'uptime_24h'  => uptime_percent((int)$s['id'], 24),
            'avg_rt_24h'  => avg_response_time((int)$s['id'], 24),
        ];
    }
    json_out(['services' => $out, 'ts' => time()]);
}

// ── action=service_status (JSON) ─────────────────────────────────────────────
if ($action === 'service_status' && $id) {
    $pdo  = db_connect();
    $stmt = $pdo->prepare("SELECT * FROM services WHERE id=?");
    $stmt->execute([$id]);
    $s = $stmt->fetch();
    if (!$s) json_out(['error' => 'Not found']);

    $checks = recent_checks($id, 20);
    json_out([
        'id'         => $s['id'],
        'name'       => $s['name'],
        'status'     => $s['last_status'],
        'last_check' => $s['last_check'],
        'fail_count' => $s['fail_count'],
        'uptime_24h' => uptime_percent($id, 24),
        'avg_rt_24h' => avg_response_time($id, 24),
        'recent'     => $checks,
    ]);
}

// ── Fallback ─────────────────────────────────────────────────────────────────
http_response_code(400);
json_out(['error' => 'Unknown action']);
