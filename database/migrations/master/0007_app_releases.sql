-- App Android (distribución propia, sin Google Play): versiones del APK subidas por el super-admin.
-- La app consulta la última activa y se actualiza sola; min_version_code obliga a actualizar.
CREATE TABLE IF NOT EXISTS app_releases (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    platform VARCHAR(20) NOT NULL,
    version_code INT UNSIGNED NOT NULL,
    version_name VARCHAR(40) NOT NULL,
    min_version_code INT UNSIGNED NOT NULL DEFAULT 0,
    notes TEXT NULL,
    path VARCHAR(255) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    uploaded_by INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_app_releases_uuid (uuid),
    UNIQUE KEY uq_app_releases_platform_code (platform, version_code),
    KEY idx_app_releases_platform (platform, is_active, version_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
