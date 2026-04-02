<?php

/**
 * call_claude.php
 *
 * Send a prompt to the Claude API and return the response as JSON.
 *
 * Usage:
 *   php tools/call_claude.php <prompt> [system_prompt] [output_file] [model]
 *   php tools/call_claude.php --prompt-file=<path> [system_prompt] [output_file] [model]
 *
 * Arguments:
 *   prompt        - The user message to send (required), or --prompt-file=<path> to read from file
 *   system_prompt - Optional system prompt to set Claude's role/context
 *   output_file   - Optional path to save JSON output (defaults to stdout)
 *   model         - Optional model ID (default: claude-opus-4-6)
 *
 * Output format: JSON with keys: model, prompt, system, response, usage, created_at
 *
 * Environment:
 *   ANTHROPIC_API_KEY - required, loaded from .env if not already set
 */

require_once __DIR__ . '/../vendor/autoload.php'; // meta-level vendor
require_once __DIR__ . '/log_tokens.php';
require_once __DIR__ . '/load_env.php';

use Anthropic\Client;

// --- Load .env (sub-project overrides meta) ---
// Meta-level .env loaded first; sub-project .env loaded second so its values
// take precedence.

// Load meta-level .env first (lowest priority)
load_env(__DIR__ . '/..');

// Load sub-project .env from cwd if different from meta root (higher priority)
$metaRoot   = realpath(__DIR__ . '/..');
$callerRoot = realpath(getcwd());
if ($callerRoot && $callerRoot !== $metaRoot) {
    load_env($callerRoot);
}

$apiKey = getenv('ANTHROPIC_API_KEY');
if (!$apiKey) {
    fwrite(STDERR, "Error: ANTHROPIC_API_KEY is not set. Add it to .env or the environment.\n");
    exit(1);
}

// --- Arguments ---

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/call_claude.php <prompt|--prompt-file=path> [system_prompt] [output_file] [model]\n");
    exit(1);
}

// Prompt: inline string or --prompt-file=<path>
if (str_starts_with($argv[1], '--prompt-file=')) {
    $promptFile = substr($argv[1], strlen('--prompt-file='));
    if (!file_exists($promptFile)) {
        fwrite(STDERR, "Error: prompt file not found: $promptFile\n");
        exit(1);
    }
    $prompt = file_get_contents($promptFile);
} else {
    $prompt = $argv[1];
}

$systemPrompt = ($argv[2] ?? '') !== '' ? $argv[2] : null;
$outputFile   = ($argv[3] ?? '') !== '' ? $argv[3] : null;
$model        = ($argv[4] ?? '') !== '' ? $argv[4] : 'claude-opus-4-6';

// --- Call Claude ---

$client = new Client(apiKey: $apiKey);

$params = [
    'model'     => $model,
    'maxTokens' => 16000,
    'thinking'  => ['type' => 'adaptive'],
    'messages'  => [
        ['role' => 'user', 'content' => $prompt],
    ],
];

if ($systemPrompt) {
    $params['system'] = $systemPrompt;
}

try {
    $message = $client->messages->create(...$params);
} catch (\Exception $e) {
    fwrite(STDERR, "Error calling Claude API: " . $e->getMessage() . "\n");
    exit(1);
}

// --- Extract response text ---

$responseText = '';
foreach ($message->content as $block) {
    if ($block->type === 'text') {
        $responseText = $block->text;
        break;
    }
}

// --- Log token usage ---

$context = $outputFile ?? (str_starts_with($argv[1], '--prompt-file=') ? $argv[1] : 'direct');
log_tokens($message, 'call_claude', $context);

// --- Build output ---

$output = json_encode([
    'model'      => $message->model,
    'prompt'     => $prompt,
    'system'     => $systemPrompt,
    'response'   => $responseText,
    'usage'      => [
        'input_tokens'  => $message->usage->inputTokens,
        'output_tokens' => $message->usage->outputTokens,
    ],
    'created_at' => date('c'),
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
