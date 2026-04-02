<?php
/**
 * parse_week.php
 *
 * Parses one week of Cloud Foundry log CSV files and outputs a JSON metrics object.
 *
 * Usage: php tools/parse_week.php <YYYY-MM-DD>
 *
 * Output: .tmp/<YYYY-MM-DD>-metrics.json
 */

$meta_root = dirname(dirname(dirname(__DIR__)));
require_once "$meta_root/tools/load_env.php";
load_env($meta_root);
load_env(__DIR__ . '/..');

$proxy_export_cap = (int)(getenv('PROXY_ALLOWED_EXPORT_CAP') ?: 5000);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/parse_week.php <YYYY-MM-DD>\n");
    exit(1);
}

$week = $argv[1];
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week)) {
    fwrite(STDERR, "Error: date must be YYYY-MM-DD format\n");
    exit(1);
}

$base = __DIR__ . '/../log-files/' . $week;
if (!is_dir($base)) {
    fwrite(STDERR, "Error: directory not found: $base\n");
    exit(1);
}

// ─── Helpers ────────────────────────────────────────────────────────────────

/**
 * Case-insensitive column lookup on an associative row array.
 * Returns the value or $default if not found.
 */
function col(array $row, string $name, string $default = ''): string {
    // Exact match first
    if (isset($row[$name])) return $row[$name];
    // Case-insensitive fallback
    $lower = strtolower($name);
    foreach ($row as $key => $val) {
        if (strtolower($key) === $lower) return $val;
    }
    return $default;
}

/**
 * Parse a CSV file and return [headers, rows[]] or null if file missing.
 */
function read_csv(string $path): ?array {
    if (!file_exists($path)) return null;
    $handle = fopen($path, 'r');
    if (!$handle) return null;
    $headers = fgetcsv($handle);
    if ($headers === false) { fclose($handle); return ['headers' => [], 'rows' => []]; }
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) === count($headers)) {
            $rows[] = array_combine($headers, $row);
        }
    }
    fclose($handle);
    return ['headers' => $headers, 'rows' => $rows];
}

/**
 * Normalise a timestamp string (handles ISO and US-month formats) to a DateTime object.
 * Returns null on failure.
 */
function parse_ts(string $ts): ?DateTime {
    $ts = trim($ts, '"');
    // ISO: "2026-02-26 22:35:46"
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{1,2}:\d{2}:\d{2}$/', $ts)) {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $ts, new DateTimeZone('UTC'));
        return $dt ?: null;
    }
    // US month: "March 05, 2026 22:50:21" or "March 5, 2026 1:33:30"
    if (preg_match('/^[A-Za-z]+ \d{1,2}, \d{4} \d{1,2}:\d{2}:\d{2}$/', $ts)) {
        $dt = DateTime::createFromFormat('F j, Y G:i:s', $ts, new DateTimeZone('UTC'));
        if (!$dt) $dt = DateTime::createFromFormat('F d, Y G:i:s', $ts, new DateTimeZone('UTC'));
        return $dt ?: null;
    }
    return null;
}

/**
 * Get the UTC hour-of-week (0 = Mon 00:00 UTC) for a DateTime.
 * Returns null if the day-of-week can't be determined.
 */
function day_name(DateTime $dt): string {
    return $dt->format('l'); // "Monday", "Tuesday", etc.
}

// ─── DST detection ──────────────────────────────────────────────────────────

function is_edt(DateTime $dt): bool {
    // Use PHP's native DST awareness for America/New_York — no hardcoded dates.
    $eastern = new DateTimeZone('America/New_York');
    $local   = (clone $dt)->setTimezone($eastern);
    return (bool) $local->format('I'); // 1 = DST active, 0 = standard time
}

/**
 * Check if a deployment timestamp is within the expected window.
 * Prod: Tuesday 8pm ET ± 90 min  → Wed 00:30–02:30 UTC (EDT) or Wed 01:00–03:00 UTC (EST)
 * Stage: Thursday 4:30pm ET ± 90 min → Thu 19:00–22:00 UTC (EDT) or Thu 20:00–23:00 UTC (EST)
 *
 * Returns 'on_time', 'late', or 'early'.
 */
