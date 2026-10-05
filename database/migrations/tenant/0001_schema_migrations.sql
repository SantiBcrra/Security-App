-- Registro de migraciones aplicadas en la base de ESTA empresa.
CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(191) NOT NULL,
    batch INT UNSIGNED NOT NULL,
    duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    applied_at DATETIME NOT NULL,
    UNIQUE KEY uq_schema_migrations_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
