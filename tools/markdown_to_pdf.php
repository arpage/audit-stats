<?php

/**
 * markdown_to_pdf.php
 *
 * Converts a Markdown file to PDF using pandoc.
 *
 * Usage:
 *   php tools/markdown_to_pdf.php <input.md> [output.pdf]
 *
 * If output path is omitted, the PDF is written alongside the input file.
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/markdown_to_pdf.php <input.md> [output.pdf]\n");
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

$output = $argc >= 3 ? $argv[2] : preg_replace('/\.md$/i', '.pdf', $input);

$inputEscaped  = escapeshellarg($input);
$outputEscaped = escapeshellarg($output);

$command = "pandoc $inputEscaped -o $outputEscaped 2>&1";
exec($command, $outputLines, $exitCode);

if ($exitCode !== 0) {
    fwrite(STDERR, "Error: pandoc failed:\n" . implode("\n", $outputLines) . "\n");
    exit(1);
}

echo "PDF created: $output\n";
exit(0);
