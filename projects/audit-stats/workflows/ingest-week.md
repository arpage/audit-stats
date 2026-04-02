# ingest-week

This is a WAT workflow SOP document.

## Objective

Ingest one week of Cloud Foundry log CSV files and produce an analysis report that:
1. Summarises activity across all 7 file types for the week
2. Flags any anomalies relative to expected patterns (see AGENTS.md)
3. Compares this week's activity to previously ingested weeks (if any exist in `output/summary.json`)

## Inputs

- The week-end date (`YYYY-MM-DD`) — determines which folder under `log-files/` to process
- Expected folder: `log-files/<YYYY-MM-DD>/`

## Pre-flight checks

Before processing, verify:
1. The week folder exists: `log-files/<YYYY-MM-DD>/`
2. All 7 expected CSV files are present in that folder
3. The `output/` directory exists (create it if not: `mkdir -p output`)

## Steps

### 1. Inventory the files

List the files in the week folder and confirm all 7 are present. Note any missing files — the report should call them out explicitly.

### 2. Parse each CSV file

For each of the 7 file types, extract the relevant metrics. Use `php -r` or a project tool to:
- Count total rows (excluding header)
- Extract key fields (timestamps, event types, spaces, app names, message types, etc.)
- Identify the date range actually covered by the data

File-specific notes:
- **CFEvents-Log**: count events by type and by CF space; note any unusual event types
- **CF-API-messages**: count by severity (info/warning/error); flag any errors
- **SSH-Activity**: count SSH sessions; note which apps/spaces were accessed and by whom
- **Proxy-DENIED**: count denied connections; note top destinations or patterns
- **Proxy-allowed**: count allowed connections; note top destinations or patterns
- **ModSecurityLogs**: extract rule violation counts and descriptions
- **ModSecurityLogMessages**: count raw messages; cross-reference with ModSecurityLogs

### 3. Check expected deployment patterns

Using timestamps in CFEvents-Log and CF-API-messages:
- **Production**: look for deployment events on Tuesday between ~00:00–02:00 UTC (= ~8pm EDT / ~9pm EST the prior day)
- **Stage**: look for deployment events on Thursday between ~20:30–22:00 UTC (= ~4:30pm EDT / ~3:30pm EST)

Flag if:
- An expected deployment is absent
- A deployment occurred outside the expected window
- An unexpected deployment occurred on another day

### 4. Compare to prior weeks

Load `output/summary.json` if it exists. Compare this week's metrics to:
- The same metrics from previous weeks
- Flag any values that are more than 2× or less than 0.5× the rolling average (where enough weeks of data exist)

### 5. Synthesise the report via AI

Call `call_ai.php` with the extracted metrics and any flagged anomalies. Ask it to produce:
- A concise executive summary (3–5 sentences)
- A section for each file type (key counts, notable observations)
- A deployment pattern section (present / absent / anomalous)
- An anomalies section (week-over-week deviations, if prior data exists)
- A conclusion (overall assessment: consistent / minor deviations / significant deviations)

Save the raw output to `output/<YYYY-MM-DD>-report.md`.

### 6. Update summary.json

Append (or update) the entry for this week in `output/summary.json`. Schema:

```json
{
  "weeks": [
    {
      "week_end": "YYYY-MM-DD",
      "cfevents_row_count": 0,
      "cf_api_error_count": 0,
      "ssh_session_count": 0,
      "proxy_denied_count": 0,
      "proxy_allowed_count": 0,
      "modsec_violation_count": 0,
      "prod_deployment_found": true,
      "prod_deployment_on_time": true,
      "stage_deployment_found": true,
      "stage_deployment_on_time": true,
      "anomalies": []
    }
  ]
}
```

### 7. Export the report

```bash
php ../../tools/markdown_to_html.php output/<YYYY-MM-DD>-report.md
php ../../tools/markdown_to_pdf.php output/<YYYY-MM-DD>-report.md
```

## Error handling

- **Missing CSV file**: note it in the report, skip that file's metrics, continue with the rest
- **Empty CSV (header only)**: treat all counts as 0; note it in the report
- **Malformed rows**: skip the row, count and report the number of skipped rows
- **AI API failure**: re-run `call_ai.php` once; if it fails again, save raw metrics as JSON and alert the user

## Known data quirks (update as new weeks are ingested)

- **Timestamp formats vary.** Week ending 2026-03-02 uses ISO format (`2026-02-26 22:35:46`). Weeks ending 2026-03-09 onward use US month format (`"March 05, 2026 22:50:21"`). The parser handles both.
- **Column name casing varies.** CFEvents-Logs week 1 uses `Cfevent.Entity.Type` (uppercase); weeks 2+ use `Cfevent.entity.type` (lowercase). Weeks 3–4 also add extra New Relic metadata columns. Always use case-insensitive column lookups.
- **Field-level granularity.** Due to the format change, space/app/host fields may parse as "unknown" in weeks 2+. This is a known gap.
- **`proxy_allowed` is capped at 5000 rows** by the New Relic export. All observed weeks hit this cap. Actual egress volume is higher.
- **`Proxy-DENIED` can be missing or empty.** Both cases mean zero denials for that week — a missing file is not a data gap. Week ending 2026-03-02 had a header-only file; week ending 2026-03-09 had no file at all. Both are treated as 0 denied rows, which is the ideal state.
- **Deployment counts per space.** Expect 9 deployment events per space per week (one per app: cms, waf, cron, www, AnalyticsReporter + occasional extras). Counts of 7–9 are normal; fewer than 7 warrants investigation.
- **Dev space deployments are unscheduled** and can spike significantly (e.g., 51 in one week). This is expected during active development.
- **US DST:** Starts second Sunday in March, ends first Sunday in November. Prod deployment UTC window shifts 1 hour earlier at DST start.

## Tooling rules

- **Never use Python** — PHP and `jq` are the correct tools
- Use `jq` for all JSON parsing and field extraction from command-line output
- Use `php -r` only for PHP-specific logic not expressible with `jq`
- Use `php ../../tools/call_ai.php` for all AI synthesis calls
- Keep intermediate data in `.tmp/` (gitignored); only final outputs go in `output/`
