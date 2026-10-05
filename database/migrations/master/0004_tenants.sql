-- Empresas registradas. Cada una tiene SU PROPIA base de datos (sin tenant_id en las tablas).
-- db_pass_enc: contraseña de esa base cifrada con AES-256-GCM (clave = app.key de config.local.php).
CREATE TABLE IF NOT EXISTS tenants (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(40) NOT NULL,
    legal_name VARCHAR(191) NULL,
    cuit CHAR(11) NULL,
    logo_path VARCHAR(191) NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'America/Argentina/Buenos_Aires',
    status VARCHAR(16) NOT NULL DEFAULT 'trial',
    status_reason VARCHAR(255) NULL,
    plan VARCHAR(40) NULL,
    settings TEXT NULL,
    db_host VARCHAR(191) NOT NULL,
    db_port INT UNSIGNED NOT NULL DEFAULT 3306,
    db_name VARCHAR(64) NOT NULL,
    db_user VARCHAR(64) NOT NULL,
    db_pass_enc TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    suspended_at DATETIME NULL,
    UNIQUE KEY uq_tenants_uuid (uuid),
    UNIQUE KEY uq_tenants_slug (slug),
    UNIQUE KEY uq_tenants_db (db_host, db_port, db_name),
    KEY idx_tenants_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
