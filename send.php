<?php
/**
 * =============================================================================
 * Универсальный диспетчер уведомлений (POST) → Telegram / VK / Matrix
 * Версия: 1.0 (на базе kuma-notifications.php)
 *
 * Принимает POST с телом в общем виде (без GET sender — универсальный режим):
 * - JSON: { "message": "..." } — одно сообщение во все каналы из правил
 *   или { "text": "..." } / { "body": "..." } (алиасы)
 * - JSON: { "telegram": "...", "vk": "...", "matrix": "..." } — разный текст по каналам
 *   (неуказанный канал берёт значение из message|text|body или JSON целиком; matrix по умолчанию как vk)
 * - Опционально: "parse_mode" или "telegram_parse_mode" (например MarkdownV2)
 *   передаётся в Telegram sendMessage, если задано
 * - Сырой text/plain: весь поток как сообщение
 * - application/x-www-form-urlencoded: поле message или text
 *
 * С GET sender=grafana или sender=kuma тело обрабатывается как в legacy-скриптах:
 * JSON берётся из сырого тела (как json_decode в grafana-notifications.php / kuma-notifications.php),
 * текст для Telegram, VK и Matrix одинаковый, без parse_mode из тела (обычный текст / Kuma с эмодзи).
 *
 * GET-параметры (как в Kuma-шлюзе):
 * - chat_id, user_id, room_id — подставляются, если в правиле пустые получатели (telegram / vk / matrix)
 * - sender — опционально: grafana | kuma | market | market/notification (нижний регистр). Пусто или отсутствует — «общий» клиент
 *   market и market/notification — вебхук Яндекс Маркета (POST /notification): php://input в каналы одним куском без разбора,
 *   ответ всегда JSON Response { version, name, time } (см. Partner API push sendNotification).
 *   (например Directus без query). В правиле ключ senders (массив строк) дополнительно ограничивает,
 *   для каких sender срабатывает правило; без senders или senders: [] — без ограничения.
 *
 * --- Опциональный фильтр правил (GET-параметр rules) ---
 * В URL можно добавить query string с перечислением имён правил через запятую.
 * Тогда в этом запросе обрабатываются только перечисленные правила из массива $rules
 * (остальные пропускаются). Проверки enabled и расписания для отобранных правил
 * остаются как обычно: отключённое правило не сработает, вне расписания — тоже.
 *
 * Пример: .../send.php?rules=workdays_vk
 *   → рассматривается только правило workdays_vk (если enabled и попадает в расписание).
 *
 * Несколько правил: ?rules=workdays_vk,always_telegram
 * Пробелы вокруг имён после запятой допустимы (обрезаются).
 * Параметр rules можно сочетать с chat_id, user_id и room_id в одном URL.
 * Если параметр rules не передан — перебираются все правила из $rules.
 *
 * Логи: путь задаётся в config.php (по умолчанию /var/log/notifications.log).
 * =============================================================================
 */

$configPath = __DIR__ . '/config.php';
if (!is_readable($configPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die('config.php not found or not readable next to send.php');
}
$config = require $configPath;
if (!is_array($config)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die('config.php must return an array');
}
foreach (['log_file', 'telegram_config', 'vk_config', 'matrix_config', 'rules'] as $key) {
    if (!array_key_exists($key, $config)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        die("config.php: missing key \"{$key}\"");
    }
}
$log_file = $config['log_file'];
$telegram_config = $config['telegram_config'];
$vk_config = $config['vk_config'];
$matrix_config = $config['matrix_config'];
$rules = $config['rules'];

$raw_input = file_get_contents('php://input');
logMessage('=== NEW REQUEST (send gateway) ===');
logRequestWithBody($raw_input);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    logMessage("ERROR: Method not allowed - {$_SERVER['REQUEST_METHOD']}");
    http_response_code(405);
    die('Method not allowed');
}

$request_sender = '';
if (isset($_GET['sender']) && is_string($_GET['sender'])) {
    $request_sender = strtolower(trim($_GET['sender']));
}
if ($request_sender !== '') {
    logMessage('GET sender=' . $request_sender);
}

