-- Intentos de login de los usuarios de ESTA empresa (bloqueo por fuerza bruta).
-- email = identificador usado (email o DNI). Los datos de sus usuarios no salen de su base.
CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    scope VARCHAR(64) NOT NULL,
    email VARCHAR(191) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    success TINYINT(1) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_login_attempts_lookup (scope, email, ip, created_at),
    KEY idx_login_attempts_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
