<?php
/**
 * Simple SMTP mailer using PHP streams (no external dependencies).
 * Falls back to PHP mail() if SMTP host is not configured.
 */

require_once __DIR__ . '/../db.php';

function send_down_alert(array $service, ?string $error): void {
    $subject = "[ALERT] {$service['name']} is DOWN";
    $body    = build_alert_body($service, 'down', $error);
    send_to_all_recipients($subject, $body);

    db_connect()->prepare("INSERT INTO alerts (service_id, type, message) VALUES (?, 'down', ?)")
        ->execute([$service['id'], $error]);
}

function send_recovery_alert(array $service): void {
    $subject = "[RECOVERY] {$service['name']} is back UP";
    $body    = build_alert_body($service, 'recovery', null);
    send_to_all_recipients($subject, $body);

    db_connect()->prepare("INSERT INTO alerts (service_id, type, message) VALUES (?, 'recovery', 'Service recovered')")
        ->execute([$service['id']]);
}

function build_alert_body(array $service, string $type, ?string $error): string {
    $siteName = setting('site_name', 'Network Monitor');
    $time     = date('Y-m-d H:i:s T');
    $host     = htmlspecialchars($service['host']);
    $name     = htmlspecialchars($service['name']);

    if ($type === 'down') {
        $icon   = '🔴';
        $status = 'DOWN';
        $detail = $error ? "Error: " . htmlspecialchars($error) : '';
    } else {
        $icon   = '🟢';
        $status = 'RECOVERED';
        $detail = 'The service is now responding normally.';
    }

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">
  <h2 style="color:{'.$type==='down'?'#dc3545':'#28a745'.'}">{$icon} {$siteName} Alert</h2>
  <table style="width:100%;border-collapse:collapse">
    <tr><td style="padding:8px;border:1px solid #ddd;font-weight:bold">Service</td><td style="padding:8px;border:1px solid #ddd">{$name}</td></tr>
    <tr><td style="padding:8px;border:1px solid #ddd;font-weight:bold">Host</td><td style="padding:8px;border:1px solid #ddd">{$host}</td></tr>
    <tr><td style="padding:8px;border:1px solid #ddd;font-weight:bold">Status</td><td style="padding:8px;border:1px solid #ddd"><strong>{$status}</strong></td></tr>
    <tr><td style="padding:8px;border:1px solid #ddd;font-weight:bold">Time</td><td style="padding:8px;border:1px solid #ddd">{$time}</td></tr>
    <tr><td style="padding:8px;border:1px solid #ddd;font-weight:bold">Detail</td><td style="padding:8px;border:1px solid #ddd">{$detail}</td></tr>
  </table>
  <p style="color:#666;font-size:12px;margin-top:20px">Sent by {$siteName}</p>
</body>
</html>
HTML;
}

function send_to_all_recipients(string $subject, string $body): void {
    $recipients = db_connect()
        ->query("SELECT email, name FROM recipients WHERE active=1")
        ->fetchAll();

    foreach ($recipients as $r) {
        send_mail($r['email'], $r['name'] ?? '', $subject, $body);
    }
}

function send_mail(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    $host     = setting('smtp_host');
    $port     = (int) setting('smtp_port', '587');
    $user     = setting('smtp_user');
    $pass     = setting('smtp_pass');
    $from     = setting('smtp_from');
    $fromName = setting('smtp_from_name', 'NetMon');
    $secure   = setting('smtp_secure', 'tls');

    if (empty($host) || empty($from)) {
        // Fallback: PHP mail()
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$fromName} <{$from}>\r\n";
        return mail($toEmail, $subject, $htmlBody, $headers);
    }

    return smtp_send($host, $port, $secure, $user, $pass, $from, $fromName, $toEmail, $toName, $subject, $htmlBody);
}

function smtp_send(
    string $host, int $port, string $secure,
    string $user, string $pass,
    string $from, string $fromName,
    string $toEmail, string $toName,
    string $subject, string $htmlBody
): bool {
    $transport = match ($secure) {
        'ssl'  => 'ssl',
        'tls'  => 'tcp',
        default => 'tcp',
    };

    $fp = @stream_socket_client(
        "{$transport}://{$host}:{$port}",
        $errno, $errstr, 15,
        STREAM_CLIENT_CONNECT
    );

    if (!$fp) {
        error_log("NetMon mailer: cannot connect to {$host}:{$port} – {$errstr}");
        return false;
    }

    stream_set_timeout($fp, 15);

    $read = function() use ($fp): string {
        $buf = '';
        while (!feof($fp)) {
            $line = fgets($fp, 512);
            $buf .= $line;
            if (strlen($line) >= 4 && $line[3] === ' ') break; // last line of response
        }
        return $buf;
    };

    $cmd = function(string $c) use ($fp, $read): string {
        fwrite($fp, $c . "\r\n");
        return $read();
    };

    $read(); // banner
    $cmd("EHLO " . gethostname());

    if ($secure === 'tls') {
        $cmd("STARTTLS");
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return false;
        }
        $cmd("EHLO " . gethostname());
    }

    if ($user && $pass) {
        $cmd("AUTH LOGIN");
        $cmd(base64_encode($user));
        $r = $cmd(base64_encode($pass));
        if (strpos($r, '235') === false) {
            error_log("NetMon mailer: AUTH failed");
            fclose($fp);
            return false;
        }
    }

    $cmd("MAIL FROM:<{$from}>");
    $cmd("RCPT TO:<{$toEmail}>");
    $cmd("DATA");

    $boundary = md5(uniqid());
    $headers  = implode("\r\n", [
        "Date: "    . date('r'),
        "From: "    . mb_encode_mimeheader($fromName, 'UTF-8') . " <{$from}>",
        "To: "      . ($toName ? mb_encode_mimeheader($toName, 'UTF-8') . " <{$toEmail}>" : $toEmail),
        "Subject: " . mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n"),
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8",
        "Content-Transfer-Encoding: base64",
    ]);

    $body = $headers . "\r\n\r\n" . chunk_split(base64_encode($htmlBody)) . "\r\n.\r\n";
    $r    = $cmd($body);

    $cmd("QUIT");
    fclose($fp);

    return strpos($r, '250') !== false;
}
