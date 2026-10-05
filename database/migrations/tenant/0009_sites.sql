-- Plantas / sitios de la empresa. Geocerca opcional (centro + radio en metros).
CREATE TABLE IF NOT EXISTS sites (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(30) NULL,
    address VARCHAR(191) NULL,
    city VARCHAR(100) NULL,
    province VARCHAR(100) NULL,
    lat DECIMAL(10,7) NULL,
    lng DECIMAL(10,7) NULL,
    geofence_radius_m INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY uq_sites_uuid (uuid),
    UNIQUE KEY uq_sites_name (name),
    KEY idx_sites_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
