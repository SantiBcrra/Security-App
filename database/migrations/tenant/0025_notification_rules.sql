-- Reglas: qué evento, con qué severidad mínima y en qué sector (incluye los de abajo) avisa a quién y por qué canal.
-- recipients: JSON [{"type":"role","value":"responsable_hys"},{"type":"sector_supervisors"},{"type":"assignee"},{"type":"reporter"},{"type":"user","value":"uuid"}]
-- channels: JSON ["app","email","push","whatsapp"]
CREATE TABLE IF NOT EXISTS notification_rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    name VARCHAR(120) NOT NULL,
    event VARCHAR(40) NOT NULL,
    min_severity_level TINYINT UNSIGNED NULL,
    sector_id INT UNSIGNED NULL,
    recipients TEXT NOT NULL,
    channels TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_notification_rules_uuid (uuid),
    KEY idx_notification_rules_event (event, is_active),
    CONSTRAINT fk_notification_rules_sector FOREIGN KEY (sector_id) REFERENCES sectors (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
