<?php

require_once __DIR__ . '/env.php';

function loadFullConfig(): array {
    $configPath = __DIR__ . '/config.php';
    if (!is_readable($configPath)) {
        throw new RuntimeException('config.php not found');
    }
    $config = require $configPath;
    if (!is_array($config)) {
        throw new RuntimeException('config.php must return an array');
    }
    return applyEnvConfig($config, __DIR__ . '/.env');
}
