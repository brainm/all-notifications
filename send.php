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
 * остаются как обычно: отключённое правило не сработает; вне расписания сообщение
 * попадёт в очередь БД и будет доставлено cron.php при наступлении окна schedule.
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
foreach (['log_file', 'telegram_config', 'vk_config', 'matrix_config', 'rules', 'db_config'] as $key) {
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

require __DIR__ . '/send_functions.php';
require __DIR__ . '/queue.php';

try {
    $queue_pdo = notificationQueuePdo($config['db_config']);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die('Database connection failed: ' . $e->getMessage());
}

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
$queued_any = false;
foreach ($rules as $rule_name => $rule) {
    if ($rules_allowlist !== null && !isset($rules_allowlist[$rule_name])) {
        logMessage("Rule '$rule_name' not listed in rules=, skipped.");
        continue;
    }

    if (empty($rule['enabled'])) {
        logMessage("Rule '$rule_name' is disabled, skipped.");
        continue;
    }

    if (!ruleMatchesSenders($rule, $request_sender)) {
        logMessage("Rule '$rule_name' senders filter does not match request sender \"{$request_sender}\", skipped.");
        continue;
    }

    if (!isScheduleMatch($rule, $current_day, $current_hour)) {
        $queued = queueRuleDeliveries(
            $queue_pdo,
            $rule_name,
            $rule,
            $channelMessages,
            $telegram_parse_mode,
            $default_recipients
        );
        if ($queued > 0) {
            $queued_any = true;
            logMessage("Rule '$rule_name' outside schedule, queued $queued message(s) for later delivery.");
        } else {
            logMessage("Rule '$rule_name' outside schedule, nothing to queue.");
        }
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
    if (!$sent_any && !$queued_any) {
        logMessage('Market notification: no matching rules or all channel sends failed.');
    } elseif ($queued_any && !$sent_any) {
        logMessage('Market notification: queued for later delivery.');
    } else {
        logMessage('Market notification: dispatched to channels.');
    }
    emitMarketNotificationApiResponse(200);
}

if (!$sent_any && !$queued_any) {
    logMessage("No notifications were sent (no matching rules or all failed).");
    http_response_code(200);
    echo "No notifications sent.";
} elseif ($queued_any && !$sent_any) {
    logMessage('Notifications queued for later delivery.');
    http_response_code(200);
    echo "Notifications queued for later delivery.";
} else {
    http_response_code(200);
    echo "Notifications sent according to rules.";
}
exit;
