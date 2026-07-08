<?php

const NOTIFICATION_QUEUE_MAX_AGE_DAYS = 7;

function notificationQueuePdo(array $db_config): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $required = ['host', 'database', 'username', 'password'];
    foreach ($required as $key) {
        if (empty($db_config[$key]) && $db_config[$key] !== '0') {
            throw new RuntimeException("db_config: missing key \"{$key}\"");
        }
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db_config['host'],
        (int) ($db_config['port'] ?? 3306),
        $db_config['database'],
        $db_config['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, $db_config['username'], $db_config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    return $pdo;
}

function enqueueNotification(
    PDO $pdo,
    string $rule_name,
    string $channel,
    string $recipient,
    string $message_text,
    ?string $telegram_parse_mode = null,
    ?string $payload_json = null
): bool {
    $stmt = $pdo->prepare(
        'INSERT INTO notification_queue (rule_name, channel, recipient, message_text, telegram_parse_mode, payload_json)
         VALUES (:rule_name, :channel, :recipient, :message_text, :telegram_parse_mode, :payload_json)'
    );

    return $stmt->execute([
        ':rule_name'            => $rule_name,
        ':channel'              => $channel,
        ':recipient'            => $recipient,
        ':message_text'         => $message_text,
        ':telegram_parse_mode'  => $telegram_parse_mode,
        ':payload_json'         => $payload_json,
    ]);
}

function queueRuleDeliveries(
    PDO $pdo,
    string $rule_name,
    array $rule,
    array $channelMessages,
    ?string $telegram_parse_mode,
    array $default_recipients,
    ?string $payload_json = null
): int {
    $queued = 0;

    foreach ($rule['channels'] as $channel) {
        if (!is_string($channel) || $channel === '') {
            continue;
        }

        $recipients = $rule['recipients'][$channel] ?? [];
        if ($recipients === [] && !empty($default_recipients[$channel])) {
            $recipients = $default_recipients[$channel];
        }
        if ($recipients === []) {
            continue;
        }

        $text = messageTextForChannel($channel, $channelMessages);
        if ($text === '') {
            continue;
        }

        $parse_mode = ($channel === 'telegram') ? $telegram_parse_mode : null;

        foreach ($recipients as $recipient) {
            if (is_int($recipient)) {
                $recipientStr = (string) $recipient;
            } elseif (is_string($recipient) && trim($recipient) !== '') {
                $recipientStr = trim($recipient);
            } else {
                continue;
            }
            enqueueNotification($pdo, $rule_name, $channel, $recipientStr, $text, $parse_mode, $payload_json);
            $queued++;
        }
    }

    return $queued;
}

function purgeExpiredNotifications(PDO $pdo, int $max_age_days = NOTIFICATION_QUEUE_MAX_AGE_DAYS): int {
    $stmt = $pdo->prepare(
        'DELETE FROM notification_queue WHERE created_at < (NOW() - INTERVAL :days DAY)'
    );
    $stmt->bindValue(':days', $max_age_days, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->rowCount();
}

function sendQueuedNotification(array $row, array $config, PDO $pdo): array {
    $channel = $row['channel'];
    $recipient = $row['recipient'];
    $text = $row['message_text'];
    $parse_mode = $row['telegram_parse_mode'] ?? null;
    $payload_json = $row['payload_json'] ?? null;

    if ($channel === 'telegram') {
        return sendToTelegram($config['telegram_config'], $recipient, $text, $parse_mode ?: null);
    }
    if ($channel === 'vk') {
        return sendToVk($config['vk_config'], $recipient, $text);
    }
    if ($channel === 'matrix') {
        return sendToMatrix($config['matrix_config'], $recipient, $text);
    }
    if ($channel === 'web') {
        if (!function_exists('resolveWebUserId')) {
            require_once __DIR__ . '/web.php';
        }
        $userId = resolveWebUserId($pdo, $recipient);
        if ($userId === null) {
            return ['success' => false, 'error' => "Web user not found or disabled: $recipient"];
        }
        $result = deliverWebNotification($pdo, $config, $userId, $row['rule_name'], $text, $payload_json);
        return ['success' => $result['success'], 'error' => $result['push']['error'] ?? ''];
    }
    if ($channel === 'email') {
        $email_config = $config['email_config'] ?? null;
        if (!is_array($email_config)) {
            return ['success' => false, 'error' => 'email_config missing in config.php'];
        }
        $subject = resolveEmailSubjectFromPayload($payload_json, (string) $row['rule_name']);
        return sendToEmail($email_config, $recipient, $subject, $text);
    }

    return ['success' => false, 'error' => "Unknown channel '{$channel}'"];
}

function processNotificationQueue(PDO $pdo, array $config): array {
    $rules = $config['rules'] ?? [];
    $now = new DateTime();
    $current_day = (int) $now->format('N');
    $current_hour = (int) $now->format('G');

    $stats = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'orphaned' => 0];

    $stmt = $pdo->query(
        'SELECT id, rule_name, channel, recipient, message_text, telegram_parse_mode, payload_json
         FROM notification_queue
         ORDER BY created_at ASC, id ASC'
    );
    $rows = $stmt->fetchAll();
    if ($rows === []) {
        return $stats;
    }

    $deleteStmt = $pdo->prepare('DELETE FROM notification_queue WHERE id = :id');

    foreach ($rows as $row) {
        $rule_name = $row['rule_name'];
        if (!isset($rules[$rule_name]) || !is_array($rules[$rule_name])) {
            $stats['orphaned']++;
            logMessage("Queue #{$row['id']}: rule '{$rule_name}' not found, left until expiry.");
            continue;
        }

        $rule = $rules[$rule_name];
        if (empty($rule['enabled'])) {
            $stats['skipped']++;
            logMessage("Queue #{$row['id']}: rule '{$rule_name}' disabled, waiting.");
            continue;
        }

        if (!isScheduleMatch($rule, $current_day, $current_hour)) {
            $stats['skipped']++;
            continue;
        }

        $result = sendQueuedNotification($row, $config, $pdo);
        if ($result['success']) {
            $deleteStmt->execute([':id' => $row['id']]);
            $stats['sent']++;
            logMessage("Queue #{$row['id']}: delivered {$row['channel']} to {$row['recipient']} (rule {$rule_name}).");
        } else {
            $stats['failed']++;
            logMessage("Queue #{$row['id']}: delivery failed - {$result['error']}");
        }
    }

    return $stats;
}

function acquireQueueWorkerLock(PDO $pdo, string $lock_name = 'all_notifications_queue'): bool {
    $stmt = $pdo->prepare('SELECT GET_LOCK(:name, 0) AS acquired');
    $stmt->execute([':name' => $lock_name]);
    $row = $stmt->fetch();

    return isset($row['acquired']) && (int) $row['acquired'] === 1;
}

function releaseQueueWorkerLock(PDO $pdo, string $lock_name = 'all_notifications_queue'): void {
    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
    $stmt->execute([':name' => $lock_name]);
}
