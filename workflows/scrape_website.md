# Workflow: Scrape Website

## Objective
Fetch content from a web page and extract specific elements or the full page text.

## Required Inputs
- Target URL (must include `http://` or `https://`)

## Optional Inputs
- CSS selector to extract specific elements (e.g. `h1`, `article p`, `.post-body`, `#main-content`)
- Output file path (defaults to stdout; recommended: `.tmp/<name>.json`)

## Steps

1. Run the tool:
   ```bash
   php tools/scrape_website.php <url> [selector] [output_file]
   ```

2. The tool outputs JSON with:
   - `url` — the page fetched
   - `selector` — the selector used (null if none)
   - `http_code` — HTTP status code
   - `count` — number of matching elements
   - `results[]` — each with `text` (plain text) and `html` (raw HTML)
   - `fetched_at` — ISO 8601 timestamp

3. Inspect the results and pass relevant content to subsequent steps.

## Examples

Fetch full page text:
```bash
php tools/scrape_website.php https://example.com
```

Extract all paragraphs inside an article:
```bash
php tools/scrape_website.php https://example.com/article "article p" .tmp/article.json
```

Extract a specific section by ID:
```bash
php tools/scrape_website.php https://example.com "#main-content" .tmp/main.json
```

## Selector Support
The tool supports basic CSS selectors:
- Tag: `p`, `h1`, `table`
- ID: `#main`
- Class: `.highlight`
- Combined: `div.content`, `section#intro`
- Descendant: `article p`
- Child: `div > p`

For complex selectors (`:nth-child`, attribute selectors, etc.), refine by post-processing the JSON output.

## When to Use This Tool

**Good fit:**
- Pages where the meaningful content is present in the raw HTML source (viewable via browser "View Source")
- Static sites, blogs, Wikipedia, news articles, government/academic pages
- Pages that load content server-side (traditional CMS, PHP, Ruby on Rails, etc.)

**Not appropriate — use a headless browser instead:**
- Single-page applications (React, Vue, Angular) where content is injected by JavaScript after load
- Pages that require a login session or cookie-based authentication
- Pages behind a CAPTCHA or bot-detection wall
- Content loaded via infinite scroll or lazy-load triggers
- Any page where "View Source" shows a near-empty `<body>` or only a `<div id="app">`

**Quick check:** Before running this tool, view the page source in a browser. If the target content is visible there, this tool will work. If not, it won't.

## Edge Cases
- If the page returns a non-2xx status, the tool exits with an error — check if the URL requires authentication or returns a redirect loop.
- Some sites block scrapers by User-Agent or rate-limit requests — check for HTTP 403/429 responses and adjust accordingly.
- Output is saved to `.tmp/` by convention; these files are disposable and regenerable.
