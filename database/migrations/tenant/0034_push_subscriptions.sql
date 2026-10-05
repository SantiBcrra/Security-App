-- Suscripciones Web Push (navegador/PWA instalada) por usuario y dispositivo.
CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    device_uuid CHAR(36) NULL,
    endpoint VARCHAR(500) NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    p256dh VARCHAR(120) NOT NULL,
    auth VARCHAR(40) NOT NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    failed_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_push_subscriptions_endpoint (endpoint_hash),
    KEY idx_push_subscriptions_user (user_id),
    CONSTRAINT fk_push_subscriptions_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
