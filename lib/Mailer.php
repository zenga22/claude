<?php
/**
 * Mailer
 *
 * Lightweight email sender with two drivers:
 *   - 'mail'  : Uses PHP's built-in mail() function.
 *   - 'smtp'  : Direct SMTP socket connection (no dependencies).
 *
 * Supports TLS (STARTTLS on port 587) and SSL (port 465).
 */
class Mailer
{
    private array $cfg;

    public function __construct(array $emailConfig)
    {
        $this->cfg = $emailConfig;
    }

    /**
     * Send a plain-text + HTML email.
     *
     * @param string   $subject
     * @param string   $textBody  Plain-text version
     * @param string   $htmlBody  HTML version (optional; falls back to text)
     * @throws \RuntimeException on failure
     */
    public function send(string $subject, string $textBody, string $htmlBody = ''): void
    {
        if (empty($this->cfg['enabled'])) {
            return;
        }

        $to      = (array) ($this->cfg['to'] ?? []);
        $from    = $this->cfg['from_address'] ?? 'heartbeat@localhost';
        $fromName = $this->cfg['from_name']   ?? 'Heartbeat Monitor';
        $prefix  = $this->cfg['subject_prefix'] ?? '[Heartbeat]';
        $subject = trim("$prefix $subject");

        if (empty($to)) {
            throw new \RuntimeException('No recipients configured.');
        }

        if (empty($htmlBody)) {
            $htmlBody = nl2br(htmlspecialchars($textBody, ENT_QUOTES));
        }

        $boundary = 'hbm_' . bin2hex(random_bytes(8));
        $headers  = $this->buildHeaders($from, $fromName, $boundary);
        $body     = $this->buildBody($textBody, $htmlBody, $boundary);

        $driver = $this->cfg['driver'] ?? 'mail';

        if ($driver === 'smtp') {
            $this->sendSmtp($to, $from, $fromName, $subject, $headers, $body);
        } else {
            $this->sendMail($to, $from, $fromName, $subject, $headers, $body);
        }
    }

    // -------------------------------------------------------------------------
    // Drivers
    // -------------------------------------------------------------------------

    private function sendMail(array $to, string $from, string $fromName, string $subject, string $headers, string $body): void
    {
        $toStr = implode(', ', $to);
        $result = mail($toStr, $subject, $body, $headers);
        if (!$result) {
            throw new \RuntimeException('mail() returned false.');
        }
    }

    private function sendSmtp(array $to, string $from, string $fromName, string $subject, string $headers, string $body): void
    {
        $host       = $this->cfg['smtp_host']       ?? 'localhost';
        $port       = (int) ($this->cfg['smtp_port']       ?? 587);
        $encryption = $this->cfg['smtp_encryption'] ?? 'tls';
        $user       = $this->cfg['smtp_user']       ?? '';
        $pass       = $this->cfg['smtp_pass']       ?? '';

        // Choose socket wrapper
        if ($encryption === 'ssl') {
            $target = "ssl://$host:$port";
        } else {
            $target = "tcp://$host:$port";
        }

        $errno = 0; $errstr = '';
        $sock = @stream_socket_client($target, $errno, $errstr, 15);
        if (!$sock) {
            throw new \RuntimeException("SMTP connect failed ($errno): $errstr");
        }
        stream_set_timeout($sock, 15);

        $this->smtpExpect($sock, '220');

        // EHLO
        $this->smtpSend($sock, "EHLO " . gethostname());
        $ehlo = $this->smtpRead($sock);
        if (!str_starts_with($ehlo, '2')) {
            throw new \RuntimeException("EHLO failed: $ehlo");
        }

        // STARTTLS upgrade
        if ($encryption === 'tls') {
            $this->smtpSend($sock, "STARTTLS");
            $this->smtpExpect($sock, '220');
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('STARTTLS upgrade failed.');
            }
            // Re-EHLO after TLS
            $this->smtpSend($sock, "EHLO " . gethostname());
            $this->smtpRead($sock);
        }

        // AUTH LOGIN
        if ($user !== '') {
            $this->smtpSend($sock, "AUTH LOGIN");
            $this->smtpExpect($sock, '334');
            $this->smtpSend($sock, base64_encode($user));
            $this->smtpExpect($sock, '334');
            $this->smtpSend($sock, base64_encode($pass));
            $this->smtpExpect($sock, '235');
        }

        // MAIL FROM
        $this->smtpSend($sock, "MAIL FROM:<$from>");
        $this->smtpExpect($sock, '250');

        // RCPT TO
        foreach ($to as $recipient) {
            $this->smtpSend($sock, "RCPT TO:<$recipient>");
            $this->smtpExpect($sock, '25');
        }

        // DATA
        $this->smtpSend($sock, "DATA");
        $this->smtpExpect($sock, '354');

        // Build full message (headers + blank line + body)
        $toHeader    = "To: " . implode(', ', $to) . "\r\n";
        $fromHeader  = "From: " . $this->encodeHeader($fromName) . " <$from>\r\n";
        $subjHeader  = "Subject: " . $this->encodeHeader($subject) . "\r\n";
        $message     = $fromHeader . $toHeader . $subjHeader . $headers . "\r\n" . $body;

        // Dot-stuff lines that start with '.'
        $message = preg_replace('/^\.$/m', '..', $message);
        $this->smtpSend($sock, $message . "\r\n.");
        $this->smtpExpect($sock, '250');

        $this->smtpSend($sock, "QUIT");
        fclose($sock);
    }

    // -------------------------------------------------------------------------
    // SMTP low-level helpers
    // -------------------------------------------------------------------------

    private function smtpSend($sock, string $data): void
    {
        fwrite($sock, $data . "\r\n");
    }

    private function smtpRead($sock): string
    {
        $response = '';
        while ($line = fgets($sock, 512)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break; // final line of multi-line response
            }
        }
        return trim($response);
    }

    private function smtpExpect($sock, string $expectedCode): void
    {
        $response = $this->smtpRead($sock);
        if (!str_starts_with($response, $expectedCode)) {
            throw new \RuntimeException("SMTP unexpected response (expected $expectedCode): $response");
        }
    }

    // -------------------------------------------------------------------------
    // MIME helpers
    // -------------------------------------------------------------------------

    private function buildHeaders(string $from, string $fromName, string $boundary): string
    {
        $encodedFrom = $this->encodeHeader($fromName);
        return implode("\r\n", [
            "MIME-Version: 1.0",
            "Content-Type: multipart/alternative; boundary=\"$boundary\"",
            "From: $encodedFrom <$from>",
            "X-Mailer: HeartbeatMonitor/1.0",
        ]) . "\r\n";
    }

    private function buildBody(string $text, string $html, string $boundary): string
    {
        $body  = "--$boundary\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($text) . "\r\n";

        $body .= "--$boundary\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
        $body .= quoted_printable_encode($html) . "\r\n";

        $body .= "--$boundary--\r\n";
        return $body;
    }

    private function encodeHeader(string $value): string
    {
        if (mb_detect_encoding($value, 'ASCII', true)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
