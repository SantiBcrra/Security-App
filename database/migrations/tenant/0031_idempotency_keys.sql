-- Operaciones de sincronización ya procesadas: reenviar el mismo op_id devuelve el mismo
-- resultado y nunca duplica (el celular reintenta cuando se corta la conexión).
CREATE TABLE IF NOT EXISTS idempotency_keys (
    op_id CHAR(36) NOT NULL PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    op_type VARCHAR(40) NOT NULL,
    response TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_idempotency_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
