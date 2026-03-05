<?php
/**
 * Core monitoring functions: HTTP, TCP, and ICMP checks.
 */

require_once __DIR__ . '/../db.php';

/**
 * Run a check for the given service row and persist the result.
 * Returns the result array: ['status'=>'up'|'down', 'response_time'=>ms, 'error'=>string|null]
 */
function run_check(array $service): array {
    $start  = microtime(true);
    $result = match ($service['type']) {
        'http'  => check_http($service),
        'tcp'   => check_tcp($service),
        'ping'  => check_ping($service),
        default => ['status' => 'down', 'error' => 'Unknown check type'],
    };
    $result['response_time'] = (int) round((microtime(true) - $start) * 1000);

    save_check_result($service, $result);
    return $result;
}

function check_http(array $service): array {
    $scheme  = $service['port'] == 443 ? 'https' : 'http';
    $host    = $service['host'];
    $port    = $service['port'] ?: ($scheme === 'https' ? 443 : 80);
    $path    = $service['path'] ?: '/';
    $url     = "{$scheme}://{$host}:{$port}{$path}";

    $ctx = stream_context_create([
        'http' => [
            'timeout'          => (int) $service['timeout'],
            'follow_location'  => 1,
            'max_redirects'    => 5,
            'ignore_errors'    => true,
            'user_agent'       => 'NetMon/1.0',
            'method'           => 'HEAD',
        ],
        'ssl'  => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);

    $result = @file_get_contents($url, false, $ctx);

    if ($result === false && empty($http_response_header)) {
        return ['status' => 'down', 'error' => 'Connection failed'];
    }

    // Parse status code from response headers
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $m);
    $code = (int) ($m[1] ?? 0);

    if ($code >= 200 && $code < 400) {
        return ['status' => 'up', 'error' => null];
    }
    return ['status' => 'down', 'error' => "HTTP {$code}"];
}

function check_tcp(array $service): array {
    $port = (int) ($service['port'] ?? 0);
    if ($port <= 0 || $port > 65535) {
        return ['status' => 'down', 'error' => 'Invalid port'];
    }

    $fp = @fsockopen($service['host'], $port, $errno, $errstr, (int) $service['timeout']);
    if ($fp === false) {
        return ['status' => 'down', 'error' => $errstr ?: "TCP connect failed (err {$errno})"];
    }
    fclose($fp);
    return ['status' => 'up', 'error' => null];
}

function check_ping(array $service): array {
    $host = escapeshellarg($service['host']);
    $timeout = (int) $service['timeout'];

    // Linux / macOS compatible
    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = "ping -n 1 -w " . ($timeout * 1000) . " {$host} 2>&1";
    } else {
        $cmd = "ping -c 1 -W {$timeout} {$host} 2>&1";
    }

    exec($cmd, $output, $code);
    if ($code === 0) {
        return ['status' => 'up', 'error' => null];
    }
    return ['status' => 'down', 'error' => 'Host unreachable'];
}

/**
 * Persist a check result, update service state, trigger alerts as needed.
 */
function save_check_result(array $service, array $result): void {
    $pdo  = db_connect();
    $now  = time();

    // Insert check record
    $pdo->prepare("INSERT INTO checks (service_id, status, response_time, error, checked_at)
                   VALUES (?, ?, ?, ?, ?)")
        ->execute([$service['id'], $result['status'], $result['response_time'], $result['error'], $now]);

    // Update service counters
    if ($result['status'] === 'up') {
        $wasDown = ($service['last_status'] === 'down' && $service['alert_sent']);
        $pdo->prepare("UPDATE services
                       SET last_status=?, last_check=?, fail_count=0, alert_sent=0
                       WHERE id=?")
            ->execute(['up', $now, $service['id']]);

        if ($wasDown) {
            send_recovery_alert($service);
        }
    } else {
        $newFail = $service['fail_count'] + 1;
        $pdo->prepare("UPDATE services
                       SET last_status=?, last_check=?, fail_count=?, alert_sent=CASE WHEN ? >= threshold THEN 1 ELSE alert_sent END
                       WHERE id=?")
            ->execute(['down', $now, $newFail, $newFail, $service['id']]);

        if ($newFail >= $service['threshold'] && !$service['alert_sent']) {
            send_down_alert($service, $result['error']);
        }
    }
}

/**
 * Return all services that are due for a check.
 */
function get_services_due(): array {
    return db_connect()
        ->query("SELECT * FROM services
                 WHERE enabled=1
                   AND (last_check IS NULL OR last_check + interval <= strftime('%s','now'))")
        ->fetchAll();
}

function format_uptime(int $serviceId): string {
    $pdo   = db_connect();
    $total = (int) $pdo->prepare("SELECT COUNT(*) FROM checks WHERE service_id=?")
                       ->execute([$serviceId]) ? $pdo->query("SELECT COUNT(*) FROM checks WHERE service_id={$serviceId}")->fetchColumn() : 0;
    if ($total === 0) return 'N/A';
    $up = (int) $pdo->query("SELECT COUNT(*) FROM checks WHERE service_id={$serviceId} AND status='up'")->fetchColumn();
    return number_format(($up / $total) * 100, 2) . '%';
}

function recent_checks(int $serviceId, int $limit = 50): array {
    $pdo  = db_connect();
    $stmt = $pdo->prepare("SELECT * FROM checks WHERE service_id=? ORDER BY checked_at DESC LIMIT ?");
    $stmt->execute([$serviceId, $limit]);
    return $stmt->fetchAll();
}

function purge_old_checks(int $retainDays): int {
    $cutoff = time() - ($retainDays * 86400);
    $pdo    = db_connect();
    $stmt   = $pdo->prepare("DELETE FROM checks WHERE checked_at < ?");
    $stmt->execute([$cutoff]);
    return $stmt->rowCount();
}

function uptime_percent(int $serviceId, int $hours = 24): float {
    $since = time() - ($hours * 3600);
    $pdo   = db_connect();
    $total = (int) $pdo->prepare("SELECT COUNT(*) FROM checks WHERE service_id=? AND checked_at>=?")->execute([$serviceId, $since]) ? 0 : 0;
    $stmt  = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status='up' THEN 1 ELSE 0 END) as up_count FROM checks WHERE service_id=? AND checked_at>=?");
    $stmt->execute([$serviceId, $since]);
    $row = $stmt->fetch();
    if (!$row || $row['total'] == 0) return 0.0;
    return round(($row['up_count'] / $row['total']) * 100, 2);
}

function avg_response_time(int $serviceId, int $hours = 24): ?int {
    $since = time() - ($hours * 3600);
    $pdo   = db_connect();
    $stmt  = $pdo->prepare("SELECT AVG(response_time) FROM checks WHERE service_id=? AND status='up' AND checked_at>=?");
    $stmt->execute([$serviceId, $since]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (int) $val : null;
}
