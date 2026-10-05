-- Alertas críticas (riesgo inminente) con escalamiento: si nadie confirma "Recibido", sube de nivel.
CREATE TABLE IF NOT EXISTS alerts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    observation_id INT UNSIGNED NOT NULL,
    level TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_escalation_at DATETIME NULL,
    acked_at DATETIME NULL,
    acked_by INT UNSIGNED NULL,
    acked_name VARCHAR(120) NULL,
    closed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_alerts_uuid (uuid),
    KEY idx_alerts_pending (acked_at, closed_at, next_escalation_at),
    KEY idx_alerts_observation (observation_id),
    CONSTRAINT fk_alerts_observation FOREIGN KEY (observation_id) REFERENCES observations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
