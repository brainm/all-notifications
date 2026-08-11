<?php
/**
 * Приём пересланных событий message_read от vk-beehive-bot (не прямой Callback API VK).
 *
 * URL: …/vk-callback.php
 * Auth: заголовок X-Authorization = VK_CALLBACK_TOKEN из .env
 *       (тот же токен, что ALL_NOTIFICATIONS_TOKEN у vk-beehive-bot)
 */

$configPath = __DIR__ . '/config.php';
if (!is_readable($configPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die('config.php not found or not readable');
}

require __DIR__ . '/config_loader.php';
try {
    $config = loadFullConfig();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die('Configuration error: ' . $e->getMessage());
}

if (!array_key_exists('log_file', $config) || !array_key_exists('vk_config', $config)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die('config.php: missing log_file or vk_config');
}

$log_file = $config['log_file'];
$vk_config = $config['vk_config'];
$rules = $config['rules'] ?? [];

require __DIR__ . '/send_functions.php';

$rotatedMonth = rotateNotificationLogIfNeeded($log_file);
if ($rotatedMonth !== null) {
    $archiveDir = notificationLogArchiveDir($log_file);
    logMessage("Log rotated: previous month archived to {$archiveDir}/{$rotatedMonth}.log.gz");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Method not allowed';
    exit;
}

$expectedToken = trim((string) ($vk_config['callback_token'] ?? ''));
if ($expectedToken === '') {
    logMessage('VK callback forward: VK_CALLBACK_TOKEN is not set in .env');
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'misconfigured';
    exit;
}

$incomingToken = '';
if (isset($_SERVER['HTTP_X_AUTHORIZATION']) && is_string($_SERVER['HTTP_X_AUTHORIZATION'])) {
    $incomingToken = trim($_SERVER['HTTP_X_AUTHORIZATION']);
}
if ($incomingToken === '' || !hash_equals($expectedToken, $incomingToken)) {
    logMessage('VK callback forward: invalid or missing X-Authorization');
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'forbidden';
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data) || empty($data['type']) || !is_string($data['type'])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'bad request';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

$type = $data['type'];
if ($type === 'message_read') {
    handleVkMessageReadEvent($data, $rules);
    echo 'ok';
    exit;
}

logMessage('VK callback forward: ignored type=' . $type);
echo 'ok';
exit;
