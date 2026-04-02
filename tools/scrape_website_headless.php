<?php

/**
 * scrape_website_headless.php
 *
 * Headless-browser web scraper. Uses Chromium to fully render JavaScript before
 * extracting content. Drop-in replacement for scrape_website.php for JS-heavy pages.
 *
 * Usage:
 *   php tools/scrape_website_headless.php <url> [selector] [output_file]
 *
 * Arguments:
 *   url         - The page to fetch (must include http:// or https://)
 *   selector    - Optional CSS selector to extract specific elements
 *                 Examples: "h1", "article p", "#main-content", ".post-body"
 *   output_file - Optional path to save output (defaults to stdout)
 *
 * Output format: JSON with keys: url, selector, results[], fetched_at
 *
 * Requirements:
 *   chromium-browser must be installed and in PATH
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/scrape_website_headless.php <url> [selector] [output_file]\n");
    exit(1);
}

$url        = $argv[1];
$selector   = $argv[2] ?? null;
$outputFile = $argv[3] ?? null;

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    fwrite(STDERR, "Error: Invalid URL: $url\n");
    exit(1);
}

// --- Find Chromium ---

$chromiumCandidates = ['chromium-browser', 'chromium', 'google-chrome', 'google-chrome-stable'];
$chromium = null;
foreach ($chromiumCandidates as $candidate) {
    $path = trim(shell_exec("which $candidate 2>/dev/null"));
    if ($path) {
        $chromium = $path;
        break;
    }
}

if (!$chromium) {
    fwrite(STDERR, "Error: Chromium not found. Install chromium-browser and ensure it is in PATH.\n");
    exit(1);
}

// --- Helpers: detect JS-shell responses ---

/**
 * Returns true if the HTML looks like an unrendered JS-bundle shell rather than
 * real page content. Heuristics:
 *   - Readable text (after stripping tags) is very short
 *   - Known JS bundle / RUM agent signatures appear early in the document
 *   - The <script> content vastly outweighs text content
 */
function looks_like_js_shell(string $html): bool
{
    // Extract readable text
    $text = trim(strip_tags($html));
    $textLen = strlen($text);

    // If there's plenty of readable text, it's probably fine
    if ($textLen > 1000) return false;

    // Very short text is suspicious on its own
    if ($textLen < 200) return true;

    // Known signatures of unrendered SPA shells / RUM agents
    $signatures = [
        'window.NREUM',        // New Relic RUM
        'window.dataLayer',    // Google Tag Manager shell
        'webpack',             // Webpack bundle
        '__webpack_require__', // Webpack runtime
        'window.__NUXT__',     // Nuxt.js shell
        'window.__NEXT_DATA__',// Next.js shell (sometimes appears before hydration)
        'self.__next_f',       // Next.js streaming shell
        'ReactDOM.hydrate',    // React hydration marker
        'gtag(',               // Google Analytics only shell
    ];

    $htmlHead = substr($html, 0, 8000); // only check the top of the document
    foreach ($signatures as $sig) {
        if (strpos($htmlHead, $sig) !== false) return true;
    }

    return false;
}

// --- Fetch rendered HTML via Chromium headless ---

function fetch_with_chromium(string $chromium, string $urlEscaped, int $timeout, int $virtualTime = 0): ?string
{
    $vtFlag = $virtualTime > 0 ? "--virtual-time-budget=$virtualTime " : '';
    $command = "$chromium --headless --disable-gpu --no-sandbox --dump-dom "
             . "--timeout=$timeout "
             . $vtFlag
             . "--user-agent='Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' "
             . "$urlEscaped 2>/dev/null";
    $result = shell_exec($command);
    return ($result && strlen($result) > 100) ? $result : null;
}

$urlEscaped = escapeshellarg($url);

// Pass 1: standard fetch (fast)
$html   = fetch_with_chromium($chromium, $urlEscaped, 20000);
$engine = 'chromium-headless';

if (!$html) {
    fwrite(STDERR, "Error: Chromium returned no output for $url\n");
    exit(1);
}

// Pass 2: if pass 1 looks like an unrendered JS shell, retry with virtual-time-budget
if (looks_like_js_shell($html)) {
    fwrite(STDERR, "Warning: initial fetch looks like a JS shell — retrying with virtual-time-budget\n");
    $html2 = fetch_with_chromium($chromium, $urlEscaped, 60000, 15000);
    if ($html2 && !looks_like_js_shell($html2)) {
        $html   = $html2;
        $engine = 'chromium-headless-vt'; // vt = virtual-time fallback
    } else {
        fwrite(STDERR, "Warning: virtual-time retry also looks like a JS shell — content may be incomplete\n");
    }
}

// --- Parse and extract ---

$results = [];

if ($selector) {
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    $xpath     = new DOMXPath($doc);
    $xpathExpr = css_to_xpath($selector);
    $nodes     = $xpath->query($xpathExpr);

    if ($nodes === false) {
        fwrite(STDERR, "Error: Could not evaluate selector: $selector\n");
        exit(1);
    }

    foreach ($nodes as $node) {
        $results[] = [
            'text' => trim($node->textContent),
            'html' => $doc->saveHTML($node),
        ];
    }
} else {
    // No selector — return full cleaned text
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $results[] = [
        'text' => trim($doc->textContent),
        'html' => $html,
    ];
}

// --- Output ---

$output = json_encode([
    'url'        => $url,
    'selector'   => $selector,
    'engine'     => $engine,
    'count'      => count($results),
    'results'    => $results,
    'fetched_at' => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($outputFile) {
    $dir = dirname($outputFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($outputFile, $output);
    fwrite(STDERR, "Output saved to: $outputFile\n");
} else {
    echo $output . "\n";
}

exit(0);

// --- Helpers ---

/**
 * Converts a basic CSS selector to an XPath expression.
 * Supports: tag, #id, .class, tag.class, tag#id, descendant (space), and > child combinator.
 */
function css_to_xpath(string $css): string
{
    $css   = trim($css);
    $parts = preg_split('/\s*>\s*/', $css);
    $xparts = [];

    foreach ($parts as $i => $part) {
        $descendants = preg_split('/\s+/', trim($part));
        $xdesc = [];
        foreach ($descendants as $j => $token) {
            $xdesc[] = token_to_xpath($token);
        }
        $xparts[] = implode('//', $xdesc);
    }

    return '//' . implode('/', $xparts);
}

function token_to_xpath(string $token): string
{
    $tag   = '*';
    $id    = null;
    $class = null;

    if (preg_match('/^([a-zA-Z][a-zA-Z0-9]*)/', $token, $m)) {
        $tag   = $m[1];
        $token = substr($token, strlen($m[1]));
    }

    if (preg_match('/#([a-zA-Z0-9_-]+)/', $token, $m)) {
        $id = $m[1];
    }

    if (preg_match('/\.([a-zA-Z0-9_-]+)/', $token, $m)) {
        $class = $m[1];
    }

    $conditions = [];
    if ($id)    $conditions[] = "@id='$id'";
    if ($class) $conditions[] = "contains(concat(' ', normalize-space(@class), ' '), ' $class ')";

    return $tag . ($conditions ? '[' . implode(' and ', $conditions) . ']' : '');
}