$telegram_parse_mode = null;
if (isMarketNotificationSender($request_sender)) {
    $bodyText = $raw_input;
    if ($bodyText !== '') {
        $bodyText = truncateMessageUniversal($bodyText);
    }
    $channelMessages = ['telegram' => $bodyText, 'vk' => $bodyText, 'matrix' => $bodyText];
} elseif ($request_sender === 'grafana') {
    $legacy = decodeLegacyJsonPayload($raw_input);
    $legacy = removeBackslashes($legacy);
    $formatted = formatGrafanaMessage($legacy);
    $channelMessages = ['telegram' => $formatted, 'vk' => $formatted, 'matrix' => $formatted];
} elseif ($request_sender === 'kuma') {
    $legacy = decodeLegacyJsonPayload($raw_input);
    $legacy = removeBackslashes($legacy);
    $formatted = formatKumaMessage($legacy);
    $channelMessages = ['telegram' => $formatted, 'vk' => $formatted, 'matrix' => $formatted];
} else {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    $data = parseIncomingPayload($raw_input, $contentType);
    $data = removeBackslashes($data);
    $channelMessages = buildChannelMessages($data, $raw_input);
    if (!empty($data['telegram_parse_mode']) && is_string($data['telegram_parse_mode'])) {
        $telegram_parse_mode = $data['telegram_parse_mode'];
    } elseif (!empty($data['parse_mode']) && is_string($data['parse_mode'])) {
        $telegram_parse_mode = $data['parse_mode'];
    }
}

logMessage('Resolved telegram message length: ' . mb_strlen($channelMessages['telegram']));
logMessage('Resolved vk message length: ' . mb_strlen($channelMessages['vk']));
logMessage('Resolved matrix message length: ' . mb_strlen($channelMessages['matrix'] ?? ''));

$get_chat_id = isset($_GET['chat_id']) ? trim($_GET['chat_id']) : null;
$get_user_id = isset($_GET['user_id']) ? trim($_GET['user_id']) : null;
$get_room_id = isset($_GET['room_id']) ? trim($_GET['room_id']) : null;

$default_recipients = [
    'telegram' => $get_chat_id ? [$get_chat_id] : [],
    'vk'       => $get_user_id ? [$get_user_id] : [],
    'matrix'   => $get_room_id ? [$get_room_id] : [],
];

$now = new DateTime();
$current_day = (int) $now->format('N');
$current_hour = (int) $now->format('G');
logMessage("Current time: day=$current_day, hour=$current_hour");

$rules_allowlist = null;
if (isset($_GET['rules']) && is_string($_GET['rules'])) {
    $parts = array_map('trim', explode(',', $_GET['rules']));
    $parts = array_values(array_filter($parts, static function ($n) {
        return $n !== '';
    }));
    if ($parts !== []) {
        $rules_allowlist = array_fill_keys($parts, true);
        logMessage('GET rules= filter: only [' . implode(', ', $parts) . ']');
        foreach ($parts as $req_name) {
            if (!array_key_exists($req_name, $rules)) {
                logMessage("GET rules=: name '$req_name' is not defined in \$rules.");
            }
        }
    }
}

$sent_any = false;
foreach ($rules as $rule_name => $rule) {
    if ($rules_allowlist !== null && !isset($rules_allowlist[$rule_name])) {
        logMessage("Rule '$rule_name' not listed in rules=, skipped.");
        continue;
    }

    if (empty($rule['enabled'])) {
        logMessage("Rule '$rule_name' is disabled, skipped.");
        continue;
    }

    if (!isScheduleMatch($rule, $current_day, $current_hour)) {
        logMessage("Rule '$rule_name' does not match schedule, skipped.");
        continue;
    }

    if (!ruleMatchesSenders($rule, $request_sender)) {
        logMessage("Rule '$rule_name' senders filter does not match request sender \"{$request_sender}\", skipped.");
        continue;
    }

    logMessage("Rule '$rule_name' matches! Processing channels...");

    foreach ($rule['channels'] as $channel) {
        $recipients = $rule['recipients'][$channel] ?? [];
        if (empty($recipients) && isset($default_recipients[$channel]) && !empty($default_recipients[$channel])) {
            $recipients = $default_recipients[$channel];
            logMessage("Using GET-parameter recipients for channel $channel: " . implode(',', $recipients));
        }
        if (empty($recipients)) {
            logMessage("Rule '$rule_name' channel '$channel' has no recipients, skipped.");
            continue;
        }

        $text = messageTextForChannel($channel, $channelMessages);
        if ($text === '') {
            logMessage("Rule '$rule_name' channel '$channel' has empty message, skipped.");
            continue;
        }

        foreach ($recipients as $recipient) {
            if ($channel === 'telegram') {
                $result = sendToTelegram($telegram_config, $recipient, $text, $telegram_parse_mode);
                logMessage("Telegram to $recipient: " . ($result['success'] ? "OK" : "FAIL - " . $result['error']));
                if ($result['success']) $sent_any = true;
            } elseif ($channel === 'vk') {
                $result = sendToVk($vk_config, $recipient, $text);
                logMessage("VK to $recipient: " . ($result['success'] ? "OK" : "FAIL - " . $result['error']));
                if ($result['success']) $sent_any = true;
            } elseif ($channel === 'matrix') {
                $result = sendToMatrix($matrix_config, $recipient, $text);
                logMessage("Matrix to $recipient: " . ($result['success'] ? "OK" : "FAIL - " . $result['error']));
                if ($result['success']) $sent_any = true;
            } else {
                logMessage("Unknown channel '$channel' in rule '$rule_name'");
            }
        }
    }
}

