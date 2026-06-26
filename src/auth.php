<?php

const USER_SESSION_NAME = 'notifications_user';
const ADMIN_SESSION_NAME = 'notifications_admin';

/** Срок жизни пользовательской сессии (cookie + данные на сервере), секунды. */
const USER_SESSION_LIFETIME = 60 * 60 * 24 * 30;

/** Срок жизни сессии администратора. */
const ADMIN_SESSION_LIFETIME = 60 * 60 * 24 * 30;

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
    if (session_status() === PHP_SESSION_ACTIVE && session_name() !== USER_SESSION_NAME) {
        session_write_close();
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.gc_maxlifetime', (string) USER_SESSION_LIFETIME);
        session_name(USER_SESSION_NAME);
        session_set_cookie_params(sessionCookieParams($config, USER_SESSION_LIFETIME));
        session_start();
    }
}

function startAdminSession(array $config): void {
    if (session_status() === PHP_SESSION_ACTIVE && session_name() !== ADMIN_SESSION_NAME) {
        session_write_close();
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.gc_maxlifetime', (string) ADMIN_SESSION_LIFETIME);
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

/** Продлить cookie сессии при активности (скользящий срок). */
function touchUserSession(array $config): void {
    startUserSession($config);
    if (currentUserId() !== null) {
        refreshSessionCookie(USER_SESSION_LIFETIME);
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
    $userId = currentUserId();
    if ($userId === null) {
        header('Location: login.php');
        exit;
    }
    $user = findActiveUserById($pdo, $userId);
    if ($user === null) {
        $_SESSION = [];
        session_destroy();
        header('Location: login.php?expired=1');
        exit;
    }
    if (!isUserRegistrationComplete($user)) {
        $_SESSION = [];
        session_destroy();
        header('Location: login.php?expired=1');
        exit;
    }
    touchUserSession($config);
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

function logoutUser(array $config): void {
    startUserSession($config);
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
