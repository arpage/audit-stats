<?php

/**
 * call_ai.php
 *
 * Provider-agnostic AI call. Identical interface to call_claude.php.
 * Routes to Claude or any OpenAI-compatible API based on environment.
 *
 * Usage:
 *   php tools/call_ai.php <prompt> [system_prompt] [output_file] [model]
 *   php tools/call_ai.php --prompt-file=<path> [system_prompt] [output_file] [model]
 *
 * Arguments:
 *   prompt        - The user message to send (required), or --prompt-file=<path> to read from file
 *   system_prompt - Optional system prompt
 *   output_file   - Optional path to save JSON output (defaults to stdout)
 *   model         - Optional model ID override
 *
 * Output format: JSON with keys: model, prompt, system, response, usage, created_at
 *
 * Environment:
 *   AI_PROVIDER       - 'claude' (default) or 'openai'
 *   AI_MODEL          - Model ID override (provider default used if not set)
 *   ANTHROPIC_API_KEY - Required when AI_PROVIDER=claude
 *   OPENAI_API_KEY    - Required when AI_PROVIDER=openai
 *   OPENAI_BASE_URL   - Optional; defaults to https://api.openai.com/v1
 *                       Set this to use any OpenAI-compatible provider (Gemini, Groq, etc.)
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/log_tokens.php';
require_once __DIR__ . '/load_env.php';

// Load .env (sub-project overrides meta, same pattern as call_claude.php)
load_env(__DIR__ . '/..');
$metaRoot   = realpath(__DIR__ . '/..');
$callerRoot = realpath(getcwd());
if ($callerRoot && $callerRoot !== $metaRoot) {
    load_env($callerRoot);
}

// --- Arguments ---

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/call_ai.php <prompt|--prompt-file=path> [system_prompt] [output_file] [model]\n");
    exit(1);
}

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
$modelArg     = ($argv[4] ?? '') !== '' ? $argv[4] : null;

$provider = strtolower(getenv('AI_PROVIDER') ?: 'claude');
$envModel = getenv('AI_MODEL') ?: null;
$model    = $modelArg ?? $envModel;  // CLI arg > AI_MODEL env > provider default below

$context = $outputFile ?? (str_starts_with($argv[1], '--prompt-file=') ? $argv[1] : 'direct');

// --- Route to provider ---

if ($provider === 'claude') {

    $apiKey = getenv('ANTHROPIC_API_KEY');
    if (!$apiKey) {
        fwrite(STDERR, "Error: ANTHROPIC_API_KEY is not set. Add it to .env or the environment.\n");
        exit(1);
    }

    if (!$model) $model = 'claude-opus-4-6';

    $client = new \Anthropic\Client(apiKey: $apiKey);

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

    $responseText = '';
    foreach ($message->content as $block) {
        if ($block->type === 'text') {
            $responseText = $block->text;
            break;
        }
    }

    $inputTokens  = $message->usage->inputTokens;
    $outputTokens = $message->usage->outputTokens;
    $modelUsed    = $message->model;

    log_tokens_raw($modelUsed, 'call_ai', $context, $inputTokens, $outputTokens);

} elseif ($provider === 'openai') {

    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) {
        fwrite(STDERR, "Error: OPENAI_API_KEY is not set. Add it to .env or the environment.\n");
        exit(1);
    }

    $baseUrl = rtrim(getenv('OPENAI_BASE_URL') ?: 'https://api.openai.com/v1', '/');
    if (!$model) $model = 'gpt-4o';

    $messages = [];
    if ($systemPrompt) {
        $messages[] = ['role' => 'system', 'content' => $systemPrompt];
    }
    $messages[] = ['role' => 'user', 'content' => $prompt];

    $http = new \GuzzleHttp\Client();
    try {
        $res = $http->post("$baseUrl/chat/completions", [
            'headers' => [
                'Authorization' => "Bearer $apiKey",
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'model'    => $model,
                'messages' => $messages,
            ],
        ]);
    } catch (\GuzzleHttp\Exception\RequestException $e) {
        $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();
        fwrite(STDERR, "Error calling OpenAI-compatible API: $body\n");
        exit(1);
    }

    $data = json_decode((string) $res->getBody(), true);
    $responseText = $data['choices'][0]['message']['content'] ?? '';
    $inputTokens  = $data['usage']['prompt_tokens'] ?? 0;
    $outputTokens = $data['usage']['completion_tokens'] ?? 0;
    $modelUsed    = $data['model'] ?? $model;

    log_tokens_raw($modelUsed, 'call_ai', $context, $inputTokens, $outputTokens);

} else {
    fwrite(STDERR, "Error: unknown AI_PROVIDER '$provider'. Supported values: claude, openai\n");
    exit(1);
}

// --- Build output (shared) ---

$output = json_encode([
    'model'      => $modelUsed,
    'prompt'     => $prompt,
    'system'     => $systemPrompt,
    'response'   => $responseText,
    'usage'      => [
        'input_tokens'  => $inputTokens,
        'output_tokens' => $outputTokens,
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
