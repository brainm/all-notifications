<?php

const WEB_NOTIFICATION_MAX_AGE_DAYS = 7;
const REGISTRATION_TOKEN_TTL_DAYS = 7;
const WEB_NOTIFICATIONS_PAGE_SIZE = 10;

require_once __DIR__ . '/auth.php';

function emailToLogin(string $email): string {
    $email = strtolower(trim($email));
    $at = strpos($email, '@');
    return $at === false ? $email : substr($email, 0, $at);
}

function findActiveUserById(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(
        'SELECT id, email, login, password_hash, enabled, deleted_at, created_at
         FROM users WHERE id = :id AND deleted_at IS NULL AND enabled = 1 LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function isUserRegistrationComplete(array $user): bool {
    return !empty($user['password_hash']) && !empty($user['email']);
}

function findUserByLogin(PDO $pdo, string $login, bool $includeDeleted = false): ?array {
    $sql = 'SELECT id, email, login, password_hash, enabled, deleted_at, created_at FROM users WHERE login = :login';
    if (!$includeDeleted) {
        $sql .= ' AND deleted_at IS NULL';
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':login' => strtolower(trim($login))]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function authenticateUser(PDO $pdo, string $identifier, string $password): ?array {
    $identifier = trim($identifier);
    $user = null;
    if (str_contains($identifier, '@')) {
        $user = findUserByEmail($pdo, strtolower($identifier), false);
    }
    if ($user === null) {
        $user = findUserByLogin($pdo, $identifier, false);
    }
    if ($user === null || empty($user['enabled'])) {
        return null;
    }
    if (empty($user['password_hash']) || !isUserRegistrationComplete($user)) {
        return null;
    }
    if (!password_verify($password, $user['password_hash'])) {
        return null;
    }
    return $user;
}

function resolveWebUserId(PDO $pdo, int|string $recipient): ?int {
    if (is_int($recipient) || (is_string($recipient) && ctype_digit(trim($recipient)))) {
        $id = (int) trim((string) $recipient);
        return findActiveUserById($pdo, $id) !== null ? $id : null;
    }
    if (!is_string($recipient)) {
        return null;
    }
    $user = findUserByLogin($pdo, $recipient, false);
    if ($user === null || empty($user['enabled'])) {
        return null;
    }
    return (int) $user['id'];
}

function listUsers(PDO $pdo, bool $includeDeleted = true): array {
    $sql = 'SELECT id, email, login, password_hash, enabled, deleted_at, created_at,
                   registration_token, registration_token_expires_at FROM users';
    if (!$includeDeleted) {
        $sql .= ' WHERE deleted_at IS NULL';
    }
    $sql .= ' ORDER BY id ASC';
    $rows = $pdo->query($sql)->fetchAll();
    return array_map('publicUserRow', $rows);
}

function publicUserRow(array $user): array {
    $pending = empty($user['password_hash']) || empty($user['email']);
    $row = [
        'id'                   => (int) $user['id'],
        'email'                => $user['email'],
        'login'                => $user['login'],
        'enabled'              => (bool) $user['enabled'],
        'deleted_at'           => $user['deleted_at'],
        'created_at'           => $user['created_at'],
        'pending_registration' => $pending,
    ];
    if ($pending && !empty($user['registration_token'])) {
        $expires = $user['registration_token_expires_at'] ?? null;
        $row['invite_expires_at'] = $expires;
        $row['invite_valid'] = $expires !== null && strtotime((string) $expires) > time();
    }
    return $row;
}

function buildRegistrationUrl(array $config, string $token): string {
    return appBasePath($config) . '/register.php?token=' . urlencode($token);
}

function generateRegistrationToken(): string {
    return bin2hex(random_bytes(32));
}

function registrationTokenExpiresAt(): string {
    return date('Y-m-d H:i:s', time() + REGISTRATION_TOKEN_TTL_DAYS * 86400);
}

function findUserByRegistrationToken(PDO $pdo, string $token): ?array {
    $token = trim($token);
    if ($token === '' || strlen($token) !== 64) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT id, email, login, password_hash, enabled, deleted_at, registration_token, registration_token_expires_at
         FROM users
         WHERE registration_token = :token AND deleted_at IS NULL
           AND registration_token_expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function normalizeLogin(string $login): string {
    return strtolower(trim($login));
}

function findUserByEmail(PDO $pdo, string $email, bool $includeDeleted = false): ?array {
    $email = strtolower(trim($email));
    $sql = 'SELECT id, email, login, password_hash, enabled, deleted_at, created_at FROM users WHERE email = :email';
    if (!$includeDeleted) {
        $sql .= ' AND deleted_at IS NULL';
    }
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':email' => $email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function validateUserInput(string $email, string $login, string $password, bool $passwordRequired): ?string {
    $email = strtolower(trim($email));
    $login = normalizeLogin($login);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Invalid email';
    }
    if ($login === '' || strlen($login) < 2) {
        return 'Login must be at least 2 characters';
    }
    if (!preg_match('/^[a-z0-9._-]+$/', $login)) {
        return 'Login: only letters, digits, . _ -';
    }
    if ($passwordRequired && strlen($password) < 6) {
        return 'Password must be at least 6 characters';
    }
    if ($password !== '' && strlen($password) < 6) {
        return 'Password must be at least 6 characters';
    }
    return null;
}

function createInvitedUser(PDO $pdo, array $config, string $login, bool $enabled = true): array {
    $login = normalizeLogin($login);
    if ($login === '' || strlen($login) < 2) {
        return ['success' => false, 'error' => 'Login must be at least 2 characters'];
    }
    if (!preg_match('/^[a-z0-9._-]+$/', $login)) {
        return ['success' => false, 'error' => 'Login: only letters, digits, . _ -'];
    }
    if (findUserByLogin($pdo, $login, true) !== null) {
        return ['success' => false, 'error' => 'Login already exists'];
    }
    $token = generateRegistrationToken();
    $expires = registrationTokenExpiresAt();
    $stmt = $pdo->prepare(
        'INSERT INTO users (email, login, password_hash, enabled, registration_token, registration_token_expires_at)
         VALUES (NULL, :login, NULL, :enabled, :token, :expires)'
    );
    $stmt->execute([
        ':login'   => $login,
        ':enabled' => $enabled ? 1 : 0,
        ':token'   => $token,
        ':expires' => $expires,
    ]);
    return [
        'success'    => true,
        'id'         => (int) $pdo->lastInsertId(),
        'login'      => $login,
        'invite_url' => buildRegistrationUrl($config, $token),
    ];
}

function regenerateUserInvite(PDO $pdo, array $config, int $userId): array {
    $stmt = $pdo->prepare(
        'SELECT id, email, password_hash, deleted_at FROM users WHERE id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
    if (!$user || $user['deleted_at'] !== null) {
        return ['success' => false, 'error' => 'User not found'];
    }
    if (!empty($user['password_hash']) && !empty($user['email'])) {
        return ['success' => false, 'error' => 'User already registered'];
    }
    $token = generateRegistrationToken();
    $expires = registrationTokenExpiresAt();
    $upd = $pdo->prepare(
        'UPDATE users SET registration_token = :token, registration_token_expires_at = :expires
         WHERE id = :id AND deleted_at IS NULL'
    );
    $upd->execute([':token' => $token, ':expires' => $expires, ':id' => $userId]);
    if ($upd->rowCount() === 0) {
        return ['success' => false, 'error' => 'Update failed'];
    }
    return [
        'success'    => true,
        'invite_url' => buildRegistrationUrl($config, $token),
    ];
}

function completeUserRegistration(PDO $pdo, string $token, string $email, string $password): array {
    $user = findUserByRegistrationToken($pdo, $token);
    if ($user === null) {
        return ['success' => false, 'error' => 'Invalid or expired invite link'];
    }
    if (!empty($user['password_hash']) && !empty($user['email'])) {
        return ['success' => false, 'error' => 'Registration already completed'];
    }
    $email = strtolower(trim($email));
    $err = validateUserInput($email, (string) $user['login'], $password, true);
    if ($err !== null) {
        return ['success' => false, 'error' => $err];
    }
    $existingEmail = findUserByEmail($pdo, $email, true);
    if ($existingEmail !== null && (int) $existingEmail['id'] !== (int) $user['id']) {
        return ['success' => false, 'error' => 'Email already exists'];
    }
    $stmt = $pdo->prepare(
        'UPDATE users SET email = :email, password_hash = :hash,
                registration_token = NULL, registration_token_expires_at = NULL
         WHERE id = :id AND deleted_at IS NULL'
    );
    $stmt->execute([
        ':email' => $email,
        ':hash'  => password_hash($password, PASSWORD_DEFAULT),
        ':id'    => (int) $user['id'],
    ]);
    if ($stmt->rowCount() === 0) {
        return ['success' => false, 'error' => 'Registration failed'];
    }
    return ['success' => true, 'login' => $user['login']];
}

function createUser(PDO $pdo, string $email, string $login, string $password, bool $enabled = true): array {
    $email = strtolower(trim($email));
    $login = normalizeLogin($login);
    $err = validateUserInput($email, $login, $password, true);
    if ($err !== null) {
        return ['success' => false, 'error' => $err];
    }
    if (findUserByLogin($pdo, $login, true) !== null) {
        return ['success' => false, 'error' => 'Login already exists'];
    }
    if (findUserByEmail($pdo, $email, true) !== null) {
        return ['success' => false, 'error' => 'Email already exists'];
    }
    $stmt = $pdo->prepare(
        'INSERT INTO users (email, login, password_hash, enabled) VALUES (:email, :login, :hash, :enabled)'
    );
    $stmt->execute([
        ':email'   => $email,
        ':login'   => $login,
        ':hash'    => password_hash($password, PASSWORD_DEFAULT),
        ':enabled' => $enabled ? 1 : 0,
    ]);
    return ['success' => true, 'id' => (int) $pdo->lastInsertId(), 'login' => $login];
}

function updateUser(PDO $pdo, int $id, string $email, string $login, string $password, bool $enabled): array {
    $email = strtolower(trim($email));
    $login = normalizeLogin($login);

    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['success' => false, 'error' => 'User not found'];
    }

    $pending = empty($row['password_hash']);
    if ($pending && $email === '' && $password === '') {
        if ($login === '' || strlen($login) < 2) {
            return ['success' => false, 'error' => 'Login must be at least 2 characters'];
        }
        if (!preg_match('/^[a-z0-9._-]+$/', $login)) {
            return ['success' => false, 'error' => 'Login: only letters, digits, . _ -'];
        }
        $existingLogin = findUserByLogin($pdo, $login, true);
        if ($existingLogin !== null && (int) $existingLogin['id'] !== $id) {
            return ['success' => false, 'error' => 'Login already exists'];
        }
        $upd = $pdo->prepare('UPDATE users SET login = :login, enabled = :enabled WHERE id = :id AND deleted_at IS NULL');
        $upd->execute([':login' => $login, ':enabled' => $enabled ? 1 : 0, ':id' => $id]);
        if ($upd->rowCount() === 0) {
            return ['success' => false, 'error' => 'Update failed'];
        }
        return ['success' => true, 'login' => $login];
    }

    $err = validateUserInput($email, $login, $password, false);
    if ($err !== null) {
        return ['success' => false, 'error' => $err];
    }

    $existingLogin = findUserByLogin($pdo, $login, true);
    if ($existingLogin !== null && (int) $existingLogin['id'] !== $id) {
        return ['success' => false, 'error' => 'Login already exists'];
    }
    $existingEmail = findUserByEmail($pdo, $email, true);
    if ($existingEmail !== null && (int) $existingEmail['id'] !== $id) {
        return ['success' => false, 'error' => 'Email already exists'];
    }

    if ($password !== '') {
        $stmt = $pdo->prepare(
            'UPDATE users SET email = :email, login = :login, password_hash = :hash, enabled = :enabled WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute([
            ':email'   => $email,
            ':login'   => $login,
            ':hash'    => password_hash($password, PASSWORD_DEFAULT),
            ':enabled' => $enabled ? 1 : 0,
            ':id'      => $id,
        ]);
    } else {
        $stmt = $pdo->prepare(
            'UPDATE users SET email = :email, login = :login, enabled = :enabled WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute([
            ':email'   => $email,
            ':login'   => $login,
            ':enabled' => $enabled ? 1 : 0,
            ':id'      => $id,
        ]);
    }

    if ($stmt->rowCount() === 0) {
        return ['success' => false, 'error' => 'Update failed'];
    }
    return ['success' => true, 'login' => $login];
}

function setUserEnabled(PDO $pdo, int $id, bool $enabled): bool {
    $stmt = $pdo->prepare('UPDATE users SET enabled = :e WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute([':e' => $enabled ? 1 : 0, ':id' => $id]);
    return $stmt->rowCount() > 0;
}

function resetUserPassword(PDO $pdo, int $id, string $password): array {
    if (strlen($password) < 6) {
        return ['success' => false, 'error' => 'Password must be at least 6 characters'];
    }
    $stmt = $pdo->prepare(
        'UPDATE users SET password_hash = :hash WHERE id = :id AND deleted_at IS NULL'
    );
    $stmt->execute([':hash' => password_hash($password, PASSWORD_DEFAULT), ':id' => $id]);
    return ['success' => $stmt->rowCount() > 0];
}

function softDeleteUser(PDO $pdo, int $id): bool {
    $stmt = $pdo->prepare('UPDATE users SET deleted_at = NOW(), enabled = 0 WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute([':id' => $id]);
    return $stmt->rowCount() > 0;
}

function listUserSubscriptions(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare(
        'SELECT id, endpoint, user_agent, created_at FROM web_push_subscriptions WHERE user_id = :uid ORDER BY id DESC'
    );
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll();
}

function deleteSubscription(PDO $pdo, int $subscriptionId): bool {
    $stmt = $pdo->prepare('DELETE FROM web_push_subscriptions WHERE id = :id');
    $stmt->execute([':id' => $subscriptionId]);
    return $stmt->rowCount() > 0;
}

function savePushSubscription(PDO $pdo, int $userId, array $sub, ?string $userAgent): bool {
    $endpoint = (string) ($sub['endpoint'] ?? '');
    $keys = $sub['keys'] ?? [];
    $p256dh = (string) ($keys['p256dh'] ?? '');
    $auth = (string) ($keys['auth'] ?? '');
    if ($endpoint === '' || $p256dh === '' || $auth === '') {
        return false;
    }
    $hash = hash('sha256', $endpoint);
    $stmt = $pdo->prepare(
        'INSERT INTO web_push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth_key, user_agent)
         VALUES (:uid, :endpoint, :hash, :p256dh, :auth, :ua)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh),
             auth_key = VALUES(auth_key), user_agent = VALUES(user_agent)'
    );
    return $stmt->execute([
        ':uid'      => $userId,
        ':endpoint' => $endpoint,
        ':hash'     => $hash,
        ':p256dh'   => $p256dh,
        ':auth'     => $auth,
        ':ua'       => $userAgent,
    ]);
}

function insertWebNotification(
    PDO $pdo,
    int $userId,
    string $ruleName,
    string $messageText,
    ?string $payloadJson = null
): int {
    $stmt = $pdo->prepare(
        'INSERT INTO web_notifications (user_id, rule_name, message_text, payload_json)
         VALUES (:uid, :rule, :text, :payload)'
    );
    $stmt->execute([
        ':uid'     => $userId,
        ':rule'    => $ruleName,
        ':text'    => $messageText,
        ':payload' => $payloadJson,
    ]);
    return (int) $pdo->lastInsertId();
}

function listUnreadWebNotificationsForUser(PDO $pdo, int $userId, int $limit = 100): array {
    $stmt = $pdo->prepare(
        'SELECT id, rule_name, message_text, seen_at, created_at
         FROM web_notifications
         WHERE user_id = :uid AND seen_at IS NULL AND created_at >= (NOW() - INTERVAL :days DAY)
         ORDER BY created_at ASC, id ASC
         LIMIT :lim'
    );
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':days', WEB_NOTIFICATION_MAX_AGE_DAYS, PDO::PARAM_INT);
    $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function listWebNotificationsForUser(PDO $pdo, int $userId, int $limit = 100, int $offset = 0): array {
    $stmt = $pdo->prepare(
        'SELECT id, rule_name, message_text, seen_at, created_at
         FROM web_notifications
         WHERE user_id = :uid AND created_at >= (NOW() - INTERVAL :days DAY)
         ORDER BY created_at DESC, id DESC
         LIMIT :lim OFFSET :off'
    );
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':days', WEB_NOTIFICATION_MAX_AGE_DAYS, PDO::PARAM_INT);
    $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
    $stmt->bindValue(':off', max(0, $offset), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function countWebNotificationsForUser(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM web_notifications
         WHERE user_id = :uid AND created_at >= (NOW() - INTERVAL :days DAY)'
    );
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':days', WEB_NOTIFICATION_MAX_AGE_DAYS, PDO::PARAM_INT);
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

function markWebNotificationSeen(PDO $pdo, int $notificationId, int $userId): bool {
    $stmt = $pdo->prepare(
        'UPDATE web_notifications SET seen_at = NOW()
         WHERE id = :id AND user_id = :uid AND seen_at IS NULL'
    );
    $stmt->execute([':id' => $notificationId, ':uid' => $userId]);
    return $stmt->rowCount() > 0;
}

function purgeExpiredWebNotifications(PDO $pdo, int $maxAgeDays = WEB_NOTIFICATION_MAX_AGE_DAYS): int {
    $stmt = $pdo->prepare('DELETE FROM web_notifications WHERE created_at < (NOW() - INTERVAL :days DAY)');
    $stmt->bindValue(':days', $maxAgeDays, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->rowCount();
}

function purgeDataForDeletedUsers(PDO $pdo, int $maxAgeDays = WEB_NOTIFICATION_MAX_AGE_DAYS): int {
    $stmt = $pdo->prepare(
        'DELETE n FROM web_notifications n
         INNER JOIN users u ON u.id = n.user_id
         WHERE u.deleted_at IS NOT NULL
           AND u.deleted_at < (NOW() - INTERVAL :days DAY)'
    );
    $stmt->bindValue(':days', $maxAgeDays, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->rowCount();
}

function webPushIsAvailable(): bool {
    foreach ([__DIR__ . '/vendor/autoload.php', dirname(__DIR__) . '/vendor/autoload.php'] as $path) {
        if (is_file($path)) {
            return true;
        }
    }
    return false;
}

function webPushLoadVendor(): void {
    foreach ([__DIR__ . '/vendor/autoload.php', dirname(__DIR__) . '/vendor/autoload.php'] as $path) {
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }
}

function webPushContentEncoding(string $endpoint): string {
    // iOS / Safari PWA используют Apple Push Notification service
    if (str_contains($endpoint, 'web.push.apple.com')) {
        return 'aes128gcm';
    }
    return 'aes128gcm';
}

function recordWebPushResult(PDO $pdo, int $notificationId, bool $success, string $error = ''): void {
    if ($success) {
        $upd = $pdo->prepare('UPDATE web_notifications SET push_sent_at = NOW(), push_error = NULL WHERE id = :id');
        $upd->execute([':id' => $notificationId]);
        return;
    }
    $upd = $pdo->prepare('UPDATE web_notifications SET push_error = :err WHERE id = :id');
    $upd->execute([':err' => $error !== '' ? $error : 'Push failed', ':id' => $notificationId]);
}

function sendWebPushToUser(PDO $pdo, array $config, int $userId, int $notificationId, string $title, string $body): array {
    if (!webPushIsAvailable()) {
        $error = 'Composer vendor not installed (minishlink/web-push)';
        recordWebPushResult($pdo, $notificationId, false, $error);
        return ['success' => false, 'error' => $error];
    }

    $pushCfg = $config['web_push_config']['vapid'] ?? [];
    $publicKey = (string) ($pushCfg['publicKey'] ?? '');
    $privateKey = (string) ($pushCfg['privateKey'] ?? '');
    $subject = (string) ($pushCfg['subject'] ?? '');
    if ($publicKey === '' || $privateKey === '' || $subject === '') {
        $error = 'web_push_config.vapid is incomplete';
        recordWebPushResult($pdo, $notificationId, false, $error);
        return ['success' => false, 'error' => $error];
    }

    webPushLoadVendor();

    $stmt = $pdo->prepare('SELECT id, endpoint, p256dh, auth_key FROM web_push_subscriptions WHERE user_id = :uid');
    $stmt->execute([':uid' => $userId]);
    $subs = $stmt->fetchAll();
    if ($subs === []) {
        $error = 'No push subscriptions';
        recordWebPushResult($pdo, $notificationId, false, $error);
        return ['success' => false, 'error' => $error];
    }

    $payload = json_encode([
        'title' => $title,
        'body'  => mb_strlen($body) > 180 ? mb_substr($body, 0, 177) . '...' : $body,
        'id'    => $notificationId,
        // Относительный URL: при клике открывается тот же origin, что и PWA (не зависит от app_base_url).
        'url'   => './dashboard.php?id=' . $notificationId . '&from=push',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $auth = [
        'VAPID' => [
            'subject'    => $subject,
            'publicKey'  => $publicKey,
            'privateKey' => $privateKey,
        ],
    ];

    $webPush = new Minishlink\WebPush\WebPush($auth);
    $webPush->setAutomaticPadding(0);
    $sent = 0;
    $errors = [];
    $deadIds = [];

    foreach ($subs as $sub) {
        $encoding = webPushContentEncoding((string) $sub['endpoint']);
        $subscription = Minishlink\WebPush\Subscription::create([
            'endpoint'        => $sub['endpoint'],
            'keys'            => ['p256dh' => $sub['p256dh'], 'auth' => $sub['auth_key']],
            'contentEncoding' => $encoding,
        ]);
        $report = $webPush->sendOneNotification($subscription, $payload);
        if ($report->isSuccess()) {
            $sent++;
            logMessage("Web push OK user $userId sub #{$sub['id']} ($encoding)");
        } else {
            $reason = $report->getReason();
            $errors[] = $reason;
            logMessage("Web push FAIL user $userId sub #{$sub['id']}: $reason");
            if ($report->isSubscriptionExpired()) {
                $deadIds[] = (int) $sub['id'];
            }
        }
    }

    if ($deadIds !== []) {
        $in = implode(',', array_map('intval', $deadIds));
        $pdo->exec("DELETE FROM web_push_subscriptions WHERE id IN ($in)");
    }

    if ($sent > 0) {
        recordWebPushResult($pdo, $notificationId, true);
        return ['success' => true, 'error' => ''];
    }

    $err = implode('; ', array_unique($errors));
    recordWebPushResult($pdo, $notificationId, false, $err !== '' ? $err : 'Push failed');
    return ['success' => false, 'error' => $err ?: 'Push failed'];
}

function deliverWebNotification(
    PDO $pdo,
    array $config,
    int $userId,
    string $ruleName,
    string $messageText,
    ?string $payloadJson = null
): array {
    $id = insertWebNotification($pdo, $userId, $ruleName, $messageText, $payloadJson);
    try {
        $push = sendWebPushToUser($pdo, $config, $userId, $id, $ruleName, $messageText);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        logMessage("Web push exception for user $userId notification #$id: $msg");
        recordWebPushResult($pdo, $id, false, $msg);
        return ['success' => true, 'notification_id' => $id, 'push' => ['success' => false, 'error' => $msg]];
    }
    if (!$push['success']) {
        logMessage("Web push for user $userId notification #$id: " . $push['error']);
    }
    return ['success' => true, 'notification_id' => $id, 'push' => $push];
}

function vapidPublicKey(array $config): string {
    return (string) ($config['web_push_config']['vapid']['publicKey'] ?? '');
}
