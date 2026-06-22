#!/usr/bin/env php
<?php
/**
 * Обработчик очереди отложенных уведомлений.
 * Запускается из cron.sh раз в минуту.
 */

$configPath = __DIR__ . '/config.php';
if (!is_readable($configPath)) {
    fwrite(STDERR, "config.php not found or not readable\n");
    exit(1);
}

$config = require $configPath;
if (!is_array($config)) {
    fwrite(STDERR, "config.php must return an array\n");
    exit(1);
}

foreach (['log_file', 'telegram_config', 'vk_config', 'matrix_config', 'rules', 'db_config'] as $key) {
    if (!array_key_exists($key, $config)) {
        fwrite(STDERR, "config.php: missing key \"{$key}\"\n");
        exit(1);
    }
}

$log_file = $config['log_file'];

require __DIR__ . '/send_functions.php';
require __DIR__ . '/queue.php';

try {
    $pdo = notificationQueuePdo($config['db_config']);
} catch (Throwable $e) {
    logMessage('Cron: database connection failed - ' . $e->getMessage());
    fwrite(STDERR, 'Database connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

if (!acquireQueueWorkerLock($pdo)) {
    logMessage('Cron: another queue worker is running, skipped.');
    exit(0);
}

try {
    logMessage('=== CRON queue worker started ===');

    $purged = purgeExpiredNotifications($pdo);
    if ($purged > 0) {
        logMessage("Cron: purged {$purged} queue item(s) older than " . NOTIFICATION_QUEUE_MAX_AGE_DAYS . ' day(s).');
    }

    $stats = processNotificationQueue($pdo, $config);
    logMessage(
        'Cron: queue processed - sent=' . $stats['sent']
        . ', failed=' . $stats['failed']
        . ', skipped(schedule/disabled)=' . $stats['skipped']
        . ', orphaned=' . $stats['orphaned']
    );

    logMessage('=== CRON queue worker finished ===');
} finally {
    releaseQueueWorkerLock($pdo);
}

exit(0);
