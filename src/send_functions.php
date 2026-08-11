<?php
// ---------------------- ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ------------------------------

/**
 * Каталог архивов: для /var/log/notifications.log → /var/log/notifications/
 */
function notificationLogArchiveDir(string $log_file): string {
    return dirname($log_file) . '/notifications';
}

/**
 * В начале нового месяца архивирует notifications.log в YYYY-MM.log.gz и начинает чистый файл.
 * Маркер текущего месяца: <archive_dir>/.current-month
 * @return string|null Имя архива YYYY-MM при ротации, иначе null
 */
function rotateNotificationLogIfNeeded(string $log_file): ?string {
    $currentMonth = date('Y-m');
    $archiveDir = notificationLogArchiveDir($log_file);
    $markerPath = $archiveDir . '/.current-month';

    if (!is_dir($archiveDir) && !@mkdir($archiveDir, 0755, true) && !is_dir($archiveDir)) {
        return null;
    }

    $fp = @fopen($markerPath, 'c+');
    if ($fp === false) {
        return null;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return null;
    }

    rewind($fp);
    $storedMonth = trim((string) stream_get_contents($fp));
    if ($storedMonth === '') {
        $storedMonth = null;
    }

    if ($storedMonth === $currentMonth) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return null;
    }

    $archivedMonth = null;
    if ($storedMonth !== null && is_file($log_file)) {
        $size = filesize($log_file);
        if ($size !== false && $size > 0) {
            $archivePath = $archiveDir . '/' . $storedMonth . '.log.gz';
            $plain = file_get_contents($log_file);
            $gz = ($plain !== false && $plain !== '') ? gzencode($plain, 9) : false;
            if ($gz !== false && file_put_contents($archivePath, $gz, LOCK_EX) !== false) {
                file_put_contents($log_file, '', LOCK_EX);
                @chmod($archivePath, 0644);
                $archivedMonth = $storedMonth;
            }
        } elseif ($size === 0) {
            // Пустой лог — просто переходим на новый месяц без архива.
        }
    }

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, $currentMonth);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if (!is_file($log_file)) {
        @touch($log_file);
    }

    return $archivedMonth;
}

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
        foreach (['telegram', 'vk', 'matrix', 'web', 'email', 'parse_mode', 'telegram_parse_mode', 'subject', 'title'] as $k) {
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

    $web = (!empty($data['web']) && is_string($data['web'])) ? truncateMessageUniversal($data['web']) : $default;
    $email = (!empty($data['email']) && is_string($data['email'])) ? truncateMessageUniversal($data['email']) : $default;

    return ['telegram' => $tg, 'vk' => $vk, 'matrix' => $mx, 'web' => $web, 'email' => $email];
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
    if ($channel === 'web') {
        if (!empty($channelMessages['web']) && is_string($channelMessages['web'])) {
            return $channelMessages['web'];
        }
        return $channelMessages['vk'] ?? $channelMessages['telegram'] ?? $channelMessages['matrix'] ?? '';
    }
    if ($channel === 'email') {
        if (!empty($channelMessages['email']) && is_string($channelMessages['email'])) {
            return $channelMessages['email'];
        }
        return $channelMessages['web'] ?? $channelMessages['vk'] ?? $channelMessages['telegram'] ?? $channelMessages['matrix'] ?? '';
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
 * Найти правила, где recipients.vk содержит peer_id / from_id (как строку или число).
 *
 * @return list<string>
 */
function findVkRulesMatchingPeer(array $rules, int $peerId, int $fromId): array {
    $needles = array_unique(array_filter([
        (string) $peerId,
        (string) $fromId,
        $peerId !== 0 ? (string) abs($peerId) : '',
        $fromId !== 0 ? (string) abs($fromId) : '',
    ], static fn($v) => $v !== ''));

    $matched = [];
    foreach ($rules as $ruleName => $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $recipients = $rule['recipients']['vk'] ?? [];
        if (!is_array($recipients) || $recipients === []) {
            continue;
        }
        foreach ($recipients as $recipient) {
            $id = is_int($recipient) || is_float($recipient)
                ? (string) (int) $recipient
                : trim((string) $recipient);
            if ($id === '') {
                continue;
            }
            if (in_array($id, $needles, true)) {
                $matched[] = (string) $ruleName;
                break;
            }
        }
    }
    return $matched;
}

/**
 * Логирует Callback API message_read (прочтение ЛС сообщества ↔ участник).
 */
function handleVkMessageReadEvent(array $data, array $rules): void {
    $object = $data['object'] ?? [];
    if (!is_array($object)) {
        $object = [];
    }

    $peerId = (int) ($object['peer_id'] ?? 0);
    $fromId = (int) ($object['from_id'] ?? 0);
    $readMessageId = (int) ($object['read_message_id'] ?? 0);
    $conversationMessageId = $object['conversation_message_id'] ?? null;
    $groupId = $data['group_id'] ?? null;
    $eventId = $data['event_id'] ?? null;
    $apiV = $data['v'] ?? null;

    $matchedRules = findVkRulesMatchingPeer($rules, $peerId, $fromId);
    $rulesLabel = $matchedRules === [] ? '(no matching rules recipients.vk)' : implode(', ', $matchedRules);

    $summary = sprintf(
        'VK message_read: peer_id=%d from_id=%d read_message_id=%d conversation_message_id=%s group_id=%s event_id=%s v=%s rules=[%s]',
        $peerId,
        $fromId,
        $readMessageId,
        $conversationMessageId === null || $conversationMessageId === '' ? '?' : (string) $conversationMessageId,
        $groupId === null || $groupId === '' ? '?' : (string) $groupId,
        $eventId === null || $eventId === '' ? '?' : (string) $eventId,
        $apiV === null || $apiV === '' ? '?' : (string) $apiV,
        $rulesLabel
    );
    logMessage($summary);
    logMessage('VK message_read full JSON: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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

function sanitizeEmailSubject(string $subject): string {
    $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
    if ($subject === '') {
        return 'Notification';
    }
    if (mb_strlen($subject) > 200) {
        return mb_substr($subject, 0, 200);
    }
    return $subject;
}

/**
 * Тема письма: GET subject → JSON subject → JSON title → имя правила.
 */
function resolveEmailSubject(array $data, string $rule_name, ?string $get_subject = null): string {
    if ($get_subject !== null && trim($get_subject) !== '') {
        return sanitizeEmailSubject($get_subject);
    }
    if (!empty($data['subject']) && is_string($data['subject'])) {
        return sanitizeEmailSubject($data['subject']);
    }
    if (!empty($data['title']) && is_string($data['title'])) {
        return sanitizeEmailSubject($data['title']);
    }
    return sanitizeEmailSubject($rule_name);
}

function resolveEmailSubjectFromPayload(?string $payload_json, string $rule_name, ?string $get_subject = null): string {
    if ($get_subject !== null && trim($get_subject) !== '') {
        return sanitizeEmailSubject($get_subject);
    }
    if ($payload_json !== null && $payload_json !== '') {
        $data = json_decode($payload_json, true);
        if (is_array($data)) {
            return resolveEmailSubject($data, $rule_name);
        }
    }
    return sanitizeEmailSubject($rule_name);
}

function isValidEmailRecipient(string $email): bool {
    return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
}

function resolveEmailFromAddress(array $config): string {
    $from = trim((string) ($config['from_email'] ?? $config['from'] ?? ''));
    if ($from !== '' && isValidEmailRecipient($from)) {
        return $from;
    }
    $username = trim((string) ($config['username'] ?? ''));
    if ($username !== '' && isValidEmailRecipient($username)) {
        return $username;
    }
    $host = trim((string) ($config['host'] ?? ''));
    if ($host !== '') {
        $domain = preg_replace('/^smtp\./i', '', $host);
        if (str_contains($domain, '.')) {
            return 'noreply@' . $domain;
        }
    }
    return 'noreply@localhost';
}

function requireComposerAutoload(): bool {
    static $loaded = false;
    if ($loaded) {
        return true;
    }
    foreach ([__DIR__ . '/vendor/autoload.php', dirname(__DIR__) . '/vendor/autoload.php'] as $path) {
        if (is_readable($path)) {
            require_once $path;
            $loaded = true;
            return true;
        }
    }
    return false;
}

function sendToEmail(array $config, string $to, string $subject, string $text): array {
    if (!isValidEmailRecipient($to)) {
        return ['success' => false, 'error' => "Invalid email address: $to"];
    }
    if (!requireComposerAutoload()) {
        return ['success' => false, 'error' => 'Composer vendor not installed (phpmailer/phpmailer)'];
    }

    $host = trim((string) ($config['host'] ?? ''));
    if ($host === '') {
        return ['success' => false, 'error' => 'email_config: host is required'];
    }
    $from = resolveEmailFromAddress($config);

    try {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = (int) ($config['port'] ?? 587);
        $encryption = strtolower(trim((string) ($config['encryption'] ?? 'tls')));
        if ($encryption === 'ssl') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPAutoTLS = false;
            $mail->SMTPSecure = false;
        }
        $username = trim((string) ($config['username'] ?? ''));
        if ($username !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = (string) ($config['password'] ?? '');
        }
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = (int) ($config['timeout'] ?? 15);
        $fromName = trim((string) ($config['from_name'] ?? ''));
        $mail->setFrom($from, $fromName);
        if (!empty($config['reply_to']) && is_string($config['reply_to'])) {
            $mail->addReplyTo($config['reply_to']);
        }
        $mail->addAddress(trim($to));
        $mail->Subject = sanitizeEmailSubject($subject);
        $mail->Body = $text;
        $mail->isHTML(false);
        $mail->send();
        return ['success' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
