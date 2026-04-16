# analyze-all-weeks

This is a WAT workflow SOP document.

## Objective

For all weeks present in `log-files/`, produce:
1. A **per-week summary report** for each week, comparing that week's activity to all preceding weeks
2. A **cross-week analysis report** covering all weeks in aggregate, surfacing trends and anomalies that span multiple weeks

## Inputs

- All week folders present under `log-files/` (each named `YYYY-MM-DD/`)
- No arguments required — tools discover weeks automatically from `.tmp/*-metrics.json`

## Pre-flight checks

Before running, verify:
1. At least two week folders exist under `log-files/` (comparative reports are not meaningful for a single week)
2. The `output/` directory exists (create if not: `mkdir -p output`)
3. The `.tmp/` directory exists (create if not: `mkdir -p .tmp`)

## Steps

### 1. Inventory available weeks

List all week folders and note which ones already have parsed metrics:

```bash
ls log-files/
ls .tmp/*-metrics.json
```

Cross-reference: every folder in `log-files/` should have a corresponding `.tmp/<YYYY-MM-DD>-metrics.json` file.

### 2. Parse any un-parsed weeks

For each week folder that is missing a metrics file in `.tmp/`, run the single-week parser:

```bash
php tools/parse_week.php <YYYY-MM-DD>
```

Repeat for each missing week. Refer to `workflows/ingest-week.md` for full details on the parse step and its error handling.

### 3. Rebuild summary.json

Regenerate `output/summary.json` from all metrics files to ensure it reflects the current set of weeks:

```bash
php tools/build_summary.php
```

This is safe to re-run — existing entries are updated, new ones appended, and the file is sorted by week.

### 4. Generate per-week summary reports

Run `generate_week_report.php` for each week in chronological order (oldest first). Each report compares that week's data to all preceding weeks using `output/summary.json`. The tool automatically exports HTML and PDF — no additional commands needed.

```bash
php tools/generate_week_report.php <YYYY-MM-DD>
```

Repeat for each week. Process oldest to newest so the comparison context in each report reflects the correct set of prior weeks.

**Re-generation note:** Per-week reports are stable — each report only compares a week to its preceding weeks, so adding a new week does not invalidate existing reports. When running after a new week is ingested, you only need to generate the report for the new week.

### 5. Verify the cross-week analysis prompt

`tools/generate_analysis.php` builds its data notes dynamically from the metrics, so no manual editing is required for routine runs. However, briefly verify:
- The DST rule in the prompt is correct ("second Sunday in March through first Sunday in November")
- No structural changes to the prompt are needed (e.g., new report sections required by the user)

If the prompt needs structural changes, edit `generate_analysis.php` before continuing. For routine runs, proceed directly to step 6.

#### Required cross-week report structure

The tool enforces this structure via its prompt. It is documented here as the canonical reference for reviewing output and detecting prompt drift:

The report must begin with:
```
# Cross-Week Analysis Report: <first-week-end> to <last-week-end>
**Weeks covered:** <first-week-end> to <last-week-end>
```

| # | Section | Required table |
|---|---------|----------------|
| 1 | Executive Summary | None — 3–5 sentence narrative |
| 2 | Deployment Pattern Analysis | `\| Space \| <week1> \| <week2> \| ... \|` — rows: prod, stage |
| 3 | CF Events Activity | `\| Event Type \| <week1> \| <week2> \| ... \|` — all event types, 0 for absent weeks |
| 4 | SSH Activity | `\| Metric \| <week1> \| <week2> \| ... \|` — rows: Sessions Started, Sessions Ended |
| 5 | CF API Messages | `\| Week \| Row Count \|` — one row per week |
| 6 | Proxy Traffic | **Allowed:** `\| Week \| Row Count \| At Cap \|`; **Denied:** `\| Week \| Denied Count \|` |
| 7 | ModSecurity / WAF Activity | `\| Week \| Total Events \|` — one row per week |
| 8 | Data Quality Notes | None |
| 9 | Conclusion | None |

Section headings must be numbered (`## 1. Executive Summary`, etc.). Week column headers must use actual week-end dates, not generic labels like "Week 1".

