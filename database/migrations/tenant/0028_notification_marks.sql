-- Marcas "ya se hizo" para tareas que deben correr una sola vez (recordatorio diario de una
-- vencida, resumen del día...). mark_key ej: "overdue:15:2026-10-06", "digest:daily:2026-10-06".
CREATE TABLE IF NOT EXISTS notification_marks (
    mark_key VARCHAR(100) NOT NULL PRIMARY KEY,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
