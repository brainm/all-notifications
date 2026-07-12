<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap_web.php';

try {
    [$config, $pdo] = initWebApp();
} catch (Throwable $e) {
    handleWebBootstrapError($e);
}

startUserSession($config);
restoreUserSessionFromRemember($pdo, $config);

if (currentUserId() !== null) {
    header('Location: dashboard.php');
    exit;
}

startAdminSession($config);

if (isAdminLoggedIn()) {
    header('Location: admin.php');
    exit;
}

require_once __DIR__ . '/assets.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Вход — уведомления</title>
    <?= webHeadIcons(appBasePath($config)) ?>
    <?= vite('login') ?>
</head>
<body>
<div id="app"></div>
</body>
</html>