if (isMarketNotificationSender($request_sender)) {
    if (!$sent_any) {
        logMessage('Market notification: no matching rules or all channel sends failed.');
    } else {
        logMessage('Market notification: dispatched to channels.');
    }
    emitMarketNotificationApiResponse(200);
}

if (!$sent_any) {
    logMessage("No notifications were sent (no matching rules or all failed).");
    http_response_code(200);
    echo "No notifications sent.";
} else {
    http_response_code(200);
    echo "Notifications sent according to rules.";
}
exit;

// ---------------------- ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ------------------------------

function logMessage(string $msg): void {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] $msg\n";
    @file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
}

function logRequestWithBody(string $body): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'unknown';
    $uri = $_SERVER['REQUEST_URI'] ?? 'unknown';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $headers_str = '';
    if (is_array($headers)) {
        foreach ($headers as $k => $v) {
            $headers_str .= "$k: $v\n";
        }
    }
    $log = "Request: $method $uri from $ip\nHeaders:\n$headers_str\nBody:\n$body\n---\n";
    logMessage($log);
}

function removeBackslashes($item) {
    if (is_array($item)) {
        foreach ($item as $key => $value) {
            $item[$key] = removeBackslashes($value);
        }
        return $item;
    }
    return is_string($item) ? str_replace('\\', '', $item) : $item;
}

/**
 * Как в grafana-notifications.php / kuma-notifications.php: json_decode(php://input);
 * если не массив — оборачиваем в raw_text.
 */
function decodeLegacyJsonPayload(string $raw_input): array {
    $decoded = json_decode($raw_input, true);
    if (!is_array($decoded)) {
        return ['raw_text' => $raw_input];
    }
    return $decoded;
}

/**
 * Текст алерта как в grafana-notifications.php (formatMessage).
 */