function check_deployment_timing(DateTime $dt, string $space): string {
    $edt = is_edt($dt);
    $offset = $edt ? 4 : 5; // hours behind UTC
    $dow = strtolower(day_name($dt));
    $hour = (int)$dt->format('G');
    $min  = (int)$dt->format('i');
    $time_mins = $hour * 60 + $min;

    if ($space === 'prod') {
        // Expect Wed UTC (= Tue 8pm ET)
        // EDT: 8pm EDT = 00:00 UTC Wed → window 22:30 Tue – 01:30 Wed UTC
        // EST: 8pm EST = 01:00 UTC Wed → window 23:30 Tue – 02:30 Wed UTC
        // Check day is Wed (or Tue late) and hour is reasonable
        if ($dow === 'wednesday' && $time_mins <= 210) return 'on_time'; // Wed 00:00–03:30 UTC
        if ($dow === 'tuesday'  && $time_mins >= 1380) return 'on_time'; // Tue 23:00+ UTC
        return 'outside_window';
    }

    if ($space === 'stage') {
        // Expect Thu 4:30pm ET
        // EDT: 4:30pm EDT = 20:30 UTC → window 19:00–22:00 UTC Thu
        // EST: 4:30pm EST = 21:30 UTC → window 20:00–23:00 UTC Thu
        if ($dow === 'thursday') {
            if ($edt && $time_mins >= 1140 && $time_mins <= 1320) return 'on_time'; // 19:00–22:00
            if (!$edt && $time_mins >= 1200 && $time_mins <= 1380) return 'on_time'; // 20:00–23:00
        }
        return 'outside_window';
    }

    return 'unknown';
}

// ─── File paths ─────────────────────────────────────────────────────────────

$files = [
    'cfevents'      => "$base/{$week}-CFEvents-Logs.csv",
    'cf_api'        => "$base/{$week}-CF-API-messages.csv",
    'ssh'           => "$base/{$week}-SSH-Activity.csv",
    'proxy_denied'  => "$base/{$week}-Proxy-DENIED.csv",
    'proxy_allowed' => "$base/{$week}-Proxy-allowed.csv",
    'modsec_logs'   => "$base/{$week}-ModSecurity-Logs.csv",
    'modsec_msgs'   => "$base/{$week}-ModSecurity-Log-Messages.csv",
];

$missing = [];
foreach ($files as $key => $path) {
    if (!file_exists($path)) $missing[] = $key;
}

// ─── Parse CFEvents-Logs ────────────────────────────────────────────────────

$cfevents_data = read_csv($files['cfevents']);
$cfevents = [
    'present'      => $cfevents_data !== null,
    'row_count'    => 0,
    'by_event_type'=> [],
    'by_space'     => [],
    'deployments'  => [],
];

if ($cfevents_data) {
    $rows = $cfevents_data['rows'];
    $cfevents['row_count'] = count($rows);

    foreach ($rows as $row) {
        $event_type = col($row, 'Cfevent.Entity.Type',       col($row, 'Cfevent.entity.type', 'unknown'));
        $space      = col($row, 'Cfevent.Entity.Space Name', col($row, 'Cfevent.entity.space Name', 'unknown'));
        $actee      = col($row, 'Cfevent.Entity.Actee Name', col($row, 'Cfevent.entity.actee Name', ''));
        $ts_raw     = col($row, 'Timestamp', '');

        $cfevents['by_event_type'][$event_type] = ($cfevents['by_event_type'][$event_type] ?? 0) + 1;
        $cfevents['by_space'][$space] = ($cfevents['by_space'][$space] ?? 0) + 1;

        if ($event_type === 'audit.app.deployment.create') {
            $dt = parse_ts($ts_raw);
            $timing = $dt ? check_deployment_timing($dt, $space) : 'unknown';
            $cfevents['deployments'][] = [
                'timestamp' => $ts_raw,
                'timestamp_utc' => $dt ? $dt->format('Y-m-d H:i:s') : null,
                'space'     => $space,
                'app'       => $actee,
                'timing'    => $timing,
            ];
        }
    }
    arsort($cfevents['by_event_type']);
    arsort($cfevents['by_space']);
}

