-- Empresas contratistas. Su personal está en employees (contractor_id).
CREATE TABLE IF NOT EXISTS contractors (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    name VARCHAR(191) NOT NULL,
    cuit CHAR(11) NULL,
    contact_name VARCHAR(120) NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(191) NULL,
    art_insurer VARCHAR(120) NULL,
    art_expires_on DATE NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY uq_contractors_uuid (uuid),
    UNIQUE KEY uq_contractors_name (name),
    KEY idx_contractors_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
