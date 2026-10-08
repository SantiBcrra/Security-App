-- Programas de inspección (Etapa 11, entrega 2): qué checklist, sobre qué, cada cuánto y quién.
-- target_type: equipo (equipment_id) | tipo (todos los equipos activos de equipment_type_id, opcionalmente
-- dentro de sector_id y sus subsectores) | sector (sector_id).
-- assignee_type: user (assignee_user_id) | role (assignee_role = slug) | sector_supervisors.
CREATE TABLE IF NOT EXISTS inspection_programs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    template_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    frequency VARCHAR(10) NOT NULL DEFAULT 'diaria',
    weekday TINYINT UNSIGNED NULL,
    monthday TINYINT UNSIGNED NULL,
    target_type VARCHAR(10) NOT NULL,
    equipment_id INT UNSIGNED NULL,
    equipment_type_id INT UNSIGNED NULL,
    sector_id INT UNSIGNED NULL,
    assignee_type VARCHAR(20) NOT NULL DEFAULT 'sector_supervisors',
    assignee_user_id INT UNSIGNED NULL,
    assignee_role VARCHAR(60) NULL,
    action_responsible_user_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY uq_inspection_programs_uuid (uuid),
    KEY idx_inspection_programs_template (template_id, is_active),
    CONSTRAINT fk_inspection_programs_template FOREIGN KEY (template_id) REFERENCES inspection_templates (id),
    CONSTRAINT fk_inspection_programs_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id),
    CONSTRAINT fk_inspection_programs_type FOREIGN KEY (equipment_type_id) REFERENCES catalog_items (id),
    CONSTRAINT fk_inspection_programs_sector FOREIGN KEY (sector_id) REFERENCES sectors (id),
    CONSTRAINT fk_inspection_programs_assignee FOREIGN KEY (assignee_user_id) REFERENCES users (id),
    CONSTRAINT fk_inspection_programs_resp FOREIGN KEY (action_responsible_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada vez que toca hacer una inspección (la genera el cron). Único por programa + objetivo + período:
-- el cron puede correr muchas veces sin duplicar. "Vencida" = pendiente con due_on < hoy (se calcula).
-- period: diaria = el día; semanal = semana ISO (desde el lunes); mensual = el mes. due_from..due_on = ventana.
CREATE TABLE IF NOT EXISTS inspection_schedule (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    program_id INT UNSIGNED NOT NULL,
    target_key VARCHAR(20) NOT NULL,
    equipment_id INT UNSIGNED NULL,
    sector_id INT UNSIGNED NULL,
    period_key VARCHAR(12) NOT NULL,
    due_from DATE NOT NULL,
    due_on DATE NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'pendiente',
    inspection_id INT UNSIGNED NULL,
    done_on DATE NULL,
    on_time TINYINT(1) NULL,
    skip_reason TEXT NULL,
    skipped_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_inspection_schedule_uuid (uuid),
    UNIQUE KEY uq_inspection_schedule_period (program_id, target_key, period_key),
    KEY idx_inspection_schedule_due (status, due_on),
    KEY idx_inspection_schedule_sector (sector_id, due_on),
    KEY idx_inspection_schedule_equipment (equipment_id, status),
    KEY idx_inspection_schedule_updated (updated_at, id),
    CONSTRAINT fk_inspection_schedule_program FOREIGN KEY (program_id) REFERENCES inspection_programs (id),
    CONSTRAINT fk_inspection_schedule_inspection FOREIGN KEY (inspection_id) REFERENCES inspections (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