function formatGrafanaMessage(array $data): string {
    $msg = "Grafana Alert\n\n";

    $status = $data['status'] ?? $data['state'] ?? '';
    if ($status) {
        $status_icon = '';
        if (str_contains($status, 'firing') || $status === 'alerting') {
            $status_icon = '[FIRING] ';
        } elseif (str_contains($status, 'resolved') || $status === 'ok') {
            $status_icon = '[RESOLVED] ';
        }
        $msg .= "State: {$status_icon}{$status}\n";
    }

    if (!empty($data['title'])) {
        $msg .= 'Title: ' . $data['title'] . "\n";
    }

    if (!empty($data['groupLabels'])) {
        $msg .= "\nGroup Labels:\n";
        foreach ($data['groupLabels'] as $k => $v) {
            $msg .= "• {$k}: {$v}\n";
        }
    }

    if (!empty($data['alerts']) && is_array($data['alerts'])) {
        foreach ($data['alerts'] as $idx => $alert) {
            if ($idx > 0) {
                $msg .= "\n---\n";
            }
            $msg .= "\nAlert #" . ($idx + 1) . "\n";

            $alert_status = $alert['status'] ?? '';
            $alert_icon = '';
            if ($alert_status === 'firing') {
                $alert_icon = '[FIRING] ';
            } elseif ($alert_status === 'resolved') {
                $alert_icon = '[RESOLVED] ';
            }
            if ($alert_status) {
                $msg .= "Status: {$alert_icon}{$alert_status}\n";
            }

            if (!empty($alert['labels'])) {
                $msg .= "Labels:\n";
                foreach ($alert['labels'] as $k => $v) {
                    $msg .= "  - {$k}: {$v}\n";
                }
            }
            if (!empty($alert['annotations'])) {
                $msg .= "Annotations:\n";
                foreach ($alert['annotations'] as $k => $v) {
                    $msg .= "  - {$k}: {$v}\n";
                }
            }
            if (!empty($alert['valueString'])) {
                $msg .= "Value: {$alert['valueString']}\n";
            }
            if (!empty($alert['silenceURL'])) {
                $msg .= "Silence: {$alert['silenceURL']}\n";
            }
        }
    } else {
        $msg .= "Full data:\n" . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    if (!empty($data['externalURL'])) {
        $msg .= "\nGrafana: {$data['externalURL']}\n";
    }
    if (mb_strlen($msg) > 4000) {
        $msg = mb_substr($msg, 0, 3950) . "\n... (truncated)";
    }
    return $msg;
}

/**
 * Текст алерта как в kuma-notifications.php (formatKumaMessage).
 */
function formatKumaMessage(array $data): string {
    $name = $data['name'] ?? 'Неизвестный сервис';
    $status = $data['status'] ?? 'unknown';
    $msg = $data['msg'] ?? '';
    $hostnameOrURL = $data['hostnameOrURL'] ?? '';
    $heartbeat = $data['heartbeatJSON'] ?? null;
    $monitor = $data['monitorJSON'] ?? null;

    if ($status === 'up') {
        $status_text = 'UP (работает)';
        $status_emoji = '✅';
    } elseif ($status === 'down') {
        $status_text = 'DOWN (недоступен)';
        $status_emoji = '🔴';
    } else {
        $status_text = ucfirst($status);
        $status_emoji = '⚠️';
    }

    $message = "📡 Uptime Kuma Alert\n\n";
    if (!empty($hostnameOrURL)) {
        $message .= "Host/URL: $hostnameOrURL\n";
    }
    if (!empty($msg)) {
        $message .= "Message: $msg\n";
    }

    if (is_array($heartbeat)) {
        if (isset($heartbeat['time'])) {
            $message .= 'Time: ' . date('Y-m-d H:i:s', strtotime($heartbeat['time'])) . "\n";
        }
        if (isset($heartbeat['responseTime'])) {
            $message .= "Response time: {$heartbeat['responseTime']} ms\n";
        }
        if (isset($heartbeat['statusCode'])) {
            $message .= "HTTP status: {$heartbeat['statusCode']}\n";
        }
    }

    if (is_array($monitor)) {
        if (isset($monitor['type'])) {
            $message .= "Monitor type: {$monitor['type']}\n";
        }
        if (isset($monitor['interval'])) {
            $message .= "Check interval: {$monitor['interval']} sec\n";
        }
    }

    if (mb_strlen($message) > 4000) {
        $message = mb_substr($message, 0, 3950) . "\n... (truncated)";
    }
    return $message;
}

/**
 * Разбор тела POST в массив (или пустой массив + сырой текст снаружи обрабатывается в buildChannelMessages).
 */
function parseIncomingPayload(string $raw_input, string $contentType): array {
    $ct = strtolower($contentType);
    if (strpos($ct, 'application/json') !== false || $raw_input !== '' && ($raw_input[0] === '{' || $raw_input[0] === '[')) {
        $decoded = json_decode($raw_input, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return ['_parse_error' => true, 'raw_text' => $raw_input];
        }
        if (!is_array($decoded)) {
            return ['message' => is_string($decoded) ? $decoded : json_encode($decoded)];
        }
        return $decoded;
    }
    if (strpos($ct, 'application/x-www-form-urlencoded') !== false) {
        parse_str($raw_input, $out);
        return is_array($out) ? $out : [];
    }
    if ($raw_input !== '') {
        return ['message' => $raw_input];
    }
    return [];
}

/**
 * Сообщения по каналам: общий fallback + опциональные переопределения telegram/vk/matrix.
 */
function buildChannelMessages(array $data, string $raw_input): array {
    $default = '';

    if (!empty($data['message']) && is_string($data['message'])) {
        $default = $data['message'];
    } elseif (!empty($data['text']) && is_string($data['text'])) {
        $default = $data['text'];
    } elseif (!empty($data['body']) && is_string($data['body'])) {
        $default = $data['body'];
    } elseif (!empty($data['_parse_error']) && isset($data['raw_text'])) {
        $default = is_string($data['raw_text']) ? $data['raw_text'] : '';
    } elseif ($data !== []) {
        $copy = $data;
        foreach (['telegram', 'vk', 'matrix', 'parse_mode', 'telegram_parse_mode'] as $k) {
            unset($copy[$k]);
        }
        if ($copy !== []) {
            $default = json_encode($copy, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
    }

    if ($default === '' && $raw_input !== '') {
        $default = $raw_input;
    }

    $default = truncateMessageUniversal($default);

    $tg = (!empty($data['telegram']) && is_string($data['telegram'])) ? truncateMessageUniversal($data['telegram']) : $default;
    $vk = (!empty($data['vk']) && is_string($data['vk'])) ? truncateMessageUniversal($data['vk']) : $default;
    $mx = (!empty($data['matrix']) && is_string($data['matrix'])) ? truncateMessageUniversal($data['matrix']) : $default;

    return ['telegram' => $tg, 'vk' => $vk, 'matrix' => $mx];
}

/**
 * Текст сообщения для канала (matrix по умолчанию совпадает с vk/plain).
 */
function messageTextForChannel(string $channel, array $channelMessages): string {
    if ($channel === 'telegram') {
        return $channelMessages['telegram'] ?? '';
    }
    if ($channel === 'vk') {
        return $channelMessages['vk'] ?? '';
    }
    if ($channel === 'matrix') {
        if (!empty($channelMessages['matrix']) && is_string($channelMessages['matrix'])) {
            return $channelMessages['matrix'];
        }
        return $channelMessages['vk'] ?? $channelMessages['telegram'] ?? '';
    }
    return '';
}

function truncateMessageUniversal(string $message): string {
    if (mb_strlen($message) > 4000) {
        return mb_substr($message, 0, 3950) . "\n... (truncated)";
    }
    return $message;
}

/** Допустимые GET sender для вебхука Яндекс Маркета (Partner API POST /notification). */
function marketNotificationSenderIds(): array {
    return ['market', 'market/notification'];
}

function isMarketNotificationSender(string $request_sender): bool {
    $needle = strtolower(trim($request_sender));
    return $needle !== '' && in_array($needle, marketNotificationSenderIds(), true);
}

/** Текущее UTC-время для поля time в Response (всегда «сейчас», не из тела запроса). */
function marketNotificationResponseTime(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
}

/**
 * Ответ Partner API push sendNotification: 200 + Response или 4xx/5xx + error.
 */
function emitMarketNotificationApiResponse(int $http_code, ?string $error_type = null, ?string $error_message = null): void {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($http_code);
    if ($error_type !== null) {
        $payload = [
            'error' => [
                'type' => $error_type,
                'message' => $error_message ?? '',
            ],
        ];
    } else {
        $payload = [
            'version' => '1.0.0',
            'name' => 'all-notifications',
            'time' => marketNotificationResponseTime(),
        ];
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Если в правиле задан непустой senders — срабатывает только при GET sender из этого списка.
 * Значения сравниваются в нижнем регистре (grafana, kuma, market, market/notification). Пустой sender — запрос без фильтра в URL.
 */
function ruleMatchesSenders(array $rule, string $request_sender): bool {
    if (!isset($rule['senders']) || !is_array($rule['senders'])) {
        return true;
    }
    $allowed = [];
    foreach ($rule['senders'] as $s) {
        if (is_string($s)) {
            $t = strtolower(trim($s));
            if ($t !== '') {
                $allowed[] = $t;
            }
        }
    }
    if ($allowed === []) {
        return true;
    }
    $needle = strtolower(trim($request_sender));
    return in_array($needle, $allowed, true);
}

function isScheduleMatch(array $rule, int $day, int $hour): bool {
    if (!empty($rule['always_send'])) {
        return true;
    }
    $sched = $rule['schedule'] ?? [];
    if (empty($sched)) {
        return true;
    }
    if (!empty($sched['days']) && !in_array($day, $sched['days'])) {
        return false;
    }
    if (!empty($sched['hours']) && !in_array($hour, $sched['hours'])) {
        return false;
    }
    return true;
}

function sendToTelegram(array $config, string $chat_id, string $text, ?string $parse_mode = null): array {
    $token = $config['bot_token'];
    $proxies = $config['proxies'] ?? [];
    $timeout = $config['timeout'] ?? 5;
    $proxy = !empty($proxies) ? $proxies[0] : null;

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $postFields = [
        'chat_id' => $chat_id,
        'text'    => $text,
        'disable_web_page_preview' => true,
    ];
    if ($parse_mode !== null && $parse_mode !== '') {
        $postFields['parse_mode'] = $parse_mode;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    if ($proxy) curl_setopt($ch, CURLOPT_PROXY, $proxy);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) return ['success' => false, 'error' => "cURL: $curl_error"];
    if ($http_code !== 200) return ['success' => false, 'error' => "HTTP $http_code, response: $response"];
    $resp = json_decode($response, true);
    if (!$resp || empty($resp['ok'])) return ['success' => false, 'error' => "Telegram API: " . ($resp['description'] ?? $response)];
    return ['success' => true, 'error' => ''];
}

function sendToVk(array $config, string $user_id, string $text): array {
    $access_token = $config['access_token'];
    $api_version  = $config['api_version'];
    $proxies = $config['proxies'] ?? [];
    $timeout = $config['timeout'] ?? 5;
    $proxy = !empty($proxies) ? $proxies[0] : null;
    $random_id = (int) (microtime(true) * 1000) . mt_rand(1, 9999);

    $url = "https://api.vk.com/method/messages.send";
    $postFields = [
        'access_token' => $access_token,
        'user_id'      => $user_id,
        'random_id'    => $random_id,
        'message'      => $text,
        'v'            => $api_version,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    if ($proxy) curl_setopt($ch, CURLOPT_PROXY, $proxy);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) return ['success' => false, 'error' => "cURL: $curl_error"];
    if ($http_code !== 200) return ['success' => false, 'error' => "HTTP $http_code, response: $response"];
    $resp = json_decode($response, true);
    if (isset($resp['error'])) {
        $error_msg = $resp['error']['error_msg'] ?? json_encode($resp['error']);
        return ['success' => false, 'error' => "VK API: $error_msg"];
    }
    if (!isset($resp['response'])) return ['success' => false, 'error' => "Invalid VK response: $response"];
    return ['success' => true, 'error' => ''];
}

/**
 * Отправка в Matrix (Client-Server API): m.room.message в room_id.
 * room_id — полный идентификатор комнаты (!xxx:server).
 */
function sendToMatrix(array $config, string $room_id, string $text): array {
    $base = rtrim((string) ($config['homeserver_url'] ?? ''), '/');
    $token = (string) ($config['access_token'] ?? '');
    if ($base === '' || $token === '') {
        return ['success' => false, 'error' => 'matrix_config: homeserver_url or access_token empty'];
    }
    $room_id = trim($room_id);
    if ($room_id === '' || $room_id[0] !== '!') {
        return ['success' => false, 'error' => 'Invalid Matrix room_id (expected !room:server)'];
    }

    $proxies = $config['proxies'] ?? [];
    $timeout = $config['timeout'] ?? 10;
    $proxy = !empty($proxies) ? $proxies[0] : null;

    if (mb_strlen($text) > 32000) {
        $text = mb_substr($text, 0, 31900) . "\n... (truncated)";
    }

    $txn = 'sn-' . bin2hex(random_bytes(10));
    $pathRoom = rawurlencode($room_id);
    $url = $base . '/_matrix/client/v3/rooms/' . $pathRoom . '/send/m.room.message/' . rawurlencode($txn);

    $payload = json_encode([
        'msgtype' => 'm.text',
        'body'    => $text,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    if ($proxy) {
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
    }

    $response = curl_exec($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['success' => false, 'error' => "cURL: $curl_error"];
    }
    if ($http_code !== 200) {
        return ['success' => false, 'error' => "HTTP $http_code, response: $response"];
    }
    $resp = json_decode($response, true);
    if (!is_array($resp) || empty($resp['event_id'])) {
        return ['success' => false, 'error' => "Invalid Matrix response: $response"];
    }
    return ['success' => true, 'error' => ''];
}
