<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap_web.php';

try {
    [$config, $pdo] = initWebApp();
} catch (Throwable $e) {
    handleWebBootstrapError($e);
}

startUserSession($config);

if (currentUserId() !== null) {
    header('Location: dashboard.php');
    exit;
}

$token = trim((string) ($_GET['token'] ?? ''));

require_once __DIR__ . '/assets.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Регистрация — уведомления</title>
    <?= webHeadIcons(appBasePath($config)) ?>
    <?= vite('register') ?>
</head>
<body>
<div id="app"></div>
<script>window.__INITIAL__ = <?= json_encode(['token' => $token], JSON_UNESCAPED_UNICODE) ?>;</script>
</body>
</html>
