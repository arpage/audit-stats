<?php
/**
 * generate_week_report.php
 *
 * Generates a focused single-week analysis report, with cross-week comparison
 * context drawn from output/summary.json.
 *
 * Usage: php tools/generate_week_report.php <YYYY-MM-DD>
 *
 * Outputs:
 *   output/<YYYY-MM-DD>-week-report.md
 *   output/<YYYY-MM-DD>-week-report.html
 *   output/<YYYY-MM-DD>-week-report.pdf
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/generate_week_report.php <YYYY-MM-DD>\n");
    exit(1);
}

$week = $argv[1];
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week)) {
    fwrite(STDERR, "Error: date must be YYYY-MM-DD format\n");
    exit(1);
}

$meta_root      = dirname(dirname(dirname(__DIR__)));
$system_context = trim(file_get_contents(__DIR__ . '/../config/system-context.txt'));
$tmp_dir    = __DIR__ . '/../.tmp';
$out_dir    = __DIR__ . '/../output';
$metrics_file  = "$tmp_dir/{$week}-metrics.json";
$summary_file  = "$out_dir/summary.json";
$report_md     = "$out_dir/{$week}-week-report.md";

if (!file_exists($metrics_file)) {
    fwrite(STDERR, "Error: metrics file not found: $metrics_file\n");
    fwrite(STDERR, "Run: php tools/parse_week.php $week\n");
    exit(1);
}

$m       = json_decode(file_get_contents($metrics_file), true);
$summary = file_exists($summary_file) ? json_decode(file_get_contents($summary_file), true) : null;

// Find this week's position in the summary and identify prior weeks for comparison
$prior_weeks = [];
$this_week_summary = null;
if ($summary) {
    foreach ($summary['weeks'] as $w) {
        if ($w['week_end'] === $week) {
            $this_week_summary = $w;
        } elseif ($w['week_end'] < $week) {
            $prior_weeks[] = $w;
        }
    }
}

$metrics_json = json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
$summary_json = $summary ? json_encode($summary, JSON_PRETTY_PRINT) : 'null';
$prior_count  = count($prior_weeks);
$prior_label  = $prior_count > 0
    ? "This is week " . ($prior_count + 1) . " of data. Prior weeks: " . implode(', ', array_column($prior_weeks, 'week_end')) . "."
    : "This is the first week of data. No prior weeks are available for comparison.";

// ─── Build prompt ────────────────────────────────────────────────────────────

$prompt = <<<PROMPT
You are analyzing Cloud Foundry audit log data for one specific week for a US government website (usa.gov). Produce a focused per-week analysis report.

$prior_label

## System Overview

$system_context

## Expected Weekly Patterns

- **Production deployments**: Tuesdays ~8pm Eastern Time
  - EST (UTC-5): Wednesday ~01:00-02:30 UTC
  - EDT (UTC-4): Wednesday ~00:00-01:30 UTC
- **Stage deployments**: Thursdays ~4:30pm Eastern Time
  - EST (UTC-5): Thursday ~21:30-23:00 UTC
  - EDT (UTC-4): Thursday ~20:30-22:00 UTC
- US DST applies from the second Sunday in March through the first Sunday in November
- Proxy-DENIED file absent or empty = zero denials = ideal state
- proxy_allowed rows are capped at 5000 by New Relic; hitting this cap is normal and expected

## This Week's Metrics

Week ending: **$week**

```json
$metrics_json
```

## Cross-Week Summary (all available weeks for comparison)

```json
$summary_json
```

## Your Task

Write a concise, focused per-week analysis report in Markdown. Structure it as follows:

1. **Week Summary** (3-4 sentences): Overall characterization of this week — was it a typical week, heavier/lighter than usual, any notable events?

2. **Deployments**:
   - Table showing prod and stage deployment counts, timing classification, and UTC window observed
   - Were deployments on schedule? Were counts as expected (typically 9 per space)?
   - Note if this is EST or EDT week and confirm the timestamps reflect the correct UTC offset

3. **CF Events**:
   - Event type breakdown table
   - Highlight anything unusual (new event types, counts significantly above/below typical)
   - Note SSH authorized/unauthorized counts

4. **SSH Activity**:
   - Session count and balance (started vs ended)
   - Space and app breakdown if available
   - Compare to prior weeks if data exists

5. **CF API Messages**:
   - Total row count and space/app breakdown if available
   - Compare to prior weeks; explain any significant variance

6. **Proxy Traffic**:
   - Allowed: top destinations, note if at export cap
   - Denied: count and destinations; note if file absent/empty (= zero denials)

7. **ModSecurity / WAF**:
   - Total event count and top violation types
   - Host breakdown if available
   - Compare to prior weeks and note trend direction

8. **Data Quality**:
   - Note any missing files, parsing gaps, or format issues specific to this week
   - If no issues, state that explicitly ("No data quality issues this week.")

9. **Items for Follow-Up** (if any):
   - Only include genuine action items. If the week was clean, say so and omit this section.

Keep the report concise — aim for clarity over length. Use tables where they add value. Plain ASCII only — no emoji, no Unicode minus (use -), no Unicode approximately (use ~), no Greek letters (use plain words), no smart quotes.
PROMPT;

// Write prompt to temp file
$prompt_file = "$tmp_dir/{$week}-week-prompt.txt";
file_put_contents($prompt_file, $prompt);

// Call Claude
$response_file = "$tmp_dir/{$week}-week-response.json";
$cmd = sprintf(
    'php %s --prompt-file=%s %s %s',
    escapeshellarg("$meta_root/tools/call_ai.php"),
    escapeshellarg($prompt_file),
    escapeshellarg('You are an expert cloud infrastructure and security analyst writing a concise weekly operations report.'),
    escapeshellarg($response_file)
);


echo "Generating report for week ending $week...\n";

// Retry up to 4 times with backoff for transient API failures (e.g. HTTP 529 overloaded)
$max_attempts = 4;
$exit_code = 1;
for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
    passthru($cmd, $exit_code);
    if ($exit_code === 0) break;
    if ($attempt < $max_attempts) {
        $wait = $attempt * 30;
        echo "Attempt $attempt failed — waiting {$wait}s before retry...\n";
        sleep($wait);
    }
}

if ($exit_code !== 0) {
    fwrite(STDERR, "Claude call failed after $max_attempts attempts (exit $exit_code)\n");
    exit(1);
}

$response = json_decode(file_get_contents($response_file), true);
if (!$response || empty($response['response'])) {
    fwrite(STDERR, "Empty response from Claude\n");
    exit(1);
}

$markdown = $response['response'];
$header = "<!-- Week report: $week | Generated: " . date('Y-m-d H:i:s') . " UTC -->\n\n";
file_put_contents($report_md, $header . $markdown);
echo "Report written: $report_md\n";

// Export HTML
$cmd_html = sprintf('php %s %s', escapeshellarg("$meta_root/tools/markdown_to_html.php"), escapeshellarg($report_md));
passthru($cmd_html);

// Export PDF
$cmd_pdf = sprintf('php %s %s', escapeshellarg("$meta_root/tools/markdown_to_pdf.php"), escapeshellarg($report_md));
passthru($cmd_pdf, $pdf_exit);

if ($pdf_exit !== 0) {
    echo "Note: PDF export failed — check for unsupported Unicode characters in the report.\n";
}
