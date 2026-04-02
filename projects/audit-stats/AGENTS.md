# AGENTS.md — audit-stats

This sub-project inherits all WAT framework instructions from the root `AGENTS.md`. Only project-specific overrides are documented here.

## Purpose

You are an agent assisting with analyzing Cloud Foundry logs, pulled weekly from New Relic into several `.csv` files locally.
You are interested in analyzing the files to determine if the pattern of activities logged in the `.csv` files remains consistent over time.

## General information about the system where the logs are generated

1. The system is container based, with each component running as an app in a Cloud Foundry Space
1. There are 6 Cloud Foundry spaces: dr, dev, stage, prod, shared-egress, tools
1. CMS container running Drupal and nginx
1. WAF container running nginx w/ ModSecurity rules
1. cron container which periodically collects Cloud Foundry Event API messages
1. WWW container which serves a static version of the site, generated as needed by the Drupal module Tome
1. AnalyticsReporter container which gathers stats on page hits for the system

## General information about the files collected each week

1. The files are sent to New Relic from an application (the app named cron) which queries the Cloud Foundry Event API periodically
1. Files cover one week + 1 hour: from 12:00am Monday morning to 1:00am the following Monday morning
1. The dates and timestamps in the files are in UTC timezone
1. The filenames will all be prefixed with the end date of the week-long period (e.g. `2026-03-23-file.csv` covers 2026-03-16 00:00 UTC to 2026-03-23 01:00 UTC)

## Specific information about each file collected every week

One of each of the following files is collected per week:

| Filename pattern | Contents |
|---|---|
| `YYYY-MM-DD-CFEvents-Logs.csv` | Each event captured from the Cloud Foundry Event API |
| `YYYY-MM-DD-CF-API-messages.csv` | Detailed info, warning, and error messages from the CF Event API |
| `YYYY-MM-DD-SSH-Activity.csv` | Each `cf ssh` event captured from the CF Event API |
| `YYYY-MM-DD-Proxy-DENIED.csv` | Proxy connection failures |
| `YYYY-MM-DD-Proxy-allowed.csv` | Successful proxy connections |
| `YYYY-MM-DD-ModSecurity-Logs.csv` | Raw ModSecurity rule violation log entries |
| `YYYY-MM-DD-ModSecurity-Log-Messages.csv` | Aggregated counts per violation type (Modsecurity.data, Modsecurity.message, Count) |

## Expected patterns in the data

1. Production deployments occur on Tuesdays at approximately 8pm Eastern (Daylight or Standard) time.
1. Stage deployments occur on Thursdays at approximately 4:30pm Eastern (Daylight or Standard) time.

## File layout

```
projects/audit-stats/
  log-files/
    <YYYY-MM-DD>/          ← one folder per week, named by the week-end date
      YYYY-MM-DD-CFEvents-Log.csv
      YYYY-MM-DD-CF-API-messages.csv
      YYYY-MM-DD-SSH-Activity.csv
      YYYY-MM-DD-Proxy-DENIED.csv
      YYYY-MM-DD-Proxy-allowed.csv
      YYYY-MM-DD-ModSecurityLogs.csv
      YYYY-MM-DD-ModSecurityLogMessages.csv
  output/                  ← analysis outputs (gitignored)
  tools/                   ← project-specific tools
  workflows/               ← project-specific SOPs
```

## Overrides

- **Model:** set `AI_MODEL` in `.env` (default: provider default from `call_ai.php`)
- **Provider:** set `AI_PROVIDER` in `.env` (`claude` or `openai`, default: `claude`)
- All other WAT defaults apply (PHP, meta-level `.env`)

## Shared Tools in Use

The following tools from `../../tools/` (meta-level) are available:

- `call_ai.php` — provider-agnostic AI reasoning, summarisation, analysis
- `markdown_to_pdf.php` — export Markdown to PDF
- `markdown_to_html.php` — export Markdown to self-contained HTML (72em width)
- `token_report.php` — print token cost summary from `output/.token_log.jsonl`
- `log_tokens.php` — shared token logging helper (used by tools internally)
- `load_env.php` — shared `.env` loader (used by tools internally)

Run from this directory:
```bash
php ../../tools/call_ai.php "<prompt>"
php ../../tools/markdown_to_pdf.php <file.md>
php ../../tools/markdown_to_html.php <file.md>
php ../../tools/token_report.php
```

## Project-Specific Tools

Located in `tools/`:

_(none yet — will be added as the project develops)_

## Deliverables

Analysis outputs live in `output/`:
- `<YYYY-MM-DD>-report.md` — weekly analysis report in Markdown
- `<YYYY-MM-DD>-report.html` — self-contained HTML version
- `<YYYY-MM-DD>-report.pdf` — PDF version
- `summary.json` — machine-readable cross-week summary (updated each run)
