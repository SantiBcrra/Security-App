-- Importaciones CSV/Excel: estado para procesar por lotes (y poder retomar si se corta).
CREATE TABLE IF NOT EXISTS imports (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    entity VARCHAR(30) NOT NULL,
    original_name VARCHAR(191) NOT NULL,
    file_path VARCHAR(191) NOT NULL,
    mapping TEXT NULL,
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    processed_rows INT UNSIGNED NOT NULL DEFAULT 0,
    created_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    errors MEDIUMTEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'uploaded',
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_imports_uuid (uuid),
    KEY idx_imports_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
