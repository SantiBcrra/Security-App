-- Preferencias por usuario y canal (solo afecta avisos NO críticos: los críticos llegan siempre).
CREATE TABLE IF NOT EXISTS notification_prefs (
    user_id INT UNSIGNED NOT NULL,
    channel VARCHAR(20) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, channel),
    CONSTRAINT fk_notification_prefs_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
