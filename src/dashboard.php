<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap_web.php';

try {
    [$config, $pdo] = initWebApp();
} catch (Throwable $e) {
    handleWebBootstrapError($e);
}

$user = requireUserLogin($config, $pdo);
$highlightId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$userId = (int) $user['id'];
$pageSize = WEB_NOTIFICATIONS_PAGE_SIZE;
$notifications = listWebNotificationsForUser($pdo, $userId, $pageSize, 0);
$totalNotifications = countWebNotificationsForUser($pdo, $userId);

require_once __DIR__ . '/assets.php';

$initial = [
    'user' => ['id' => (int) $user['id'], 'login' => $user['login'], 'email' => $user['email']],
    'notifications' => $notifications,
    'notificationsHasMore' => $totalNotifications > count($notifications),
    'pageSize' => $pageSize,
    'highlightId' => $highlightId,
    'maxAgeDays' => WEB_NOTIFICATION_MAX_AGE_DAYS,
    'config' => [
        'vapidPublicKey' => vapidPublicKey($config),
        'swPath' => appBasePath($config) . '/service-worker.js',
        'scope' => appBasePath($config) . '/',
    ],
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inbox — <?= h($user['login']) ?></title>
    <?= webHeadIcons(appBasePath($config)) ?>
    <?= vite('dashboard') ?>
</head>
<body>
<div id="app"></div>
<script>
(function () {
  var p = new URLSearchParams(location.search);
  var id = Number(p.get("id") || 0);
  if (!id) return;
  try {
    sessionStorage.setItem(
      "notifications-open-intent",
      JSON.stringify({ id: id, fromPush: p.get("from") === "push", ts: Date.now() })
    );
  } catch (e) {}
})();
</script>
<script>window.__INITIAL__ = <?= json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
</body>
</html>
