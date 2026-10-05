-- Configuración de la plataforma (SMTP, WhatsApp, Expo, estado del cron). Secretos cifrados con Crypto.
CREATE TABLE IF NOT EXISTS platform_settings (
    `key` VARCHAR(100) NOT NULL PRIMARY KEY,
    `value` TEXT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
