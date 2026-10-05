-- Subidas de fotos por partes desde la app (reanudables). El archivo se arma en
-- storage/tenants/{empresa}/uploads/{uuid}.part y al completarse se verifica tamaño y SHA-256.
CREATE TABLE IF NOT EXISTS uploads (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    observation_uuid CHAR(36) NOT NULL,
    original_name VARCHAR(191) NULL,
    size_bytes INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    received_bytes INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(12) NOT NULL DEFAULT 'receiving',
    attachment_uuid CHAR(36) NULL,
    error VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_uploads_uuid (uuid),
    KEY idx_uploads_observation (observation_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
