<?php

const USER_SESSION_NAME = 'notifications_user';
const ADMIN_SESSION_NAME = 'notifications_admin';

/** Срок жизни пользовательской сессии (cookie + данные на сервере), секунды. */
const USER_SESSION_LIFETIME = 60 * 60 * 24 * 90;

/** Срок жизни сессии администратора. */
const ADMIN_SESSION_LIFETIME = 60 * 60 * 24 * 90;

/** Долгоживущий токен «запомнить» (PWA / iOS — когда PHP-сессия теряется раньше cookie). */
const USER_REMEMBER_LIFETIME = 60 * 60 * 24 * 90;

const USER_REMEMBER_COOKIE_NAME = 'notifications_remember';

function configurePhpSessionDefaults(int $lifetime = USER_SESSION_LIFETIME): void {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    // На многих хостингах php.ini: session.gc_maxlifetime = 1440 (24 мин) — файлы сессий
    // удаляются раньше cookie. Переопределяем на каждый запрос + свой каталог (см. ниже).
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    ini_set('session.cookie_lifetime', (string) $lifetime);

    $savePath = appSessionSavePath();
    if (!is_dir($savePath)) {
        @mkdir($savePath, 0700, true);
    }
    if (is_dir($savePath) && is_writable($savePath)) {
        ini_set('session.save_path', $savePath);
    }
}

/** Отдельный каталог сессий — иначе GC других PHP-скриптов с gc_maxlifetime=1440 чистит наш /tmp. */
function appSessionSavePath(): string {
    return __DIR__ . '/var/sessions';
}

