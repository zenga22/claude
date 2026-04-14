<?php
declare(strict_types=1);

/**
 * ICS Calendar Export – RFC 5545
 * PHP 7.4+ | Timezone: America/Denver
 *
 * Usage: calendar_export.php?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD
 */

// ─── Database Configuration ────────────────────────────────────────────────────
$db_config = [
    'host'  => 'localhost',
    'name'  => 'your_database',
    'user'  => 'your_username',
    'pass'  => 'your_password',
    'table' => 'events',          // table name
];

// ─── Calendar Configuration ────────────────────────────────────────────────────
define('TIMEZONE',  'America/Denver');
define('PROD_ID',   '-//Your Organization//Event Calendar//EN');
define('CAL_NAME',  'Events Calendar');

// ─── Validate Input ────────────────────────────────────────────────────────────
function is_valid_date(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d !== false && $d->format('Y-m-d') === $date;
}

$start = trim($_GET['start_date'] ?? '');
$end   = trim($_GET['end_date']   ?? '');

if (!$start || !$end || !is_valid_date($start) || !is_valid_date($end)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Error: Provide valid query parameters.\n"
       . "Usage: ?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD");
}

if ($start > $end) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Error: start_date must not be later than end_date.");
}

// ─── Database ──────────────────────────────────────────────────────────────────
try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $db_config['host'],
        $db_config['name']
    );
    $pdo = new PDO($dsn, $db_config['user'], $db_config['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Error: Database connection failed.");
}

$table = $db_config['table'];
$sql   = "SELECT * FROM `{$table}` WHERE DATE(`time`) BETWEEN :start AND :end ORDER BY `time` ASC";
$stmt  = $pdo->prepare($sql);
$stmt->execute([':start' => $start, ':end' => $end]);
$events = $stmt->fetchAll();

// ─── ICS Helpers ───────────────────────────────────────────────────────────────

/**
 * Fold a content line to ≤75 octets per RFC 5545 §3.1.
 * Folds at UTF-8 character boundaries to avoid splitting multi-byte sequences.
 */
function ics_fold(string $line): string
{
    $out   = '';
    $len   = mb_strlen($line, 'UTF-8');
    $pos   = 0;
    $width = 75;

    while ($pos < $len) {
        if ($pos > 0) {
            $out   .= "\r\n ";   // CRLF + 1 whitespace (counts as 1 octet)
            $width  = 74;        // remaining budget on continuation lines
        }
        $out .= mb_substr($line, $pos, $width, 'UTF-8');
        $pos += $width;
    }
    return $out;
}

/**
 * Emit a single folded, CRLF-terminated property line.
 *
 * Example: prop('DTSTART', '20260401T100000', ['TZID' => 'America/Denver'])
 *   → "DTSTART;TZID=America/Denver:20260401T100000\r\n"
 *
 * @param array<string,string> $params
 */
function prop(string $name, string $value, array $params = []): string
{
    $param_str = '';
    foreach ($params as $k => $v) {
        $param_str .= ";{$k}={$v}";
    }
    return ics_fold("{$name}{$param_str}:{$value}") . "\r\n";
}

/**
 * Escape TEXT property values per RFC 5545 §3.3.11.
 * Escapes: \ ; , and line breaks.
 */
function ics_escape(string $value): string
{
    $value = str_replace('\\',               '\\\\', $value);
    $value = str_replace(';',                '\\;',  $value);
    $value = str_replace(',',                '\\,',  $value);
    $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);
    return $value;
}

/**
 * Format a MySQL DATETIME string (assumed America/Denver) as a local ICS datetime.
 * Output: YYYYMMDDTHHMMSS
 */
function dt_local(string $mysql_dt): string
{
    $d = new DateTime($mysql_dt, new DateTimeZone(TIMEZONE));
    return $d->format('Ymd\THis');
}

/**
 * Format a MySQL DATETIME string (assumed America/Denver) as a UTC ICS datetime.
 * Output: YYYYMMDDTHHMMSSZ
 */
function dt_utc(string $mysql_dt): string
{
    $d = new DateTime($mysql_dt, new DateTimeZone(TIMEZONE));
    $d->setTimezone(new DateTimeZone('UTC'));
    return $d->format('Ymd\THis\Z');
}

/**
 * Compute DTEND by adding a MySQL TIME duration (HH:MM:SS) to a MySQL DATETIME
 * start value, both interpreted as America/Denver local time.
 * Output: YYYYMMDDTHHMMSS
 */
function dt_end(string $mysql_start, string $mysql_duration): string
{
    [$h, $m, $s] = array_map('intval', explode(':', $mysql_duration));
    $d = new DateTime($mysql_start, new DateTimeZone(TIMEZONE));
    $d->add(new DateInterval(sprintf('PT%dH%dM%dS', $h, $m, $s)));
    return $d->format('Ymd\THis');
}

/**
 * Map a free-form status string to a valid RFC 5545 VEVENT STATUS value.
 * Unrecognized values default to CONFIRMED.
 */
