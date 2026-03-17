#!/usr/bin/env php
<?php
/**
 * heartbeat.php – Send a heartbeat to the Heartbeat Monitor server.
 *
 * Usage (CLI):
 *   php heartbeat.php
 *   MONITOR_URL=http://... COMPUTER_ID=my-server php heartbeat.php
 *
 * Add to crontab (every 5 minutes):
 *   * /5 * * * * /usr/bin/php /path/to/heartbeat.php >> /var/log/heartbeat.log 2>&1
 *
 * Requires: PHP 7.4+, curl extension (usually enabled by default).
 */

declare(strict_types=1);

// =============================================================================
// Configuration – edit here or set environment variables
// =============================================================================

$monitorUrl  = getenv('MONITOR_URL')  ?: 'http://your-monitor-server.example.com/api/heartbeat.php';
$apiKey      = getenv('API_KEY')      ?: 'change-me-to-a-strong-secret';
$computerId  = getenv('COMPUTER_ID')  ?: gethostname();
$hostname    = getenv('HOSTNAME_VAL') ?: (php_uname('n') ?: gethostname());

$maxRetries  = 3;
$retryDelay  = 5;   // seconds
$timeout     = 10;  // seconds

// =============================================================================
// Collect optional system metadata
// =============================================================================

$extra = ['php_version' => PHP_VERSION, 'os' => PHP_OS];

// Uptime (Linux)
if (is_readable('/proc/uptime')) {
    $uptimeSec  = (int) explode(' ', file_get_contents('/proc/uptime'))[0];
    $days       = intdiv($uptimeSec, 86400);
    $hours      = intdiv($uptimeSec % 86400, 3600);
    $mins       = intdiv($uptimeSec % 3600, 60);
    $extra['uptime'] = "{$days}d {$hours}h {$mins}m";
}

// Load average (Unix)
if (function_exists('sys_getloadavg')) {
    $load = sys_getloadavg();
    $extra['load'] = sprintf('%.2f %.2f %.2f', $load[0], $load[1], $load[2]);
}

// =============================================================================
// Send with retries
// =============================================================================

$payload = json_encode([
    'id'       => $computerId,
    'hostname' => $hostname,
    'extra'    => $extra,
]);

$success = false;
for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
    if (sendHeartbeat($monitorUrl, $apiKey, $payload, $timeout)) {
        $success = true;
        break;
    }
    if ($attempt < $maxRetries) {
        logMsg("WARN", "Attempt $attempt failed. Retrying in {$retryDelay}s…");
        sleep($retryDelay);
    }
}

if (!$success) {
    logMsg("ERROR", "All $maxRetries attempts failed for '$computerId'.");
    exit(1);
}

exit(0);

// =============================================================================
// Helpers
// =============================================================================

function sendHeartbeat(string $url, string $apiKey, string $payload, int $timeout): bool
{
    if (function_exists('curl_init')) {
        return sendWithCurl($url, $apiKey, $payload, $timeout);
    }
    return sendWithStream($url, $apiKey, $payload, $timeout);
}

function sendWithCurl(string $url, string $apiKey, string $payload, int $timeout): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "X-Api-Key: $apiKey",
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error) {
        logMsg("ERROR", "cURL error: $error");
        return false;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        logMsg("ERROR", "HTTP $httpCode: " . trim((string) $response));
        return false;
    }

    global $computerId;
    logMsg("OK", "Heartbeat sent for '$computerId'. Response: " . trim((string) $response));
    return true;
}

function sendWithStream(string $url, string $apiKey, string $payload, int $timeout): bool
{
    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nX-Api-Key: $apiKey",
            'content'       => $payload,
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        logMsg("ERROR", "Stream request failed.");
        return false;
    }

    $statusLine = $http_response_header[0] ?? '';
    if (!preg_match('/\s2\d\d\s/', $statusLine)) {
        logMsg("ERROR", "HTTP error: $statusLine - " . trim($response));
        return false;
    }

    global $computerId;
    logMsg("OK", "Heartbeat sent for '$computerId'. Response: " . trim($response));
    return true;
}

function logMsg(string $level, string $msg): void
{
    echo date('[Y-m-d H:i:s]') . " [$level] $msg\n";
}
