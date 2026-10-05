-- Auditoría de la plataforma: lo que hacen los super-admins (altas, suspensiones, impersonación...).
CREATE TABLE IF NOT EXISTS platform_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NULL,
    admin_name VARCHAR(120) NULL,
    action VARCHAR(64) NOT NULL,
    entity_type VARCHAR(40) NOT NULL,
    entity_uuid CHAR(36) NULL,
    before_data TEXT NULL,
    after_data TEXT NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    KEY idx_platform_audit_entity (entity_type, entity_uuid, created_at),
    KEY idx_platform_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
