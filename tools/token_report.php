<?php

/**
 * token_report.php
 *
 * Reads output/.token_log.jsonl and prints a cost summary grouped by context
 * (professor) and tool, plus a grand total.
 *
 * Usage:
 *   php tools/token_report.php [output_dir]
 *
 * output_dir defaults to ./output
 */

$outputDir = $argv[1] ?? (getcwd() . '/output');
$logFile   = $outputDir . '/.token_log.jsonl';

if (!file_exists($logFile)) {
    echo "No token log found at: $logFile\n";
    echo "Token logging is recorded automatically by call_claude.php and generate_markdown.php.\n";
    exit(0);
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$byContext = [];
$grandIn = $grandOut = $grandCost = 0;
$firstEntry = $lastEntry = null;

foreach ($lines as $line) {
    $e = json_decode($line, true);
    if (!$e) continue;
    $firstEntry ??= $e;
    $lastEntry = $e;

    // Normalise context to a short professor label
    $ctx = basename($e['context'] ?? 'unknown', '.json');
    $ctx = basename($ctx, '.php');

    if (!isset($byContext[$ctx])) {
        $byContext[$ctx] = ['calls' => 0, 'input' => 0, 'output' => 0, 'cost' => 0.0];
    }

    $byContext[$ctx]['calls']++;
    $byContext[$ctx]['input']  += $e['input_tokens']   ?? 0;
    $byContext[$ctx]['output'] += $e['output_tokens']  ?? 0;
    $byContext[$ctx]['cost']   += $e['total_cost_usd'] ?? 0.0;

    $grandIn   += $e['input_tokens']   ?? 0;
    $grandOut  += $e['output_tokens']  ?? 0;
    $grandCost += $e['total_cost_usd'] ?? 0.0;
}

// Sort by cost descending
uasort($byContext, fn($a, $b) => $b['cost'] <=> $a['cost']);

printf("\n%-30s %6s %10s %10s %10s\n", 'Context', 'Calls', 'In tok', 'Out tok', 'Cost USD');
echo str_repeat('-', 70) . "\n";

foreach ($byContext as $ctx => $r) {
    printf("%-30s %6d %10d %10d %10.4f\n",
        $ctx, $r['calls'], $r['input'], $r['output'], $r['cost']);
}

echo str_repeat('-', 70) . "\n";
printf("%-30s %6s %10d %10d %10.4f\n", 'TOTAL', '', $grandIn, $grandOut, $grandCost);

// Log date range
$first = $firstEntry['timestamp'] ?? '?';
$last  = $lastEntry['timestamp']  ?? '?';
echo "\nLog period: $first  →  $last\n";
echo 'Entries: ' . count($lines) . "\n\n";