// Summarise deployments by space
$deployment_summary = [];
foreach ($cfevents['deployments'] as $dep) {
    $sp = $dep['space'];
    if (!isset($deployment_summary[$sp])) {
        $deployment_summary[$sp] = ['count' => 0, 'on_time' => 0, 'outside_window' => 0, 'earliest' => null, 'latest' => null];
    }
    $deployment_summary[$sp]['count']++;
    if ($dep['timing'] === 'on_time') $deployment_summary[$sp]['on_time']++;
    if ($dep['timing'] === 'outside_window') $deployment_summary[$sp]['outside_window']++;
    if (!$deployment_summary[$sp]['earliest'] || $dep['timestamp_utc'] < $deployment_summary[$sp]['earliest'])
        $deployment_summary[$sp]['earliest'] = $dep['timestamp_utc'];
    if (!$deployment_summary[$sp]['latest'] || $dep['timestamp_utc'] > $deployment_summary[$sp]['latest'])
        $deployment_summary[$sp]['latest'] = $dep['timestamp_utc'];
}
$cfevents['deployment_summary'] = $deployment_summary;
unset($cfevents['deployments']); // keep metrics JSON lean; full list not needed

// ─── Parse CF-API-messages ──────────────────────────────────────────────────

$cf_api_data = read_csv($files['cf_api']);
$cf_api = [
    'present'       => $cf_api_data !== null,
    'row_count'     => 0,
    'by_space'      => [],
    'by_app'        => [],
    'deployment_msgs' => 0,
];

if ($cf_api_data) {
    $rows = $cf_api_data['rows'];
    $cf_api['row_count'] = count($rows);

    foreach ($rows as $row) {
        $space = col($row, 'Tags.Space Name', 'unknown');
        $app   = col($row, 'Tags.App Name',   'unknown');
        $msg   = col($row, 'Message',         '');

        $cf_api['by_space'][$space] = ($cf_api['by_space'][$space] ?? 0) + 1;
        $cf_api['by_app'][$app]     = ($cf_api['by_app'][$app]     ?? 0) + 1;

        if (stripos($msg, 'Updated app') !== false || stripos($msg, 'deployment') !== false) {
            $cf_api['deployment_msgs']++;
        }
    }
    arsort($cf_api['by_space']);
    arsort($cf_api['by_app']);
}

// ─── Parse SSH-Activity ─────────────────────────────────────────────────────

$ssh_data = read_csv($files['ssh']);
$ssh = [
    'present'     => $ssh_data !== null,
    'row_count'   => 0,
    'sessions_started' => 0,
    'sessions_ended'   => 0,
    'by_space'    => [],
    'by_app'      => [],
];

if ($ssh_data) {
    $rows = $ssh_data['rows'];
    $ssh['row_count'] = count($rows);

    foreach ($rows as $row) {
        $space = col($row, 'Tags.Space Name', 'unknown');
        $app   = col($row, 'Tags.App Name',   'unknown');
        $msg   = col($row, 'Message',         '');

        $ssh['by_space'][$space] = ($ssh['by_space'][$space] ?? 0) + 1;
        $ssh['by_app'][$app]     = ($ssh['by_app'][$app]     ?? 0) + 1;

        if (stripos($msg, 'Successful remote access') !== false) $ssh['sessions_started']++;
        if (stripos($msg, 'Remote access ended')      !== false) $ssh['sessions_ended']++;
    }
    arsort($ssh['by_space']);
    arsort($ssh['by_app']);
}

// ─── Parse Proxy-DENIED ─────────────────────────────────────────────────────

$denied_data = read_csv($files['proxy_denied']);
$proxy_denied = [
    'present'        => $denied_data !== null,
    'row_count'      => 0,
    'top_destinations' => [],
];

