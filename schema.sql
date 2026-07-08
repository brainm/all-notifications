-- MySQL / MariaDB schema for all-notifications.
-- Применение: mysql -u USER -p DATABASE < schema.sql
-- Для существующей БД с notification_queue см. блок миграции в конце.

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) DEFAULT NULL,
    login VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) DEFAULT NULL,
    registration_token CHAR(64) DEFAULT NULL,
    registration_token_expires_at DATETIME DEFAULT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_users_login (login),
    UNIQUE KEY uk_users_email (email),
    UNIQUE KEY uk_users_registration_token (registration_token),
    KEY idx_users_active (enabled, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS web_push_subscriptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    endpoint TEXT NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    p256dh VARCHAR(255) NOT NULL,
    auth_key VARCHAR(255) NOT NULL,
    user_agent VARCHAR(512) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_push_endpoint_hash (endpoint_hash),
    KEY idx_push_user_id (user_id),
    CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS web_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    rule_name VARCHAR(128) NOT NULL,
    message_text MEDIUMTEXT NOT NULL,
    payload_json MEDIUMTEXT DEFAULT NULL,
    seen_at DATETIME DEFAULT NULL,
    push_sent_at DATETIME DEFAULT NULL,
    push_error TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_web_user_seen (user_id, seen_at, created_at),
    KEY idx_web_created_at (created_at),
    CONSTRAINT fk_web_notif_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_name VARCHAR(128) NOT NULL,
    channel ENUM('telegram', 'vk', 'matrix', 'web', 'email') NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    message_text MEDIUMTEXT NOT NULL,
    telegram_parse_mode VARCHAR(32) DEFAULT NULL,
    payload_json MEDIUMTEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_created_at (created_at),
    KEY idx_rule_name (rule_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Миграция существующей установки (выполнить вручную при необходимости):
-- ALTER TABLE notification_queue
--     MODIFY channel ENUM('telegram', 'vk', 'matrix', 'web', 'email') NOT NULL;
-- ALTER TABLE notification_queue
--     ADD COLUMN payload_json MEDIUMTEXT DEFAULT NULL AFTER telegram_parse_mode;
--
-- Приглашения и nullable email/password:
-- ALTER TABLE users MODIFY email VARCHAR(255) NULL;
-- ALTER TABLE users MODIFY password_hash VARCHAR(255) NULL;
-- ALTER TABLE users ADD COLUMN registration_token CHAR(64) DEFAULT NULL AFTER password_hash;
-- ALTER TABLE users ADD COLUMN registration_token_expires_at DATETIME DEFAULT NULL AFTER registration_token;
-- ALTER TABLE users ADD UNIQUE KEY uk_users_registration_token (registration_token);
