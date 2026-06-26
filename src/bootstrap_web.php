<?php

function loadAppConfig(): array {
    require_once __DIR__ . '/config_loader.php';
    $config = loadFullConfig();
    foreach (['log_file', 'db_config', 'web_push_config', 'admin_config'] as $key) {
        if (!array_key_exists($key, $config)) {
            throw new RuntimeException("config.php: missing key \"{$key}\"");
        }
    }
    return $config;
}

function isAppDebugEnabled(): bool {
    static $debug = null;
    if ($debug !== null) {
        return $debug;
    }
    $envPath = __DIR__ . '/.env';
    if (!is_readable($envPath)) {
        $debug = false;
        return $debug;
    }
    if (!function_exists('loadDotEnv')) {
        require_once __DIR__ . '/env.php';
    }
    $env = loadDotEnv($envPath);
    $debug = in_array(strtolower($env['APP_DEBUG'] ?? ''), ['1', 'true', 'yes'], true);
    return $debug;
}

function handleWebBootstrapError(Throwable $e): never {
    error_log('[all-notifications] bootstrap failed: ' . $e->getMessage());
    http_response_code(500);

    $detail = $e->getMessage();
    if (str_contains($detail, 'config.php not found') || str_contains($detail, 'not readable')) {
        die('Configuration error: config.php not found on server. Copy config.example.php to config.php.');
    }
    if (isAppDebugEnabled()) {
        die('Configuration error: ' . $detail);
    }
    die('Configuration error');
}

function initWebApp(): array {
    $config = loadAppConfig();
    global $log_file;
    $log_file = $config['log_file'];
    require_once __DIR__ . '/send_functions.php';
    require_once __DIR__ . '/queue.php';
    require_once __DIR__ . '/web.php';
    require_once __DIR__ . '/auth.php';
    $pdo = notificationQueuePdo($config['db_config']);
    return [$config, $pdo];
}

function h(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