function to_status(string $status): string
{
    static $map = [
        'confirmed' => 'CONFIRMED',
        'tentative' => 'TENTATIVE',
        'cancelled' => 'CANCELLED',
        'canceled'  => 'CANCELLED',
    ];
    return $map[strtolower(trim($status))] ?? 'CONFIRMED';
}

/**
 * Combine the venue columns into a single LOCATION string.
 */
function build_location(array $row): string
{
    $parts = array_filter([
        $row['venue_name'],
        $row['venue_address_1'],
        $row['venue_city'],
        $row['venue_state'],
        $row['venue_zip'],
    ], fn(string $v): bool => $v !== '');

    return implode(', ', $parts);
}

// ─── Build ICS Document ────────────────────────────────────────────────────────
$cal  = "BEGIN:VCALENDAR\r\n";
$cal .= prop('VERSION',       '2.0');
$cal .= prop('PRODID',        PROD_ID);
$cal .= prop('CALSCALE',      'GREGORIAN');
$cal .= prop('METHOD',        'PUBLISH');
$cal .= prop('X-WR-CALNAME',  ics_escape(CAL_NAME));
$cal .= prop('X-WR-TIMEZONE', TIMEZONE);

// ── VTIMEZONE: America/Denver ─────────────────────────────────────────────────
// DST rules follow the US Energy Policy Act of 2007:
//   Daylight: 2nd Sunday in March    @ 02:00 local  (UTC−7 → UTC−6)
//   Standard: 1st Sunday in November @ 02:00 local  (UTC−6 → UTC−7)
$cal .= "BEGIN:VTIMEZONE\r\n";
$cal .= prop('TZID', TIMEZONE);
$cal .= "BEGIN:DAYLIGHT\r\n";
$cal .= prop('TZOFFSETFROM', '-0700');
$cal .= prop('TZOFFSETTO',   '-0600');
$cal .= prop('TZNAME',       'MDT');
$cal .= prop('DTSTART',      '19700308T020000');
$cal .= prop('RRULE',        'FREQ=YEARLY;BYDAY=2SU;BYMONTH=3');
$cal .= "END:DAYLIGHT\r\n";
$cal .= "BEGIN:STANDARD\r\n";
$cal .= prop('TZOFFSETFROM', '-0600');
$cal .= prop('TZOFFSETTO',   '-0700');
$cal .= prop('TZNAME',       'MST');
$cal .= prop('DTSTART',      '19701101T020000');
$cal .= prop('RRULE',        'FREQ=YEARLY;BYDAY=1SU;BYMONTH=11');
$cal .= "END:STANDARD\r\n";
$cal .= "END:VTIMEZONE\r\n";

// ── VEVENT entries ────────────────────────────────────────────────────────────
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

foreach ($events as $row) {
    $location = build_location($row);
    $hosts    = trim($row['eventHosts']);

    // UID must be globally unique: <id>@<hostname>
    $uid = ics_escape($row['id']) . '@' . $host;

    $cal .= "BEGIN:VEVENT\r\n";

    // Required properties
    $cal .= prop('UID',           $uid);
    $cal .= prop('DTSTAMP',       dt_utc($row['updated']));   // UTC, per RFC 5545
    $cal .= prop('DTSTART', dt_local($row['time']),               ['TZID' => TIMEZONE]);
    $cal .= prop('DTEND',   dt_end($row['time'], $row['duration']), ['TZID' => TIMEZONE]);

    // Descriptive properties
    $cal .= prop('SUMMARY',       ics_escape($row['name']));
    $cal .= prop('STATUS',        to_status($row['status']));
    $cal .= prop('CREATED',       dt_utc($row['created']));
    $cal .= prop('LAST-MODIFIED', dt_utc($row['updated']));

    if ($location !== '') {
        $cal .= prop('LOCATION',    ics_escape($location));
    }
    if ($row['description'] !== '') {
        $cal .= prop('DESCRIPTION', ics_escape($row['description']));
    }
    if ($row['event_url'] !== '') {
        $cal .= prop('URL',         $row['event_url']);
    }

    // eventHosts is a comma-separated list of names.
    // RFC 5545 ORGANIZER requires a mailto: URI, so this is stored as a
    // custom X-property to preserve the data without violating the spec.
    if ($hosts !== '') {
        $cal .= prop('X-EVENT-HOSTS', ics_escape($hosts));
    }

    // GEO per RFC 5545 §3.8.1.6: "GEO:latitude;longitude"
    // Both values are semicolon-separated signed decimal degrees.
    $lat = $row['latitude']  ?? '';
    $lon = $row['longitude'] ?? '';
    if ($lat !== '' && $lat !== null && $lon !== '' && $lon !== null) {
        $lat_f = (float) $lat;
        $lon_f = (float) $lon;
        if ($lat_f !== 0.0 || $lon_f !== 0.0) {
            $cal .= prop('GEO', number_format($lat_f, 6) . ';' . number_format($lon_f, 6));
        }
    }

    $cal .= "END:VEVENT\r\n";
}

$cal .= "END:VCALENDAR\r\n";

// ─── Send Download Response ────────────────────────────────────────────────────
$filename = sprintf('events_%s_%s.ics', $start, $end);

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($cal));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

echo $cal;
