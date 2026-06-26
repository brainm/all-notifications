<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap_web.php';

try {
    [$config, $pdo] = initWebApp();
} catch (Throwable $e) {
    handleWebBootstrapError($e);
}

startAdminSession($config);

require_once __DIR__ . '/assets.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin</title>
    <?= webHeadIcons(appBasePath($config)) ?>
    <?= vite('admin') ?>
</head>
<body>
<div id="app"></div>
<script>window.__ADMIN_AUTH__ = <?= isAdminLoggedIn() ? 'true' : 'false' ?>;</script>
</body>
</html>
