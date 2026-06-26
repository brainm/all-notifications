<?php
/**
 * Пример конфигурации для send.php.
 * Скопируйте в config.php и подставьте реальные значения.
 * Файл config.php в репозитории обычно не коммитят (см. .gitignore).
 *
 * --- Прокси (ключ proxies в telegram_config / vk_config / matrix_config) ---
 * Массив строк URI; send.php передаёт первый элемент в CURLOPT_PROXY (cURL/libcurl).
 * Пустой массив [] — запросы без прокси.
 *
 * Поддерживаемые схемы (как в libcurl):
 *   http://HOST:PORT
 *   http://USER:PASSWORD@HOST:PORT
 *   https://HOST:PORT
 *   https://USER:PASSWORD@HOST:PORT
 *   socks5://HOST:PORT          — SOCKS5, имя хоста назначения резолвится на клиенте
 *   socks5h://HOST:PORT         — SOCKS5, DNS через прокси (рекомендуется, если нужен удалённый DNS)
 *   socks4://HOST:PORT
 *
 * USER/PASSWORD: спецсимволы в пароле кодируйте в URI (%21 для ! и т.д.) либо экранируйте в PHP-строке.
 * Примеры строк:
 *   'http://proxy.example.com:3128'
 *   'http://admin:secret%21@203.0.113.10:8080'
 *   'socks5h://user:brookpass%212025@45.148.117.177:39999'
 *
 * --- Правила (rules): опциональный ключ senders ---
 * Массив строк: какие значения GET-параметра sender допускаются для этого правила (например grafana, kuma).
 * Если ключ senders отсутствует или массив пустой — правило действует для любого запроса (в т.ч. без ?sender).
 * Пример: 'senders' => ['grafana', 'kuma'] — правило не сработает для Directus без ?sender=...
 *
 * --- Matrix (matrix_config) ---
 * homeserver_url — базовый URL Synapse/Dendrite (без завершающего /), например https://matrix.example.org
 * access_token — Bearer после m.login.password.
 * В rules: канал matrix, recipients — список room_id (!abc:server). Опционально GET room_id= если в правиле пусто.
 *
 * --- Admin / Web Push (.env) ---
 * ADMIN_LOGIN, ADMIN_PASSWORD, WEB_PUSH_PUBLIC_KEY, WEB_PUSH_PRIVATE_KEY
 * в dist/.env (создаётся при сборке из корневого .env).
 */
return [
    'log_file' => '/var/log/notifications.log',

    // MySQL/MariaDB для очереди отложенных уведомлений (см. schema.sql, cron.sh).
    'db_config' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'notifications',
        'username' => 'notifications',
        'password' => 'YOUR_DB_PASSWORD',
        'charset'  => 'utf8mb4',
    ],

    // Web Push + URL веб-интерфейса (login.php, dashboard.php).
    // VAPID public/private — в dist/.env (WEB_PUSH_PUBLIC_KEY, WEB_PUSH_PRIVATE_KEY).
    // app_base_url — для справки/будущего; ссылка в push строится относительно origin PWA.
    'web_push_config' => [
        'app_base_url'  => 'https://panel.example.com/notifications',
        'app_base_path' => '/notifications',
        'vapid' => [
            'subject' => 'mailto:admin@site.com',
        ],
    ],

    'telegram_config' => [
        'bot_token' => 'YOUR_TELEGRAM_BOT_TOKEN',
        // Первый элемент массива используется как прокси; [] — без прокси
        'proxies'   => [
            // 'http://127.0.0.1:8080',
            // 'socks5h://USER:PASSWORD@HOST:39999',
        ],
        'timeout'   => 5,
    ],

    'vk_config' => [
        'access_token' => 'YOUR_VK_USER_OR_GROUP_TOKEN',
        'api_version'  => '5.131',
        'proxies'      => [
            // 'http://127.0.0.1:8080',
        ],
        'timeout'      => 5,
    ],

    'matrix_config' => [
        'homeserver_url' => 'https://matrix.example.org',
        'access_token'   => 'YOUR_MATRIX_ACCESS_TOKEN',
        'proxies'        => [
            // 'http://127.0.0.1:8080',
        ],
        'timeout'        => 10,
    ],

    'rules' => [
        // Универсальное правило: без senders — любой клиент (Directus, ручной curl без ?sender)
        'main' => [
            'enabled'    => true,
            'channels'   => ['telegram', 'vk', 'matrix'],
            'recipients' => [
                'telegram' => ['YOUR_TELEGRAM_CHAT_ID'],
                'vk'       => ['YOUR_VK_USER_ID'],
                'matrix'   => ['!YOUR_ROOM_ID:matrix.example.org'],
            ],
            'schedule'   => [],
        ],
        // Только вебхуки Grafana / Kuma, если в URL send.php указан ?sender=grafana или ?sender=kuma
        'from_monitoring' => [
            'enabled'    => true,
            'channels'   => ['telegram', 'vk', 'matrix'],
            'recipients' => [
                'telegram' => ['YOUR_TELEGRAM_CHAT_ID'],
                'vk'       => ['YOUR_VK_USER_ID'],
                'matrix'   => ['!YOUR_ROOM_ID:matrix.example.org'],
            ],
            'schedule'   => [],
            'senders'    => ['grafana', 'kuma'],
        ],
        // Web inbox + browser push: recipients — login или id пользователя из admin.php
        'web_alerts' => [
            'enabled'    => true,
            'channels'   => ['web'],
            'recipients' => [
                'web' => ['ivan'],
            ],
            'schedule'   => [],
        ],
    ],
];
