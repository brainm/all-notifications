<?php

/**
 * Минимальный парсер .env (KEY=VALUE, кавычки опциональны).
 */
function loadDotEnv(string $path): array {
    if (!is_readable($path)) {
        if (file_exists($path)) {
            error_log("[all-notifications] .env exists but is not readable: {$path}");
        }
        return [];
    }

    $vars = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));
        if (
            strlen($value) >= 2
            && (($value[0] === '"' && str_ends_with($value, '"'))
                || ($value[0] === "'" && str_ends_with($value, "'")))
        ) {
            $value = substr($value, 1, -1);
        }
        $vars[$key] = $value;
    }

    return $vars;
}

function applyEnvConfig(array $config, string $envPath): array {
    $env = loadDotEnv($envPath);

    $login = trim($env['ADMIN_LOGIN'] ?? '');
    $password = (string) ($env['ADMIN_PASSWORD'] ?? '');
    if ($login === '' || $password === '') {
        throw new RuntimeException('.env: ADMIN_LOGIN and ADMIN_PASSWORD required');
    }
    $config['admin_config'] = [
        'username' => $login,
        'password' => $password,
    ];

    $publicKey = trim($env['WEB_PUSH_PUBLIC_KEY'] ?? '');
    $privateKey = (string) ($env['WEB_PUSH_PRIVATE_KEY'] ?? '');
    if ($publicKey === '' || $privateKey === '') {
        throw new RuntimeException('.env: WEB_PUSH_PUBLIC_KEY and WEB_PUSH_PRIVATE_KEY required');
    }
    if (!isset($config['web_push_config']) || !is_array($config['web_push_config'])) {
        $config['web_push_config'] = [];
    }
    if (!isset($config['web_push_config']['vapid']) || !is_array($config['web_push_config']['vapid'])) {
        $config['web_push_config']['vapid'] = [];
    }
    $config['web_push_config']['vapid']['publicKey'] = $publicKey;
    $config['web_push_config']['vapid']['privateKey'] = $privateKey;

    return $config;
}
