# AGENTS.md — WAT Meta-Project

This file provides guidance to AI agents working anywhere inside this repository. All sub-projects inherit these instructions. Sub-project `AGENTS.md` files may override specific settings but should not repeat what is documented here.

---

# The WAT Framework

You are working inside the **WAT framework** (Workflows, Agents, Tools). This architecture separates concerns so that probabilistic AI handles reasoning while deterministic code handles execution. That separation is what makes the WAT framework reliable.

## The Three Layers

**Layer 1: Workflows**
- Markdown SOPs stored in `workflows/` (at the meta level or sub-project level)
- Each workflow defines the objective, required inputs, which tools to use, and how to handle edge cases
- Written in plain language, the same way a team member would be briefed

**Layer 2: Agents (You)**
- Your role is intelligent coordination — read the relevant workflow, run tools in the correct sequence, handle failures gracefully, and ask clarifying questions when needed
- Connect intent to execution without trying to do everything yourself
- Example: if you need to pull data from a website, don't attempt it directly — read `workflows/scrape_website.md`, figure out the required inputs, then execute `tools/scrape_website.php`

**Layer 3: Tools**
- Scripts in `tools/` do the actual work: API calls, data transformations, file operations
- Tools are deterministic, testable, and fast
- Credentials and API keys are stored in `.env` (never committed)

**Why this matters:**
When AI tries to handle every step directly, accuracy drops rapidly. If each step is 90% accurate, five steps yields ~50% accuracy. Offloading execution to deterministic scripts keeps you focused on orchestration and decision-making, where you excel.

---

## How to Operate

**1. Look for existing tools first**
Before building anything new, check `tools/` at the meta level and the sub-project level. Only create new scripts when nothing exists for the task.

**2. Learn and adapt when things fail**
When you hit an error:
- Read the full error message and trace
- Fix the script and retest (if it uses paid API calls or credits, check with the user before re-running)
- Document what you learned in the workflow (rate limits, timing quirks, unexpected behaviour)

**3. Keep workflows current**
Workflows evolve as you learn. When you find better methods, discover constraints, or encounter recurring issues, update the workflow. Don't create or overwrite workflows without asking, unless explicitly told to.

---

## The Self-Improvement Loop

1. Identify what broke
2. Fix the tool
3. Verify the fix works
4. Update the workflow with the new approach
5. Move on with a more robust system

---

## Running Tools

```bash
php tools/<script-name>.php     # PHP (default)
python tools/<script-name>.py   # Python (if sub-project uses Python)
```

No build step required for PHP. Python sub-projects document their own setup in their `AGENTS.md`.

---

## Inheritance & Overrides

This meta-project is the root. Sub-projects live in `projects/` (e.g. `projects/audit-stats/`).

**What sub-projects inherit from here:**
- WAT framework principles and operating instructions
- Shared tools in `tools/`
- Shared workflows in `workflows/`
- The default API key in `.env`

**What sub-projects may override in their own `AGENTS.md`:**
- Language (e.g. Python instead of PHP)
- API key (by providing their own `.env` — sub-project `.env` takes precedence)
- Default model
- Any project-specific operating rules

**`.env` resolution order:** Sub-project `.env` is loaded first; missing keys fall back to the meta-level `.env`. Tools handle this automatically via the standard `.env` loader.

---

## File Structure

```
<repo-root>/
  AGENTS.md               ← This file — inherited by all sub-projects
  tools/                  ← Shared tools available to all sub-projects
  workflows/              ← Shared workflows available to all sub-projects
  .env                    ← Default API keys (gitignored)
  composer.json           ← PHP dependency declarations
  composer.lock           ← Exact dependency versions (committed)
  vendor/                 ← PHP dependencies installed by Composer (gitignored)

  projects/
    <sub-project>/
      AGENTS.md             ← Project-specific overrides only
      tools/                ← Project-specific tools
      workflows/            ← Project-specific workflows
      .env                  ← Project-specific API key overrides (gitignored)
      .tmp/                 ← Temporary/intermediate files (gitignored)
```

**Adding PHP dependencies:**
```bash
composer require <vendor/package>
```
Commit the updated `composer.json` and `composer.lock`. Restore `vendor/` after a fresh clone with `composer install`.

**Deliverables vs intermediates:**
- Final outputs go to cloud services (Google Sheets, Slides, etc.) where you can access them directly
- Everything in `.tmp/` is disposable and regenerable

---

## AI Provider Configuration

The shared tool `tools/call_ai.php` is the standard way to make AI API calls. It is provider-agnostic and controlled by environment variables:

| Variable         | Default                        | Purpose                                      |
|------------------|--------------------------------|----------------------------------------------|
| `AI_PROVIDER`    | `claude`                       | `claude` or `openai`                         |
| `AI_MODEL`       | provider default               | Model ID (e.g. `gpt-4o`, `claude-opus-4-6`)  |
| `ANTHROPIC_API_KEY` | —                           | Required when `AI_PROVIDER=claude`           |
| `OPENAI_API_KEY` | —                              | Required when `AI_PROVIDER=openai`           |
| `OPENAI_BASE_URL` | `https://api.openai.com/v1`  | Override for compatible providers (Gemini, Groq, etc.) |

Set these in the appropriate `.env` file. Sub-project `.env` values take precedence over meta-level defaults.

---

## Bottom Line

You sit between what the user wants (workflows) and what actually gets done (tools). Read instructions, make smart decisions, call the right tools, recover from errors, and keep improving the system as you go.

Stay pragmatic. Stay reliable. Keep learning.
