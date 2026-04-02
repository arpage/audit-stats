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

Write a comprehensive analysis report in Markdown. The report should include:

1. **Executive Summary** (3–5 sentences): Overall health and consistency of the system across these 4 weeks.

2. **Deployment Pattern Analysis**: Were prod and stage deployments present and on schedule each week? Note the slightly lower deployment counts for week ending 2026-03-23.

3. **CF Events Activity**: Summarize event types observed. Note SSH authorization counts (these are high — flag if unexpectedly so). Highlight the one `audit.app.ssh-unauthorized` event in week 1.

4. **SSH Activity**: Week-over-week trends. Which spaces and apps were most accessed. Note week 3 (2026-03-16) had noticeably fewer SSH sessions (56 vs 99–115 in other weeks).

5. **CF API Messages**: Explain the large variation in row counts across weeks. The high dev space activity is expected during development cycles.

6. **Proxy Traffic**:
   - Allowed: all weeks at the 5000-row New Relic cap; note this limits visibility. Top destinations (api.fr.cloud.gov, Google Analytics APIs, New Relic, login.fr.cloud.gov) appear normal for this system.
   - Denied: missing for week 2, zero for week 1, low counts for weeks 3–4 (2 and 3 rows). Describe what this means.

7. **ModSecurity / WAF Activity**: Summarize the top violation types (failed body parsing errors dominate). Note the upward trend from 328 to 541 total events over the 4 weeks. Assess whether this is concerning.

8. **Data Quality Notes**: The missing Proxy-DENIED file for week 2, the timestamp format change, and the proxy_allowed export cap.

9. **Conclusion**: Overall assessment — is activity consistent and within expected norms? Are there any items that warrant follow-up investigation?

Format the report with clear Markdown headings, use tables where useful (e.g., week-over-week metric comparison), and keep language precise and professional. This report is for a security and operations audience.

**Important formatting constraint:** Do NOT use any Unicode special characters — no emoji (✅ ❌), no Unicode minus (−), no Unicode approximately (≈), no Greek letters (Δ), no smart quotes, and no other non-ASCII symbols. Use plain ASCII equivalents instead: - for minus, ~ for approximately, "Change" for delta, Yes/No instead of checkmarks.
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

$markdown = $response['response'];

// Add generation metadata header
$header = "<!-- Generated: " . date('Y-m-d H:i:s') . " UTC | Weeks: " . implode(', ', $weeks) . " -->\n\n";
// Note: concatenation used intentionally — date() and implode() calls cannot be inlined in PHP string interpolation.
file_put_contents($report_file, $header . $markdown);
echo "Report written: $report_file\n";
