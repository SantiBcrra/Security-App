-- Sectores jerárquicos dentro de cada planta (planta > nave > sector, hasta 3 niveles).
-- path materializado: "/3/7/12/" = ids desde la raíz; permite traer un sector con todos sus
-- descendientes con WHERE path LIKE '/3/7/%'.
CREATE TABLE IF NOT EXISTS sectors (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    site_id INT UNSIGNED NOT NULL,
    parent_id INT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(30) NULL,
    path VARCHAR(191) NOT NULL DEFAULT '/',
    depth TINYINT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY uq_sectors_uuid (uuid),
    KEY idx_sectors_site (site_id, parent_id),
    KEY idx_sectors_path (path),
    KEY idx_sectors_updated (updated_at),
    CONSTRAINT fk_sectors_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_sectors_parent FOREIGN KEY (parent_id) REFERENCES sectors (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
