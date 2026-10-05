-- Límite simple de pedidos por dispositivo y minuto (sin Redis).
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket VARCHAR(80) NOT NULL,
    window_start DATETIME NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
