-- Acciones correctivas y preventivas (CAPA). Pueden nacer de cualquier origen (origin_type + origin_id).
-- "Vencida" no es un estado: due_on < hoy con estado abierta/en_curso.
-- cycle: vuelta de trabajo; sube cuando la verificación rechaza el cierre (la evidencia vieja queda en su ciclo).
CREATE TABLE IF NOT EXISTS actions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    number INT UNSIGNED NOT NULL,
    title VARCHAR(191) NOT NULL,
    description TEXT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'correctiva',
    priority VARCHAR(10) NOT NULL DEFAULT 'media',
    origin_type VARCHAR(20) NOT NULL DEFAULT 'manual',
    origin_id INT UNSIGNED NULL,
    site_id INT UNSIGNED NULL,
    sector_id INT UNSIGNED NULL,
    responsible_user_id INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NULL,
    due_on DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'abierta',
    cycle SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    closed_at DATETIME NULL,
    closed_by INT UNSIGNED NULL,
    closure_text TEXT NULL,
    verify_due_on DATE NULL,
    verified_at DATETIME NULL,
    verified_by INT UNSIGNED NULL,
    verification_text TEXT NULL,
    effective TINYINT(1) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY uq_actions_uuid (uuid),
    UNIQUE KEY uq_actions_number (number),
    KEY idx_actions_status_due (status, due_on),
    KEY idx_actions_responsible (responsible_user_id, status),
    KEY idx_actions_origin (origin_type, origin_id),
    KEY idx_actions_sector (sector_id, status),
    KEY idx_actions_updated (updated_at, id),
    CONSTRAINT fk_actions_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_actions_sector FOREIGN KEY (sector_id) REFERENCES sectors (id),
    CONSTRAINT fk_actions_responsible FOREIGN KEY (responsible_user_id) REFERENCES users (id),
    CONSTRAINT fk_actions_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_actions_closed_by FOREIGN KEY (closed_by) REFERENCES users (id),
    CONSTRAINT fk_actions_verified_by FOREIGN KEY (verified_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Línea de tiempo de cada acción. Solo inserción: nunca se edita ni se borra.
-- type: created | started | updated | comment | evidence | closed | verified | rejected | cancelled | migrated
CREATE TABLE IF NOT EXISTS action_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    action_id INT UNSIGNED NOT NULL,
    type VARCHAR(20) NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NULL,
    comment TEXT NULL,
    data TEXT NULL,
    user_id INT UNSIGNED NULL,
    actor_name VARCHAR(120) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_action_events_uuid (uuid),
    KEY idx_action_events_action (action_id, id),
    CONSTRAINT fk_action_events_action FOREIGN KEY (action_id) REFERENCES actions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Evidencia (fotos o PDF). Inmutable: el archivo no se modifica, sha256 permite verificarlo.
-- kind: evidencia (para el cierre, del ciclo `cycle`) | referencia (adjunta al crear o comentar).
CREATE TABLE IF NOT EXISTS action_attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    action_id INT UNSIGNED NOT NULL,
    kind VARCHAR(20) NOT NULL DEFAULT 'evidencia',
    cycle SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    path VARCHAR(191) NOT NULL,
    thumb_path VARCHAR(191) NULL,
    original_name VARCHAR(191) NULL,
    mime VARCHAR(60) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    width INT UNSIGNED NULL,
    height INT UNSIGNED NULL,
    exif TEXT NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_action_attachments_uuid (uuid),
    KEY idx_action_attachments_action (action_id, kind, cycle),
    CONSTRAINT fk_action_attachments_action FOREIGN KEY (action_id) REFERENCES actions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
