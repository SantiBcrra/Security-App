-- Usuarios de la empresa (viven SOLO en la base de esta empresa).
-- Login con email o DNI. password_hash NULL hasta que la persona activa su cuenta con el link.
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(191) NULL,
    dni VARCHAR(12) NULL,
    password_hash VARCHAR(255) NULL,
    role_id INT UNSIGNED NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    access_expires_at DATETIME NULL,
    totp_secret_enc TEXT NULL,
    totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    totp_last_step BIGINT UNSIGNED NULL,
    activation_token_hash CHAR(64) NULL,
    activation_expires_at DATETIME NULL,
    last_login_at DATETIME NULL,
    password_changed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_users_uuid (uuid),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_dni (dni),
    UNIQUE KEY uq_users_activation (activation_token_hash),
    KEY idx_users_role (role_id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
