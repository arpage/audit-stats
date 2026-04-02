<?php
/**
 * build_summary.php
 *
 * Reads all .tmp/<YYYY-MM-DD>-metrics.json files and writes output/summary.json.
 * Safe to re-run — existing entries are updated, new ones appended.
 *
 * Usage: php tools/build_summary.php
 */

$tmp_dir = __DIR__ . '/../.tmp';
$out_path = __DIR__ . '/../output/summary.json';

// Load existing summary if present
$summary = ['weeks' => []];
if (file_exists($out_path)) {
    $existing = json_decode(file_get_contents($out_path), true);
    if ($existing) $summary = $existing;
}

// Index existing weeks by week_end date
$existing_by_date = [];
foreach ($summary['weeks'] as $i => $w) {
    $existing_by_date[$w['week_end']] = $i;
}

// Find all metrics files
$files = glob("$tmp_dir/*-metrics.json");
sort($files);

foreach ($files as $file) {
    $m = json_decode(file_get_contents($file), true);
    if (!$m) continue;

    $dep = $m['cfevents']['deployment_summary'] ?? [];

    $entry = [
        'week_end'                  => $m['week_end'],
        'missing_files'             => $m['missing_files'],
        'cfevents_row_count'        => $m['cfevents']['row_count'],
        'cf_api_row_count'          => $m['cf_api']['row_count'],
        'ssh_session_count'         => $m['ssh']['sessions_started'],
        'ssh_row_count'             => $m['ssh']['row_count'],
        'proxy_denied_row_count'    => $m['proxy_denied']['row_count'],
        'proxy_denied_present'      => $m['proxy_denied']['present'],
        'proxy_allowed_row_count'   => $m['proxy_allowed']['row_count'],
        'proxy_allowed_at_cap'      => $m['proxy_allowed']['at_export_cap'],
        'modsec_log_row_count'      => $m['modsec_logs']['row_count'],
        'modsec_total_events'       => $m['modsec_msgs']['total_events'],
        'prod_deployment_count'     => $dep['prod']['count']      ?? 0,
        'prod_deployment_on_time'   => ($dep['prod']['outside_window'] ?? 0) === 0 && ($dep['prod']['count'] ?? 0) > 0,
        'prod_deployment_found'     => ($dep['prod']['count'] ?? 0) > 0,
        'stage_deployment_count'    => $dep['stage']['count']     ?? 0,
        'stage_deployment_on_time'  => ($dep['stage']['outside_window'] ?? 0) === 0 && ($dep['stage']['count'] ?? 0) > 0,
        'stage_deployment_found'    => ($dep['stage']['count'] ?? 0) > 0,
    ];

    if (isset($existing_by_date[$m['week_end']])) {
        $summary['weeks'][$existing_by_date[$m['week_end']]] = $entry;
    } else {
        $summary['weeks'][] = $entry;
    }
}

// Sort by week_end ascending
usort($summary['weeks'], fn($a, $b) => strcmp($a['week_end'], $b['week_end']));

file_put_contents($out_path, json_encode($summary, JSON_PRETTY_PRINT));
echo "Written: $out_path\n";