if ($denied_data) {
    $rows = $denied_data['rows'];
    $proxy_denied['row_count'] = count($rows);

    $dests = [];
    foreach ($rows as $row) {
        $dest = col($row, 'Request.Host', 'unknown');
        $dests[$dest] = ($dests[$dest] ?? 0) + 1;
    }
    arsort($dests);
    $proxy_denied['top_destinations'] = array_slice($dests, 0, 10, true);
}

// ─── Parse Proxy-allowed ────────────────────────────────────────────────────

$allowed_data = read_csv($files['proxy_allowed']);
$proxy_allowed = [
    'present'          => $allowed_data !== null,
    'row_count'        => 0,
    'at_export_cap'    => false,
    'top_destinations' => [],
];

if ($allowed_data) {
    $rows = $allowed_data['rows'];
    $proxy_allowed['row_count'] = count($rows);
    $proxy_allowed['at_export_cap'] = $proxy_allowed['row_count'] >= $proxy_export_cap;

    $dests = [];
    foreach ($rows as $row) {
        $dest = col($row, 'Request.Host', 'unknown');
        $dests[$dest] = ($dests[$dest] ?? 0) + 1;
    }
    arsort($dests);
    $proxy_allowed['top_destinations'] = array_slice($dests, 0, 10, true);
}

// ─── Parse ModSecurity-Logs ─────────────────────────────────────────────────

$modsec_data = read_csv($files['modsec_logs']);
$modsec_logs = [
    'present'     => $modsec_data !== null,
    'row_count'   => 0,
    'by_rule_id'  => [],
    'by_host'     => [],
];

if ($modsec_data) {
    $rows = $modsec_data['rows'];
    $modsec_logs['row_count'] = count($rows);

    foreach ($rows as $row) {
        $rule_id = col($row, 'Modsecurity.ID',   'unknown');
        $msg     = col($row, 'Modsecurity.Msg',  '');
        $host    = col($row, 'Modsecurity.Host', 'unknown');

        $label = $rule_id . ($msg ? ": $msg" : '');
        $modsec_logs['by_rule_id'][$label] = ($modsec_logs['by_rule_id'][$label] ?? 0) + 1;
        $modsec_logs['by_host'][$host]     = ($modsec_logs['by_host'][$host]     ?? 0) + 1;
    }
    arsort($modsec_logs['by_rule_id']);
    arsort($modsec_logs['by_host']);
    // Keep top 15 rule IDs
    $modsec_logs['by_rule_id'] = array_slice($modsec_logs['by_rule_id'], 0, 15, true);
}

// ─── Parse ModSecurity-Log-Messages ─────────────────────────────────────────

$modsec_msgs_data = read_csv($files['modsec_msgs']);
$modsec_msgs = [
    'present'      => $modsec_msgs_data !== null,
    'row_count'    => 0,
    'total_events' => 0,
    'top_violations' => [],
];

if ($modsec_msgs_data) {
    $rows = $modsec_msgs_data['rows'];
    $modsec_msgs['row_count'] = count($rows);

    foreach ($rows as $row) {
        $data  = $row['Modsecurity.data']    ?? '';
        $count = (int)($row['Count']         ?? 0);
        $modsec_msgs['total_events'] += $count;
        $modsec_msgs['top_violations'][] = [
            'data'  => $data,
            'count' => $count,
        ];
    }
    usort($modsec_msgs['top_violations'], fn($a, $b) => $b['count'] - $a['count']);
    $modsec_msgs['top_violations'] = array_slice($modsec_msgs['top_violations'], 0, 10);
}

// ─── Assemble output ────────────────────────────────────────────────────────

$output = [
    'week_end'      => $week,
    'missing_files' => $missing,
    'cfevents'      => $cfevents,
    'cf_api'        => $cf_api,
    'ssh'           => $ssh,
    'proxy_denied'  => $proxy_denied,
    'proxy_allowed' => $proxy_allowed,
    'modsec_logs'   => $modsec_logs,
    'modsec_msgs'   => $modsec_msgs,
];

$out_path = __DIR__ . '/../.tmp/' . $week . '-metrics.json';
file_put_contents($out_path, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo $out_path . "\n";
