<?php
/**
 * generate_analysis.php
 *
 * Builds a Claude analysis prompt from all available .tmp/*-metrics.json files,
 * calls Claude, and writes output/<date-range>-report.md.
 *
 * Usage: php tools/generate_analysis.php
 */

$meta_root = dirname(dirname(dirname(__DIR__)));
$tmp_dir   = __DIR__ . '/../.tmp';
$out_dir   = __DIR__ . '/../output';

require_once "$meta_root/tools/load_env.php";
load_env($meta_root);
load_env(__DIR__ . '/..');

$system_context = trim(file_get_contents(__DIR__ . '/../config/system-context.txt'));

// Load metrics files in date order
$files = glob("$tmp_dir/*-metrics.json");
sort($files);
if (empty($files)) {
    fwrite(STDERR, "No metrics files found in $tmp_dir\n");
    exit(1);
}

$all_metrics = [];
foreach ($files as $f) {
    $m = json_decode(file_get_contents($f), true);
    if ($m) $all_metrics[] = $m;
}

$weeks      = array_column($all_metrics, 'week_end');
$week_count = count($all_metrics);
$report_date = end($weeks);
$report_file = "$out_dir/{$report_date}-report.md";

// Load summary
$summary_path = "$out_dir/summary.json";
$summary = file_exists($summary_path) ? json_decode(file_get_contents($summary_path), true) : null;

// ─── Build dynamic data notes ────────────────────────────────────────────────

$notes = [];

// Proxy-allowed export cap
$cap = (int)(getenv('PROXY_ALLOWED_EXPORT_CAP') ?: 5000);
$cap_weeks = array_column(array_filter($all_metrics, fn($m) => $m['proxy_allowed']['at_export_cap']), 'week_end');
if (count($cap_weeks) === $week_count) {
    $notes[] = "`proxy_allowed` rows are capped at $cap by the New Relic export — all $week_count weeks hit this cap. Actual traffic volume is higher.";
} elseif (!empty($cap_weeks)) {
    $notes[] = "`proxy_allowed` rows are capped at $cap by the New Relic export. " . count($cap_weeks) . " of $week_count weeks hit this cap (" . implode(', ', $cap_weeks) . "). Actual traffic volume may be higher in those weeks.";
}

// Proxy-DENIED missing or empty
foreach ($all_metrics as $m) {
    $w = $m['week_end'];
    if (!$m['proxy_denied']['present']) {
        $notes[] = "Week ending $w is missing its `Proxy-DENIED` file entirely — treat as zero denials.";
    } elseif ($m['proxy_denied']['row_count'] === 0) {
        $notes[] = "Week ending $w has a `Proxy-DENIED` file present but with 0 data rows (header only) — treat as zero denials.";
    }
}

// CF-API row count variation
$cf_api_values = array_map(fn($m) => $m['cf_api']['row_count'], $all_metrics);
if ($week_count > 1 && max($cf_api_values) > min($cf_api_values) * 1.5) {
    $notes[] = "CF-API-messages row counts vary significantly week to week (" . implode(' -> ', $cf_api_values) . "), likely reflecting variable dev/stage activity rather than a consistent baseline.";
}

// SSH session counts
$ssh_values = array_map(fn($m) => $m['ssh']['sessions_started'], $all_metrics);
if ($week_count > 1) {
    $notes[] = "SSH session counts vary week to week (" . implode(' -> ', $ssh_values) . " sessions started).";
}

// ModSecurity trend
$modsec_values = array_map(fn($m) => $m['modsec_msgs']['total_events'], $all_metrics);
if ($week_count > 1) {
    $seq   = implode(' -> ', $modsec_values);
    $first = $modsec_values[0];
    $last  = $modsec_values[$week_count - 1];
    if ($last > $first * 1.25) {
        $notes[] = "ModSecurity violation counts show an upward trend ($seq total events).";
    } elseif ($last < $first * 0.75) {
        $notes[] = "ModSecurity violation counts show a downward trend ($seq total events).";
    } else {
        $notes[] = "ModSecurity violation counts are relatively stable ($seq total events).";
    }
}

// Low deployment counts
foreach ($all_metrics as $m) {
    $dep = $m['cfevents']['deployment_summary'] ?? [];
    foreach (['prod', 'stage'] as $space) {
        $count = $dep[$space]['count'] ?? null;
        if ($count !== null && $count < 7) {
            $notes[] = "Week ending {$m['week_end']} shows only $count deployment events in the $space space — below the expected 7-9.";
        }
    }
}

$data_notes = empty($notes)
    ? '- No notable data quirks detected.'
    : implode("\n", array_map(fn($n) => "- $n", $notes));

// ─── Build prompt ────────────────────────────────────────────────────────────

