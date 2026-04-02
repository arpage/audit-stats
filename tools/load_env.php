<?php

/**
 * load_env.php
 *
 * Shared helper: loads key=value pairs from a .env file into the process
 * environment. Safe to call multiple times; later calls override earlier ones,
 * so sub-project .env files take precedence over meta-level defaults when
 * loaded second.
 */

function load_env(string $dir): void {
    $envFile = "$dir/.env";
    if (!file_exists($envFile)) return;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($value));
    }
}
