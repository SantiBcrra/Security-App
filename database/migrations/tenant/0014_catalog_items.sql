-- Catálogos configurables por empresa en una sola tabla:
-- catalog = tipo_riesgo | categoria | severidad | causa | tipo_equipo
-- level: peso numérico (severidad 1..5); color: para mostrar badges.
CREATE TABLE IF NOT EXISTS catalog_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    catalog VARCHAR(30) NOT NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(30) NULL,
    description VARCHAR(255) NULL,
    color CHAR(7) NULL,
    level TINYINT UNSIGNED NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY uq_catalog_items_uuid (uuid),
    UNIQUE KEY uq_catalog_items_name (catalog, name),
    KEY idx_catalog_items_list (catalog, is_active, sort_order),
    KEY idx_catalog_items_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
