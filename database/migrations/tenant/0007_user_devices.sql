-- Sesiones de la app móvil: un registro por usuario + dispositivo.
-- refresh_token_hash: SHA-256 del refresh token vigente; previous_token_hash: el anterior,
-- para detectar reutilización (robo) y revocar el dispositivo.
CREATE TABLE IF NOT EXISTS user_devices (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    device_uuid CHAR(36) NOT NULL,
    device_name VARCHAR(120) NULL,
    refresh_token_hash CHAR(64) NOT NULL,
    previous_token_hash CHAR(64) NULL,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    revoked_at DATETIME NULL,
    revoked_reason VARCHAR(120) NULL,
    ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_user_devices_uuid (uuid),
    UNIQUE KEY uq_user_devices_user_device (user_id, device_uuid),
    UNIQUE KEY uq_user_devices_refresh (refresh_token_hash),
    KEY idx_user_devices_previous (previous_token_hash),
    CONSTRAINT fk_user_devices_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
