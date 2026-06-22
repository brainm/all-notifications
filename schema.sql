-- Очередь отложенных уведомлений (MySQL / MariaDB).
-- Применение: mysql -u USER -p DATABASE < schema.sql

CREATE TABLE IF NOT EXISTS notification_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_name VARCHAR(128) NOT NULL,
    channel ENUM('telegram', 'vk', 'matrix') NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    message_text MEDIUMTEXT NOT NULL,
    telegram_parse_mode VARCHAR(32) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_created_at (created_at),
    KEY idx_rule_name (rule_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