$metrics_json = json_encode($all_metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$summary_json = $summary ? json_encode($summary, JSON_PRETTY_PRINT) : 'null';
$week_cols    = implode(' | ', $weeks);

$prompt = <<<PROMPT
You are analyzing $week_count weeks of Cloud Foundry audit log data for a US government website (usa.gov). The logs are exported weekly from New Relic.

## System Overview

$system_context

## Expected Patterns

- **Production deployments**: Tuesdays ~8pm Eastern Time (EST = UTC-5, EDT = UTC-4)
  - EST: Wed ~01:00-02:30 UTC | EDT: Wed ~00:00-01:00 UTC
- **Stage deployments**: Thursdays ~4:30pm Eastern Time
  - EST: Thu ~21:30-23:00 UTC | EDT: Thu ~20:30-22:00 UTC
- US DST applies from the second Sunday in March through the first Sunday in November

## Detailed Metrics (all $week_count weeks)

```json
$metrics_json
```

## Cross-Week Summary

```json
$summary_json
```

## Notes on the data

$data_notes

## Your Task

Write a comprehensive cross-week analysis report in Markdown covering all $week_count weeks ($week_cols).

### Mandatory table rule

Every section below that specifies a REQUIRED TABLE must contain that table. You may NOT replace a required table with prose, even when data is sparse or unremarkable. Use the actual week-end dates as column headers, not generic labels like "Week 1".

### Required sections and tables

**1. Executive Summary** (3-5 sentences): Overall health and consistency across all $week_count weeks. No table.

**2. Deployment Pattern Analysis**
REQUIRED TABLE - columns: Space | $week_cols
Rows: prod (deployment count), stage (deployment count)
Note any weeks with below-expected counts (expected: 9 per space), and whether all deployments were on time.

**3. CF Events Activity**
REQUIRED TABLE - columns: Event Type | $week_cols
Include all event types observed across any week; use 0 for absent weeks.
Follow with 1-2 sentence narrative noting anything unusual (e.g., ssh-unauthorized events).

**4. SSH Activity**
REQUIRED TABLE - columns: Metric | $week_cols
Rows: Sessions Started, Sessions Ended
Follow with narrative on which space/app dominated and notable week-over-week changes.

**5. CF API Messages**
REQUIRED TABLE - columns: Week | Row Count
One row per week.
Follow with narrative on variation and which spaces/apps drove it.

**6. Proxy Traffic**

Allowed:
REQUIRED TABLE - columns: Week | Row Count | At Cap
"At Cap" = Yes or No (cap = 5000 rows). Note that capped weeks have underreported actual traffic.

Denied:
REQUIRED TABLE - columns: Week | Denied Count
Use 0 for weeks with no denials or missing file. Briefly explain significance.

**7. ModSecurity / WAF Activity**
REQUIRED TABLE - columns: Week | Total Events
One row per week.
Follow with assessment of the trend and the dominant violation types.

**8. Data Quality Notes**: Notable missing files, format issues, or data limitations. No table required.

**9. Conclusion**: Overall assessment — is activity consistent and within expected norms? Are there items warranting follow-up investigation? No table required.

### General formatting rules

- Plain ASCII only — no emoji, no Unicode minus (use -), no Unicode approximately (use ~), no Greek letters (use plain words), no smart quotes
- Use Markdown headings for each section
- Keep language precise and professional — this report is for a security and operations audience
PROMPT;

// Write prompt to temp file
$prompt_file = "$tmp_dir/analysis-prompt.txt";
file_put_contents($prompt_file, $prompt);

// Call Claude
$cmd = sprintf(
    'php %s --prompt-file=%s %s %s',
    escapeshellarg("$meta_root/tools/call_ai.php"),
    escapeshellarg($prompt_file),
    escapeshellarg('You are an expert cloud infrastructure and security analyst writing a formal operations report.'),
    escapeshellarg("$tmp_dir/analysis-response.json")
);

echo "Calling Claude for analysis...\n";
passthru($cmd, $exit_code);

if ($exit_code !== 0) {
    fwrite(STDERR, "Claude call failed (exit $exit_code)\n");
    exit(1);
}

// Extract response text
$response = json_decode(file_get_contents("$tmp_dir/analysis-response.json"), true);
if (!$response || empty($response['response'])) {
    fwrite(STDERR, "Empty response from Claude\n");
    exit(1);
}

$markdown  = $response['response'];
$generated = date('Y-m-d H:i:s') . ' UTC';

// Add generation metadata header
$header = "<!-- Generated: $generated | Weeks: " . implode(', ', $weeks) . " -->\n\n";

// Format-specific footer blocks (same pattern as generate_week_report.php)
$footer = "\n\n" .
    "```{=html}\n" .
    '<div style="text-align: center; font-size: 0.75em; font-family: monospace; color: #888; margin-top: 3em; padding-top: 0.75em; border-top: 1px solid #ddd;">Generated: ' . $generated . "</div>\n" .
    "```\n\n" .
    "```{=latex}\n" .
    '\\vspace{2em}' . "\n" .
    '\\begin{center}{\\small\\ttfamily Generated: ' . $generated . '}\\end{center}' . "\n" .
    "```";

file_put_contents($report_file, $header . $markdown . $footer);
echo "Report written: $report_file\n";
