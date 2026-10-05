-- Sectores asignados a cada usuario (alcance "Sus sectores": ve esos sectores y sus hijos).
CREATE TABLE IF NOT EXISTS user_sectors (
    user_id INT UNSIGNED NOT NULL,
    sector_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, sector_id),
    KEY idx_user_sectors_sector (sector_id),
    CONSTRAINT fk_user_sectors_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_user_sectors_sector FOREIGN KEY (sector_id) REFERENCES sectors (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
