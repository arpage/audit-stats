<?php

/**
 * markdown_to_html.php
 *
 * Converts a Markdown file to a self-contained HTML file using pandoc.
 *
 * Usage:
 *   php tools/markdown_to_html.php <input.md> [output.html]
 *
 * If output path is omitted, the HTML is written alongside the input file.
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/markdown_to_html.php <input.md> [output.html]\n");
    exit(1);
}

$input = $argv[1];

if (!file_exists($input)) {
    fwrite(STDERR, "Error: File not found: $input\n");
    exit(1);
}

if (!str_ends_with(strtolower($input), '.md')) {
    fwrite(STDERR, "Error: Input file must have a .md extension.\n");
    exit(1);
}

$output = $argc >= 3 ? $argv[2] : preg_replace('/\.md$/i', '.html', $input);

$inputEscaped  = escapeshellarg($input);
$outputEscaped = escapeshellarg($output);

// --standalone   → complete HTML document with <head>/<body>
// --embed-resources → inline CSS/fonts (--self-contained on older pandoc)
// --metadata title → set the page <title> from the filename
$title      = escapeshellarg(basename($input, '.md'));
$baseFlags  = "pandoc $inputEscaped -o $outputEscaped --standalone --metadata title=$title --variable maxwidth=72em";

exec("$baseFlags --embed-resources 2>&1", $outputLines, $exitCode);
if ($exitCode !== 0) {
    exec("$baseFlags --self-contained 2>&1", $outputLines, $exitCode);
}

if ($exitCode !== 0) {
    fwrite(STDERR, "Error: pandoc failed:\n" . implode("\n", $outputLines) . "\n");
    exit(1);
}

echo "HTML created: $output\n";
exit(0);
