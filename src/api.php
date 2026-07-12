<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap_web.php';

header('Content-Type: application/json; charset=utf-8');

function apiJsonError(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    [$config, $pdo] = initWebApp();
} catch (Throwable $e) {
    error_log('[all-notifications] api bootstrap failed: ' . $e->getMessage());
    $msg = isAppDebugEnabled() ? $e->getMessage() : 'config';
    apiJsonError(500, $msg);
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

if ($action === 'register_info' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = trim((string) ($_GET['token'] ?? ''));
    $user = findUserByRegistrationToken($pdo, $token);
    if ($user === null) {
        apiJsonError(404, 'invalid or expired invite');
    }
    if (!empty($user['password_hash']) && !empty($user['email'])) {
        apiJsonError(409, 'already registered');
    }
    echo json_encode([
        'login'      => $user['login'],
        'expires_at' => $user['registration_token_expires_at'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'register_complete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim((string) ($_POST['token'] ?? ''));
    $email = (string) ($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password2'] ?? '');
    if ($password !== $password2) {
        apiJsonError(400, 'passwords do not match');
    }
    $r = completeUserRegistration($pdo, $token, $email, $password);
    if (!$r['success']) {
        apiJsonError(400, $r['error'] ?? 'error');
    }
    echo json_encode(['success' => true, 'login' => $r['login']], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim((string) ($_POST['login'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    startAdminSession($config);
    if (loginAdmin($config, $login, $password)) {
        touchAdminSession($config);
        echo json_encode(['success' => true, 'role' => 'admin'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    startUserSession($config);
    $user = authenticateUser($pdo, $login, $password);
    if ($user === null) {
        apiJsonError(401, 'unauthorized');
    }
    $_SESSION['user_id'] = (int) $user['id'];
    issueUserRememberToken($pdo, $config, (int) $user['id']);
    touchUserSession($config, $pdo);
    echo json_encode([
        'success' => true,
        'role'    => 'user',
        'user'    => ['id' => $user['id'], 'login' => $user['login']],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'admin_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    startAdminSession($config);
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if (!loginAdmin($config, $username, $password)) {
        apiJsonError(401, 'unauthorized');
    }
    touchAdminSession($config);
    echo json_encode(['success' => true]);
    exit;
}

if (str_starts_with($action, 'admin_')) {
    startAdminSession($config);
    if (!isAdminLoggedIn()) {
        apiJsonError(401, 'unauthorized');
    }
    touchAdminSession($config);

    if ($action === 'admin_users' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['users' => listUsers($pdo, true)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'admin_user' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $uid = (int) ($_GET['user_id'] ?? 0);
        if ($uid <= 0) {
            apiJsonError(400, 'bad user_id');
        }
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $uid]);
        $user = $stmt->fetch();
        if (!$user) {
            apiJsonError(404, 'not found');
        }
        $notifLimit = min(50, max(1, (int) ($_GET['notifications_limit'] ?? WEB_NOTIFICATIONS_PAGE_SIZE)));
        $notifOffset = max(0, (int) ($_GET['notifications_offset'] ?? 0));
        $notifications = listWebNotificationsForUser($pdo, $uid, $notifLimit, $notifOffset);
        $totalNotifications = countWebNotificationsForUser($pdo, $uid);
        echo json_encode([
            'user' => publicUserRow($user),
            'notifications' => $notifications,
            'notifications_has_more' => ($notifOffset + count($notifications)) < $totalNotifications,
            'subscriptions' => listUserSubscriptions($pdo, $uid),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'admin_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $enabled = ($_POST['enabled'] ?? '1') === '1';
        $invite = ($_POST['invite'] ?? '') === '1';
        if ($invite) {
            $r = createInvitedUser($pdo, $config, (string) ($_POST['login'] ?? ''), $enabled);
        } else {
            $r = createUser(
                $pdo,
                (string) ($_POST['email'] ?? ''),
                (string) ($_POST['login'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                $enabled
            );
        }
        if (!$r['success']) {
            apiJsonError(400, $r['error']);
        }
        echo json_encode([
            'success'    => true,
            'id'         => $r['id'],
            'login'      => $r['login'],
            'invite_url' => $r['invite_url'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'admin_regenerate_invite' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $r = regenerateUserInvite($pdo, $config, $id);
        if (!$r['success']) {
            apiJsonError(400, $r['error'] ?? 'error');
        }
        echo json_encode(['success' => true, 'invite_url' => $r['invite_url']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'admin_update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $enabled = ($_POST['enabled'] ?? '1') === '1';
        $r = updateUser(
            $pdo,
            $id,
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['login'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            $enabled
        );
        if (!$r['success']) {
            apiJsonError(400, $r['error'] ?? 'error');
        }
        echo json_encode(['success' => true, 'login' => $r['login']]);
        exit;
    }

    if ($action === 'admin_toggle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $enabled = ($_POST['enabled'] ?? '') === '1';
        setUserEnabled($pdo, $id, $enabled);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'admin_reset_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $id = (int) ($_POST['user_id'] ?? 0);
        $r = resetUserPassword($pdo, $id, (string) ($_POST['password'] ?? ''));
        if (!$r['success']) {
            apiJsonError(400, $r['error'] ?? 'error');
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'admin_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        softDeleteUser($pdo, (int) ($_POST['user_id'] ?? 0));
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'admin_delete_subscription' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        deleteSubscription($pdo, (int) ($_POST['subscription_id'] ?? 0));
        echo json_encode(['success' => true]);
        exit;
    }

    apiJsonError(404, 'unknown action');
}

startUserSession($config);
restoreUserSessionFromRemember($pdo, $config);
$userId = currentUserId();
if ($userId !== null) {
    touchUserSession($config, $pdo);
}

if ($action === 'session_ping' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($userId === null) {
        apiJsonError(401, 'unauthorized');
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'me' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($userId === null) {
        apiJsonError(401, 'unauthorized');
    }
    $user = findActiveUserById($pdo, $userId);
    if ($user === null) {
        apiJsonError(401, 'unauthorized');
    }
    echo json_encode(['user' => $user], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($userId === null) {
    apiJsonError(401, 'unauthorized');
}

if ($action === 'notifications' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? WEB_NOTIFICATIONS_PAGE_SIZE)));
    if (!empty($_GET['unread'])) {
        $items = listUnreadWebNotificationsForUser($pdo, $userId, $limit);
        echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $items = listWebNotificationsForUser($pdo, $userId, $limit, $offset);
    $total = countWebNotificationsForUser($pdo, $userId);
    echo json_encode([
        'items'    => $items,
        'total'    => $total,
        'has_more' => ($offset + count($items)) < $total,
        'limit'    => $limit,
        'offset'   => $offset,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'seen' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['id'] ?? 0);
    $ok = $id > 0 && markWebNotificationSeen($pdo, $id, $userId);
    echo json_encode(['success' => $ok]);
    exit;
}

if ($action === 'subscribe' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        apiJsonError(400, 'invalid json');
    }
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $ok = savePushSubscription($pdo, $userId, $data, is_string($ua) ? $ua : null);
    echo json_encode(['success' => $ok]);
    exit;
}

if ($action === 'vapid-public-key' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['publicKey' => vapidPublicKey($config)]);
    exit;
}

apiJsonError(404, 'unknown action');
