-- Avisos dentro de la app (campanita), por usuario. alert_id: si es una alerta crítica confirmable.
CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    event VARCHAR(40) NOT NULL,
    title VARCHAR(191) NOT NULL,
    body TEXT NULL,
    url VARCHAR(191) NULL,
    is_critical TINYINT(1) NOT NULL DEFAULT 0,
    alert_id INT UNSIGNED NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_notifications_uuid (uuid),
    KEY idx_notifications_user (user_id, read_at, id),
    KEY idx_notifications_alert (alert_id),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