If the generated report is missing tables or uses incorrect section names, the prompt in `generate_analysis.php` has drifted — edit it to restore compliance with this spec before regenerating.

### 6. Run the cross-week analysis

```bash
php tools/generate_analysis.php
```

This reads all `.tmp/*-metrics.json` files in date order, generates data notes dynamically, calls `call_ai.php`, writes the report to `output/<last-week-end>-report.md`, and automatically exports HTML and PDF.

Retry up to once on API failure. If it fails again, the raw prompt is saved to `.tmp/analysis-prompt.txt` and can be submitted manually.

**Regenerating a historical report:** To regenerate a cross-week report for a specific past date (e.g., to fix format drift in an older report), use `--through` to scope the analysis to only weeks up to and including that date:

```bash
php tools/generate_analysis.php --through 2026-03-30
```

### 7. Verify the cross-week report

HTML and PDF are generated automatically by `generate_analysis.php` — no separate export step needed. Check the output:
- `output/<YYYY-MM-DD>-report.md` — Markdown with 9 numbered sections and all required tables
- `output/<YYYY-MM-DD>-report.html` — self-contained HTML
- `output/<YYYY-MM-DD>-report.pdf` — PDF

### 8. Update the shareable package

```bash
php tools/generate_shareable_package.php
```

This regenerates `output/index.html` and `output/audit-stats.zip` to reflect all current reports. Run this every time after completing the analysis cycle.

### 9. Review the outputs

**Per-week reports** — for each week, verify:
- The week summary accurately characterises the week (typical / heavier / lighter)
- The deployment section confirms correct timing classification (EST vs EDT)
- Comparative observations reference the correct prior weeks
- Data quality issues specific to that week are noted

**Cross-week report** — verify:
- The executive summary reflects actual trends across all weeks
- Week-over-week comparison tables are consistent with `output/summary.json`
- Flagged anomalies match what was observed during parsing
- The conclusion is appropriately hedged where data gaps exist (e.g., proxy_allowed cap, missing files)

## Outputs

| File | Description |
|---|---|
| `output/<YYYY-MM-DD>-week-report.md` | Per-week report with comparative analysis (one per week) |
| `output/<YYYY-MM-DD>-week-report.html` | Self-contained HTML version of the per-week report |
| `output/<YYYY-MM-DD>-week-report.pdf` | PDF version of the per-week report |
| `output/<last-week-end>-report.md` | Full cross-week analysis in Markdown |
| `output/<last-week-end>-report.html` | Self-contained HTML version of the cross-week report |
| `output/<last-week-end>-report.pdf` | PDF version of the cross-week report |
| `output/summary.json` | Machine-readable week-over-week metrics (updated in step 3) |
| `output/index.html` | Navigation page linking all reports (updated in step 8) |
| `output/audit-stats.zip` | Zip archive of all output files for sharing (updated in step 8) |

## Error handling

- **Missing metrics file for a week**: parse it first (step 2); do not skip weeks — both the per-week and cross-week prompts depend on complete data
- **API failure on a per-week report**: retry once; if it fails again, skip that week and continue with the remaining weeks — the cross-week report does not depend on the per-week reports
- **API failure on cross-week report**: retry once; if it fails again, save `.tmp/analysis-prompt.txt` and alert the user — the raw metrics are complete and the prompt can be submitted manually
- **Missing prior-week data in a per-week report**: ensure `build_summary.php` was run (step 3) before generating per-week reports

## Relationship to ingest-week

| | ingest-week | analyze-all-weeks |
|---|---|---|
| Scope | One week | All weeks |
| Per-week report | `generate_week_report.php` (one week) | `generate_week_report.php` (each week, step 4) |
| Cross-week report | Not produced | `generate_analysis.php` (step 6) |
| Output names | `<YYYY-MM-DD>-week-report.*` | Both `*-week-report.*` and `*-report.*` |
| Prompt | Fully auto-generated | Per-week: auto-generated; cross-week: auto-generated with manual structural option |
| When to run | Each time a new week is ingested | After ingesting one or more weeks, or on demand |

Run `ingest-week` first for any new weeks, then run this workflow to produce the updated per-week and cross-week reports.
