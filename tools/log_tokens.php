<?php

/**
 * log_tokens.php
 *
 * Shared helper — appends one JSONL line to output/.token_log.jsonl for
 * every Claude API call made by WAT tools.
 *
 * Include with:
 *   require_once __DIR__ . '/log_tokens.php';
 *
 * Then call after a successful $message = $client->messages->create():
 *   log_tokens($message, 'generate_markdown', 'angela_davis.json');
 */

/**
 * Per-million-token pricing table (USD).
 * Add new model IDs here as needed.
 */
function token_pricing(string $model): array {
    $table = [
        // Anthropic
        'claude-opus-4-6'     => ['input' => 15.00, 'output' => 75.00],
        'claude-opus-4-5'     => ['input' => 15.00, 'output' => 75.00],
        'claude-sonnet-4-6'   => ['input' =>  3.00, 'output' => 15.00],
        'claude-sonnet-4-5'   => ['input' =>  3.00, 'output' => 15.00],
        'claude-haiku-4-5'    => ['input' =>  0.80, 'output' =>  4.00],
        // OpenAI
        'gpt-4o'              => ['input' =>  2.50, 'output' => 10.00],
        'gpt-4o-mini'         => ['input' =>  0.15, 'output' =>  0.60],
        'gpt-4.1'             => ['input' =>  2.00, 'output' =>  8.00],
        'gpt-4.1-mini'        => ['input' =>  0.40, 'output' =>  1.60],
        'o3'                  => ['input' => 10.00, 'output' => 40.00],
        'o4-mini'             => ['input' =>  1.10, 'output' =>  4.40],
    ];

    foreach ($table as $prefix => $prices) {
        if (str_starts_with($model, $prefix)) return $prices;
    }

    // Unknown model — return zeros so cost shows as 0.0000 rather than crashing
    return ['input' => 0.0, 'output' => 0.0];
}

/**
 * Append a token-usage record to output/.token_log.jsonl.
 *
 * @param object $message  The response object from $client->messages->create()
 * @param string $tool     Name of the calling tool (e.g. 'generate_markdown')
 * @param string $context  Human-readable label for what was processed (e.g. 'angela_davis.json')
 */
function log_tokens(object $message, string $tool, string $context): void {
    $inputTok  = $message->usage->inputTokens;
    $outputTok = $message->usage->outputTokens;
    $model     = $message->model;

    $prices    = token_pricing($model);
    $inputCost  = $inputTok  / 1_000_000 * $prices['input'];
    $outputCost = $outputTok / 1_000_000 * $prices['output'];

    $entry = json_encode([
        'timestamp'       => date('c'),
        'tool'            => $tool,
        'context'         => $context,
        'model'           => $model,
        'input_tokens'    => $inputTok,
        'output_tokens'   => $outputTok,
        'input_cost_usd'  => round($inputCost,  6),
        'output_cost_usd' => round($outputCost, 6),
        'total_cost_usd'  => round($inputCost + $outputCost, 6),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Write to output/.token_log.jsonl relative to cwd
    $logDir  = getcwd() . '/output';
    $logFile = $logDir . '/.token_log.jsonl';

    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    file_put_contents($logFile, $entry . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Log token usage from raw values (provider-agnostic).
 * Use this when the response is not an Anthropic SDK object.
 *
 * @param string $model       Model ID as returned by the API
 * @param string $tool        Name of the calling tool
 * @param string $context     Human-readable label for what was processed
 * @param int    $inputTok    Input token count
 * @param int    $outputTok   Output token count
 */
function log_tokens_raw(string $model, string $tool, string $context, int $inputTok, int $outputTok): void {
    $prices     = token_pricing($model);
    $inputCost  = $inputTok  / 1_000_000 * $prices['input'];
    $outputCost = $outputTok / 1_000_000 * $prices['output'];

    $entry = json_encode([
        'timestamp'       => date('c'),
        'tool'            => $tool,
        'context'         => $context,
        'model'           => $model,
        'input_tokens'    => $inputTok,
        'output_tokens'   => $outputTok,
        'input_cost_usd'  => round($inputCost,  6),
        'output_cost_usd' => round($outputCost, 6),
        'total_cost_usd'  => round($inputCost + $outputCost, 6),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $logDir  = getcwd() . '/output';
    $logFile = $logDir . '/.token_log.jsonl';

    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    file_put_contents($logFile, $entry . "\n", FILE_APPEND | LOCK_EX);
}
