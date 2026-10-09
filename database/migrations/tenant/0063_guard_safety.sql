-- App Android (entrega 3): seguridad del guardia.
-- Posiciones durante la ronda (servicio en primer plano del celular; llegan en lote, también las guardadas sin señal).
CREATE TABLE IF NOT EXISTS patrol_tracks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    round_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    recorded_at_device DATETIME NOT NULL,
    received_at DATETIME NOT NULL,
    lat DECIMAL(10,7) NOT NULL,
    lng DECIMAL(10,7) NOT NULL,
    accuracy_m DECIMAL(11,2) NULL,
    battery TINYINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_patrol_tracks_uuid (uuid),
    KEY idx_patrol_tracks_round (round_id, recorded_at_device),
    CONSTRAINT fk_patrol_tracks_round FOREIGN KEY (round_id) REFERENCES patrol_rounds (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Botón de pánico: por datos (directo o en cola) o avisado por SMS. Aviso crítico y re-aviso hasta que alguien lo atiende.
CREATE TABLE IF NOT EXISTS panic_alerts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    round_id INT UNSIGNED NULL,
    lat DECIMAL(10,7) NULL,
    lng DECIMAL(10,7) NULL,
    accuracy_m DECIMAL(11,2) NULL,
    triggered_at_device DATETIME NOT NULL,
    received_at DATETIME NOT NULL,
    via VARCHAR(10) NOT NULL,
    sms_sent TINYINT(1) NOT NULL DEFAULT 0,
    level TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_escalation_at DATETIME NULL,
    acked_at DATETIME NULL,
    acked_by INT UNSIGNED NULL,
    acked_name VARCHAR(120) NULL,
    ack_comment VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_panic_alerts_uuid (uuid),
    KEY idx_panic_alerts_pending (acked_at, next_escalation_at),
    CONSTRAINT fk_panic_alerts_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
