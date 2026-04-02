<?php

/**
 * scrape_website.php
 *
 * Generic web scraper. Fetches a URL and extracts content by CSS selector,
 * or returns the full page text/HTML if no selector is given.
 *
 * Usage:
 *   php tools/scrape_website.php <url> [selector] [output_file]
 *
 * Arguments:
 *   url         - The page to fetch (must include http:// or https://)
 *   selector    - Optional CSS selector to extract specific elements
 *                 Examples: "h1", "article p", "#main-content", ".post-body"
 *   output_file - Optional path to save output (defaults to stdout)
 *
 * Output format: JSON with keys: url, selector, results[], fetched_at
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/scrape_website.php <url> [selector] [output_file]\n");
    exit(1);
}

$url        = $argv[1];
$selector   = $argv[2] ?? null;
$outputFile = $argv[3] ?? null;

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    fwrite(STDERR, "Error: Invalid URL: $url\n");
    exit(1);
}

// --- Fetch the page ---

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; research-scraper/1.0)',
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_ENCODING       => '',   // accept compressed responses
]);

$html     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    fwrite(STDERR, "Error fetching URL: $curlErr\n");
    exit(1);
}

if ($httpCode < 200 || $httpCode >= 300) {
    fwrite(STDERR, "Error: HTTP $httpCode received for $url\n");
    exit(1);
}

// --- Parse and extract ---

$results = [];

if ($selector) {
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    $xpath    = new DOMXPath($doc);
    $xpathExpr = css_to_xpath($selector);
    $nodes    = $xpath->query($xpathExpr);

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
    'http_code'  => $httpCode,
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
    $parts = preg_split('/\s*>\s*/', $css); // split on child combinator
    $xparts = [];

    foreach ($parts as $i => $part) {
        // Each part may itself be space-separated (descendant)
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
    // tag#id.class or any combination
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
