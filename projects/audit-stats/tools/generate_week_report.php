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

Write a concise, focused per-week analysis report in Markdown. You MUST follow the exact structure and formatting rules below — do not deviate.

### Required document structure

The report MUST begin with these two lines exactly (substituting the correct date):

```
# Weekly Operations Report

**Week Ending:** YYYY-MM-DD
```

Then the following sections in order:

```
## Week Summary
## Deployments
## CF Events
## SSH Activity
## CF API Messages
## Proxy Traffic
## ModSecurity / WAF
## Data Quality
## Items for Follow-Up
```

### Mandatory table rule

Every section below that specifies a table MUST contain that table. You may NOT replace a required table with prose, even when data is sparse, minimal, or appears unremarkable. The only exception is Proxy Traffic Denied: if there are zero denials, write "No denials recorded." instead of a table. All other tables are unconditional.

### Section-by-section formatting rules

**## Week Summary**
3-4 sentence paragraph. No sub-headers.

**## Deployments**
REQUIRED TABLE with EXACTLY these columns (no renaming):
| Space | Deployment Count | On Time | Outside Window | UTC Window Observed |

- "Outside Window" MUST be an integer count (0, 1, 2, ...) — never Yes/No/boolean
- "UTC Window Observed" MUST be a full date+time range: "YYYY-MM-DD HH:MM - HH:MM" (include the date, not time only)
- Follow with bullet points noting EST vs EDT, schedule adherence, and expected count (typically 9 per space)

**## CF Events**
REQUIRED TABLE: | Event Type | Count |
List all event types from the metrics. Follow with 1-2 sentence narrative.

**## SSH Activity**
REQUIRED TABLE: | Space | Sessions Started | Sessions Ended |
- One row per space with activity
- Total row MUST use bold markdown: | **Total** | **N** | **N** |
- Follow with 1 sentence noting the dominant app and any anomalies

**## CF API Messages**
REQUIRED TABLE: | Space | Row Count |
- Include all spaces present in the metrics — do not omit any
- Space names MUST NOT be abbreviated. Use full names: "Shared-egress" not "Shared-egr"
- Total row MUST use bold markdown: | **Total** | **N** |
- Follow with 1 sentence on top apps by volume and comparison to prior weeks

**## Proxy Traffic**
This section uses BOLD LABELS (not sub-headers) for the two sub-sections:

```
**Allowed:**
<table>

**Denied:**
<table or "No denials recorded.">
```

Do NOT use ### sub-headers here. Bold labels only.

- Allowed: REQUIRED TABLE | Destination | Count |, top destinations; note if at export cap (5000 rows)
- Denied: REQUIRED TABLE | Destination | Count | if denials exist; if no denials, write exactly "No denials recorded." with no table

**## ModSecurity / WAF**
This section uses BOLD LABELS (not sub-headers) for the two sub-sections:

```
**Violations:**
<table>

**Hosts:**
<table>
<narrative sentence>
```

Do NOT use ### sub-headers here. Bold labels only.

- Violations: REQUIRED TABLE | Violation | Count |, all violation types from the metrics (up to 10 rows)
- Hosts: REQUIRED TABLE | Host | Count |, all hosts from the metrics
- Follow with 1 sentence comparing total event count to prior weeks

**## Data Quality**
Paragraph noting missing files, parsing gaps, or format issues. If no issues: "No data quality issues this week."

**## Items for Follow-Up**
Only genuine action items. If none, write one sentence saying the week was clean (do not omit the section).

### General rules

- Plain ASCII only — no emoji, no Unicode minus (use -), no Unicode approximately (use ~), no Greek letters (use plain words), no smart quotes
- Keep the report concise — aim for clarity over length
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

$markdown  = $response['response'];
$generated = date('Y-m-d H:i:s') . ' UTC';
$header    = "<!-- Week report: $week | Generated: $generated -->\n\n";

// Format-specific footer blocks: HTML and LaTeX (PDF) each get the appropriate syntax.
// The {=html} block is ignored by pdflatex; the {=latex} block is ignored by pandoc HTML output.
$footer = "\n\n" .
    "```{=html}\n" .
    '<div style="text-align: center; font-size: 0.75em; font-family: monospace; color: #888; margin-top: 3em; padding-top: 0.75em; border-top: 1px solid #ddd;">Generated: ' . $generated . "</div>\n" .
    "```\n\n" .
    "```{=latex}\n" .
    '\\vspace{2em}' . "\n" .
    '\\begin{center}{\\small\\ttfamily Generated: ' . $generated . '}\\end{center}' . "\n" .
    "```";

file_put_contents($report_md, $header . $markdown . $footer);
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
