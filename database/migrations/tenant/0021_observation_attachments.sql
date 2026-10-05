-- Fotos/evidencia. El archivo original nunca se modifica: sha256 permite verificarlo.
-- La miniatura se genera aparte (thumb_path). exif = metadata original, si el servidor la pudo leer.
CREATE TABLE IF NOT EXISTS observation_attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    observation_id INT UNSIGNED NOT NULL,
    path VARCHAR(191) NOT NULL,
    thumb_path VARCHAR(191) NULL,
    original_name VARCHAR(191) NULL,
    mime VARCHAR(60) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    width INT UNSIGNED NULL,
    height INT UNSIGNED NULL,
    exif TEXT NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_observation_attachments_uuid (uuid),
    KEY idx_observation_attachments_obs (observation_id),
    CONSTRAINT fk_observation_attachments_obs FOREIGN KEY (observation_id) REFERENCES observations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
