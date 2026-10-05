-- Cola de envíos (email, push, whatsapp) y log de cada envío.
-- status: pending | sent | failed. Reintento exponencial con next_attempt_at.
CREATE TABLE IF NOT EXISTS notification_queue (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    channel VARCHAR(20) NOT NULL,
    user_id INT UNSIGNED NULL,
    to_address VARCHAR(191) NOT NULL,
    subject VARCHAR(191) NULL,
    body_text TEXT NULL,
    body_html MEDIUMTEXT NULL,
    payload TEXT NULL,
    event VARCHAR(40) NULL,
    entity_uuid CHAR(36) NULL,
    is_critical TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(10) NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL,
    last_error VARCHAR(500) NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_notification_queue_uuid (uuid),
    KEY idx_notification_queue_due (status, next_attempt_at),
    KEY idx_notification_queue_entity (entity_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
