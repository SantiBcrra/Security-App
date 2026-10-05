-- Contadores correlativos por empresa (ej: número de observación OBS-000123).
-- Se incrementan con SELECT ... FOR UPDATE dentro de una transacción: sin huecos ni duplicados.
CREATE TABLE IF NOT EXISTS sequences (
    name VARCHAR(40) NOT NULL PRIMARY KEY,
    value INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
