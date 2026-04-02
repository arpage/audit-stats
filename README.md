# audit-stats — Quick Start

Analyze Cloud Foundry logs from New Relic to track deployment patterns and system activity.

## Prerequisites

- PHP 8.1+ with `composer`
- OpenAI or Anthropic API key

## Setup (5 minutes)

```bash
# 1. Install PHP dependencies
composer install

# 2. Configure your AI provider
cp .env.example .env          # or create .env manually
# Edit .env and add your API key:
#   OPENAI_API_KEY=sk-...     (for OpenAI)
#   or
#   ANTHROPIC_API_KEY=sk-ant-...  (for Claude)
```

## Log Gathering (more than 5 minutes...)
See [Log Gathering](./projects/audit-stats/workflows/human-log-gathering.md) for steps on aquiring the log files from NewRelic

we should have log data in the audit artifacts folders of Google Drive, for a quicker start

```
# 3. Place your weekly CSV log files
# Put files in: projects/audit-stats/log-files/YYYY-MM-DD/
# Expected files per week:
#   - YYYY-MM-DD-CFEvents-Logs.csv
#   - YYYY-MM-DD-CF-API-messages.csv
#   - YYYY-MM-DD-SSH-Activity.csv
#   - YYYY-MM-DD-Proxy-DENIED.csv
#   - YYYY-MM-DD-Proxy-allowed.csv
#   - YYYY-MM-DD-ModSecurity-Logs.csv
#   - YYYY-MM-DD-ModSecurity-Log-Messages.csv
```

## Analyze a Single Week

```bash
cd projects/audit-stats
php tools/parse_week.php YYYY-MM-DD
php tools/generate_week_report.php YYYY-MM-DD
```

Reports are generated in `output/` as Markdown, HTML, and PDF.

## Analyze All Weeks

```bash
cd projects/audit-stats
php tools/build_summary.php
```

## Key Files

| File/Directory | Purpose |
|---|---|
| `projects/audit-stats/log-files/` | Input: weekly CSV folders |
| `projects/audit-stats/output/` | Output: reports and summaries |
| `projects/audit-stats/workflows/` | SOP documentation |
| `tools/call_ai.php` | Shared AI provider tool |

## Expected Patterns

- **Production deployments**: Tuesdays ~8pm ET
- **Stage deployments**: Thursdays ~4:30pm ET

See `projects/audit-stats/AGENTS.md` for full details.
