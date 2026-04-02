# Workflow: Call Claude API

## Objective
Send a prompt to the Claude API and receive a response. Use this as the foundation for any task requiring AI reasoning, summarization, analysis, classification, or content generation.

## Required Inputs
- A user prompt (the question or instruction)

## Optional Inputs
- A system prompt (sets Claude's role or context for the task)
- An output file path (recommended: `.tmp/<name>.json`)

## Steps

1. Run the tool:
   ```bash
   php tools/call_claude.php "<prompt>" ["<system_prompt>"] [output_file]
   ```

2. The tool outputs JSON with:
   - `model` — the Claude model used
   - `prompt` — the user prompt sent
   - `system` — the system prompt (null if none)
   - `response` — Claude's text response
   - `usage.input_tokens` / `usage.output_tokens` — token counts for cost tracking
   - `created_at` — ISO 8601 timestamp

3. Extract `response` from the JSON for use in subsequent steps.

## Examples

Simple question:
```bash
php tools/call_claude.php "Summarize the key ideas of Paulo Freire's Pedagogy of the Oppressed"
```

With a system prompt:
```bash
php tools/call_claude.php \
  "What are the main critiques of this author?" \
  "You are an academic research assistant specialising in critical pedagogy." \
  .tmp/freire_critique.json
```

## Model & Defaults
- **Model:** `claude-opus-4-6` — Anthropic's most capable model
- **Thinking:** Adaptive (Claude decides how deeply to reason based on the complexity of the task)
- **Max tokens:** 16,000 output tokens

## API Key
The tool reads `ANTHROPIC_API_KEY` from `.env`. That file is gitignored and must never be committed.

## When to Use This Tool
- Summarisation, analysis, classification, Q&A — any single-turn reasoning task
- As a building block inside larger workflows that need AI judgment at one step

## When NOT to Use This Tool
- When the task requires Claude to call other tools (web search, file access) — extend the tool or build a new one with tool-use support
- When processing many items in a batch — consider a loop or the Batches API to manage cost and rate limits
- For very long documents that exceed the context window — chunk the input first

## Cost Awareness
Each call consumes tokens. Check `usage.input_tokens` and `usage.output_tokens` in the output to track spend. Claude Opus 4.6 is priced at $5/M input tokens and $25/M output tokens.
