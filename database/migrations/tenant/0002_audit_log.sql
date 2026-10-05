-- Auditoría de la empresa: quién hizo qué, con el antes/después (JSON en TEXT).
-- actor_type: 'user' (Etapa 3), 'platform_admin' (impersonación/soporte) o 'system' (cron, sync).
CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    actor_type VARCHAR(20) NOT NULL,
    actor_id INT UNSIGNED NULL,
    actor_name VARCHAR(120) NULL,
    action VARCHAR(64) NOT NULL,
    entity_type VARCHAR(40) NOT NULL,
    entity_uuid CHAR(36) NULL,
    before_data TEXT NULL,
    after_data TEXT NULL,
    ip VARCHAR(45) NULL,
    device VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    KEY idx_audit_entity (entity_type, entity_uuid, created_at),
    KEY idx_audit_actor (actor_type, actor_id, created_at),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