function sessionCookieParams(array $config, int $lifetime): array {
    return [
        'lifetime' => $lifetime,
        'path'     => appBasePath($config),
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function startUserSession(array $config): void {
    configurePhpSessionDefaults();
    if (session_status() === PHP_SESSION_ACTIVE && session_name() !== USER_SESSION_NAME) {
        session_write_close();
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name(USER_SESSION_NAME);
        session_set_cookie_params(sessionCookieParams($config, USER_SESSION_LIFETIME));
        session_start();
    }
}

function rememberCookieOptions(array $config, int $lifetime): array {
    return [
        'expires'  => time() + $lifetime,
        'path'     => appBasePath($config),
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function issueUserRememberToken(PDO $pdo, array $config, int $userId): void {
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + USER_REMEMBER_LIFETIME);
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    if (is_string($ua) && mb_strlen($ua) > 512) {
        $ua = mb_substr($ua, 0, 512);
    }
    $stmt = $pdo->prepare(
        'INSERT INTO user_remember_tokens (user_id, token_hash, expires_at, user_agent)
         VALUES (:uid, :hash, :exp, :ua)'
    );
    $stmt->execute([
        ':uid' => $userId,
        ':hash' => $hash,
        ':exp'  => $expiresAt,
        ':ua'   => $ua,
    ]);
    setcookie(USER_REMEMBER_COOKIE_NAME, $token, rememberCookieOptions($config, USER_REMEMBER_LIFETIME));
}

function touchUserRememberToken(PDO $pdo, array $config): void {
    $token = (string) ($_COOKIE[USER_REMEMBER_COOKIE_NAME] ?? '');
    if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) {
        return;
    }
    $hash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + USER_REMEMBER_LIFETIME);
    $stmt = $pdo->prepare(
        'UPDATE user_remember_tokens SET expires_at = :exp
         WHERE token_hash = :hash AND expires_at > NOW()'
    );
    $stmt->execute([':exp' => $expiresAt, ':hash' => $hash]);
    if ($stmt->rowCount() > 0) {
        setcookie(USER_REMEMBER_COOKIE_NAME, $token, rememberCookieOptions($config, USER_REMEMBER_LIFETIME));
    }
}

/**
 * Восстановить PHP-сессию по долгоживущему cookie (если файл сессии на сервере уже удалён).
 */
function restoreUserSessionFromRemember(PDO $pdo, array $config): bool {
    if (currentUserId() !== null) {
        return true;
    }
    $token = (string) ($_COOKIE[USER_REMEMBER_COOKIE_NAME] ?? '');
    if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) {
        return false;
    }
    $hash = hash('sha256', $token);
    $stmt = $pdo->prepare(
        'SELECT user_id FROM user_remember_tokens
         WHERE token_hash = :hash AND expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([':hash' => $hash]);
    $row = $stmt->fetch();
    if (!$row) {
        clearUserRememberCookie($config);
        return false;
    }
    $userId = (int) $row['user_id'];
    $user = findActiveUserById($pdo, $userId);
    if ($user === null || !isUserRegistrationComplete($user)) {
        $pdo->prepare('DELETE FROM user_remember_tokens WHERE token_hash = :hash')->execute([':hash' => $hash]);
        clearUserRememberCookie($config);
        return false;
    }
    $_SESSION['user_id'] = $userId;
    refreshSessionCookie(USER_SESSION_LIFETIME);
    touchUserRememberToken($pdo, $config);
    return true;
}

function clearUserRememberCookie(array $config): void {
    setcookie(USER_REMEMBER_COOKIE_NAME, '', rememberCookieOptions($config, -86400));
}

function revokeUserRememberToken(PDO $pdo, array $config): void {
    $token = (string) ($_COOKIE[USER_REMEMBER_COOKIE_NAME] ?? '');
    if ($token !== '' && strlen($token) === 64 && ctype_xdigit($token)) {
        $hash = hash('sha256', $token);
        $pdo->prepare('DELETE FROM user_remember_tokens WHERE token_hash = :hash')->execute([':hash' => $hash]);
    }
    clearUserRememberCookie($config);
}

function startAdminSession(array $config): void {
    configurePhpSessionDefaults(ADMIN_SESSION_LIFETIME);
    if (session_status() === PHP_SESSION_ACTIVE && session_name() !== ADMIN_SESSION_NAME) {
        session_write_close();
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name(ADMIN_SESSION_NAME);
        session_set_cookie_params(sessionCookieParams($config, ADMIN_SESSION_LIFETIME));
        session_start();
    }
}

function refreshSessionCookie(int $lifetime): void {
    if (!ini_get('session.use_cookies') || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $params = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires'  => time() + $lifetime,
        'path'     => $params['path'],
        'domain'   => $params['domain'] ?? '',
        'secure'   => (bool) $params['secure'],
        'httponly' => (bool) $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

/** Продлить cookie сессии и remember-токен при активности (скользящий срок). */
function touchUserSession(array $config, ?PDO $pdo = null): void {
    startUserSession($config);
    if (currentUserId() !== null) {
        refreshSessionCookie(USER_SESSION_LIFETIME);
        if ($pdo instanceof PDO) {
            touchUserRememberToken($pdo, $config);
        }
    }
}

function touchAdminSession(array $config): void {
    startAdminSession($config);
    if (isAdminLoggedIn()) {
        refreshSessionCookie(ADMIN_SESSION_LIFETIME);
    }
}

function appBasePath(array $config): string {
    $path = $config['web_push_config']['app_base_path'] ?? '/notifications';
    $path = '/' . trim($path, '/');
    return $path === '/' ? '/' : $path;
}

function appBaseUrl(array $config): string {
    return rtrim((string) ($config['web_push_config']['app_base_url'] ?? ''), '/');
}

function currentUserId(): ?int {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    return (int) $_SESSION['user_id'];
}

function isAdminLoggedIn(): bool {
    return !empty($_SESSION['admin_logged_in']);
}

function verifyAdminPassword(array $config, string $password): bool {
    $admin = $config['admin_config'] ?? [];
    $expected = (string) ($admin['password'] ?? '');
    if ($expected === '') {
        return false;
    }
    if (str_starts_with($expected, '$2y$') || str_starts_with($expected, '$argon2')) {
        return password_verify($password, $expected);
    }
    return hash_equals($expected, $password);
}

function loginAdmin(array $config, string $username, string $password): bool {
    $admin = $config['admin_config'] ?? [];
    $expectedUser = (string) ($admin['username'] ?? 'admin');
    if (!hash_equals(strtolower($expectedUser), strtolower($username))) {
        return false;
    }
    if (!verifyAdminPassword($config, $password)) {
        return false;
    }
    $_SESSION['admin_logged_in'] = true;
    return true;
}

function requireUserLogin(array $config, PDO $pdo): array {
    startUserSession($config);
    restoreUserSessionFromRemember($pdo, $config);
    $userId = currentUserId();
    if ($userId === null) {
        header('Location: login.php');
        exit;
    }
    $user = findActiveUserById($pdo, $userId);
    if ($user === null) {
        revokeUserRememberToken($pdo, $config);
        $_SESSION = [];
        session_destroy();
        header('Location: login.php?expired=1');
        exit;
    }
    if (!isUserRegistrationComplete($user)) {
        revokeUserRememberToken($pdo, $config);
        $_SESSION = [];
        session_destroy();
        header('Location: login.php?expired=1');
        exit;
    }
    touchUserSession($config, $pdo);
    return $user;
}

function requireAdminLogin(array $config): void {
    startAdminSession($config);
    if (!isAdminLoggedIn()) {
        return;
    }
}

function adminMustBeLoggedIn(array $config): void {
    startAdminSession($config);
    if (!isAdminLoggedIn()) {
        header('Location: admin.php');
        exit;
    }
}

function logoutUser(array $config, ?PDO $pdo = null): void {
    startUserSession($config);
    if ($pdo instanceof PDO) {
        revokeUserRememberToken($pdo, $config);
    } else {
        clearUserRememberCookie($config);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function logoutAdmin(array $config): void {
    startAdminSession($config);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}
