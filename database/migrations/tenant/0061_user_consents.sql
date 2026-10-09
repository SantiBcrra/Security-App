-- Consentimiento de los empleados para la app Android (celular personal, Ley 25.326): qué texto aceptó, cuándo y desde qué dispositivo.
CREATE TABLE IF NOT EXISTS user_consents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    version VARCHAR(20) NOT NULL,
    text_sha256 CHAR(64) NOT NULL,
    device_uuid CHAR(36) NULL,
    ip VARCHAR(45) NULL,
    accepted_at DATETIME NOT NULL,
    UNIQUE KEY uq_user_consents_user_version (user_id, version),
    CONSTRAINT fk_user_consents_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
