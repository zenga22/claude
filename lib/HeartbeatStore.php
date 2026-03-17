<?php
/**
 * HeartbeatStore
 *
 * File-based JSON storage for heartbeat records and alert state.
 * No database required – all state lives in data/heartbeats.json.
 *
 * Record schema per computer ID:
 * {
 *   "last_seen"       : <unix timestamp|null>,
 *   "last_alert_sent" : <unix timestamp|null>,
 *   "alert_count"     : <int>,
 *   "miss_count"      : <int>,   // consecutive missed beats
 *   "status"          : "ok"|"late"|"down"|"unknown",
 *   "ip"              : "<string>",
 *   "hostname"        : "<string>",
 *   "extra"           : {}        // arbitrary client-supplied metadata
 * }
 */
class HeartbeatStore
{
    private string $file;
    private array  $data = [];

    public function __construct(string $dataDir)
    {
        if (!is_dir($dataDir) && !mkdir($dataDir, 0755, true)) {
            throw new \RuntimeException("Cannot create data directory: $dataDir");
        }
        $this->file = rtrim($dataDir, '/') . '/heartbeats.json';
        $this->load();
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Record a received heartbeat for $computerId.
     */
    public function recordBeat(string $computerId, string $ip = '', string $hostname = '', array $extra = []): void
    {
        $now = time();
        $rec = $this->get($computerId);

        $rec['last_seen']  = $now;
        $rec['status']     = 'ok';
        $rec['miss_count'] = 0;
        $rec['ip']         = $ip ?: $rec['ip'];
        $rec['hostname']   = $hostname ?: $rec['hostname'];
        if ($extra) {
            $rec['extra'] = array_merge($rec['extra'] ?? [], $extra);
        }

        $this->data[$computerId] = $rec;
        $this->save();
    }

    /**
     * Return the stored record for $computerId (never null).
     */
    public function get(string $computerId): array
    {
        return $this->data[$computerId] ?? $this->defaultRecord();
    }

    /**
     * Return all stored records.
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Update the alert state after an alert has been dispatched.
     */
    public function recordAlertSent(string $computerId): void
    {
        $rec = $this->get($computerId);
        $rec['last_alert_sent'] = time();
        $rec['alert_count']     = ($rec['alert_count'] ?? 0) + 1;
        $this->data[$computerId] = $rec;
        $this->save();
    }

    /**
     * Mark a computer as currently down/late and increment miss counter.
     */
    public function recordMiss(string $computerId): void
    {
        $rec = $this->get($computerId);
        $rec['miss_count'] = ($rec['miss_count'] ?? 0) + 1;
        $rec['status']     = $rec['miss_count'] >= 3 ? 'down' : 'late';
        $this->data[$computerId] = $rec;
        $this->save();
    }

    /**
     * Evaluate whether an alert should be sent for $computerId given its config.
     * Returns true when: the beat is overdue AND the cooldown has expired.
     */
    public function shouldAlert(string $computerId, array $cfg, int $cooldown): bool
    {
        $rec = $this->get($computerId);

        // Never seen → treat as overdue if the computer is configured
        $lastSeen = $rec['last_seen'] ?? null;
        $deadline = $lastSeen !== null
            ? $lastSeen + $cfg['interval'] + $cfg['grace']
            : time() - 1; // immediately overdue if never seen

        if (time() < $deadline) {
            return false; // still within window
        }

        // Check consecutive miss threshold
        if (($rec['miss_count'] ?? 0) < ($cfg['alert_after'] ?? 1)) {
            return false;
        }

        // Respect cooldown
        $lastAlert = $rec['last_alert_sent'] ?? null;
        if ($lastAlert !== null && (time() - $lastAlert) < $cooldown) {
            return false;
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function defaultRecord(): array
    {
        return [
            'last_seen'       => null,
            'last_alert_sent' => null,
            'alert_count'     => 0,
            'miss_count'      => 0,
            'status'          => 'unknown',
            'ip'              => '',
            'hostname'        => '',
            'extra'           => [],
        ];
    }

    private function load(): void
    {
        if (!file_exists($this->file)) {
            $this->data = [];
            return;
        }
        $json = file_get_contents($this->file);
        $this->data = json_decode($json, true) ?: [];
    }

    private function save(): void
    {
        $tmp = $this->file . '.tmp';
        file_put_contents($tmp, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        rename($tmp, $this->file);
    }
}
