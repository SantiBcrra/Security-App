-- Inspecciones y checklists (Etapa 11).
-- Plantillas con versiones: la estructura (secciones e ítems, JSON) de una versión ya usada nunca cambia.
CREATE TABLE IF NOT EXISTS inspection_templates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    scope VARCHAR(12) NOT NULL DEFAULT 'general',
    equipment_type_id INT UNSIGNED NULL,
    current_version_id INT UNSIGNED NULL,
    preset_key VARCHAR(60) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY uq_inspection_templates_uuid (uuid),
    KEY idx_inspection_templates_type (equipment_type_id, is_active),
    KEY idx_inspection_templates_updated (updated_at, id),
    CONSTRAINT fk_inspection_templates_type FOREIGN KEY (equipment_type_id) REFERENCES catalog_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inspection_template_versions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    template_id INT UNSIGNED NOT NULL,
    version SMALLINT UNSIGNED NOT NULL,
    structure LONGTEXT NOT NULL,
    item_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_inspection_template_versions_uuid (uuid),
    UNIQUE KEY uq_inspection_template_versions_num (template_id, version),
    CONSTRAINT fk_inspection_template_versions_tpl FOREIGN KEY (template_id) REFERENCES inspection_templates (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- La inspección hecha: evidencia inmutable (original_data + SHA-256), como las observaciones.
-- result: conforme | con_observaciones | no_conforme_critico. score = % de ítems que cumplen (sin contar N/A).
CREATE TABLE IF NOT EXISTS inspections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    number INT UNSIGNED NOT NULL,
    template_id INT UNSIGNED NOT NULL,
    template_version_id INT UNSIGNED NOT NULL,
    schedule_id INT UNSIGNED NULL,
    equipment_id INT UNSIGNED NULL,
    site_id INT UNSIGNED NULL,
    sector_id INT UNSIGNED NULL,
    inspector_user_id INT UNSIGNED NULL,
    done_at_device DATETIME NOT NULL,
    received_at DATETIME NOT NULL,
    lat DECIMAL(10,7) NULL,
    lng DECIMAL(10,7) NULL,
    gps_accuracy_m INT UNSIGNED NULL,
    result VARCHAR(20) NOT NULL,
    score TINYINT UNSIGNED NULL,
    items_ok SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    items_fail SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    items_critical_fail SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    notes TEXT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'completa',
    annulled_at DATETIME NULL,
    annulled_by INT UNSIGNED NULL,
    annul_reason TEXT NULL,
    original_data LONGTEXT NOT NULL,
    original_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY uq_inspections_uuid (uuid),
    UNIQUE KEY uq_inspections_number (number),
    KEY idx_inspections_equipment (equipment_id, done_at_device),
    KEY idx_inspections_sector (sector_id, done_at_device),
    KEY idx_inspections_template (template_id, done_at_device),
    KEY idx_inspections_inspector (inspector_user_id),
    KEY idx_inspections_updated (updated_at, id),
    CONSTRAINT fk_inspections_template FOREIGN KEY (template_id) REFERENCES inspection_templates (id),
    CONSTRAINT fk_inspections_version FOREIGN KEY (template_version_id) REFERENCES inspection_template_versions (id),
    CONSTRAINT fk_inspections_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id),
    CONSTRAINT fk_inspections_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_inspections_sector FOREIGN KEY (sector_id) REFERENCES sectors (id),
    CONSTRAINT fk_inspections_inspector FOREIGN KEY (inspector_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Una fila por ítem respondido (solo inserción). ok: 1 cumple, 0 no cumple, NULL no aplica / informativo.
CREATE TABLE IF NOT EXISTS inspection_answers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    inspection_id INT UNSIGNED NOT NULL,
    item_key CHAR(36) NOT NULL,
    section_title VARCHAR(160) NULL,
    item_text VARCHAR(255) NOT NULL,
    value VARCHAR(255) NULL,
    ok TINYINT(1) NULL,
    critical TINYINT(1) NOT NULL DEFAULT 0,
    comment TEXT NULL,
    action_id INT UNSIGNED NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY uq_inspection_answers_item (inspection_id, item_key),
    KEY idx_inspection_answers_action (action_id),
    CONSTRAINT fk_inspection_answers_inspection FOREIGN KEY (inspection_id) REFERENCES inspections (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inspection_attachments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    inspection_id INT UNSIGNED NOT NULL,
    item_key CHAR(36) NULL,
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
    UNIQUE KEY uq_inspection_attachments_uuid (uuid),
    KEY idx_inspection_attachments_item (inspection_id, item_key),
    CONSTRAINT fk_inspection_attachments_inspection FOREIGN KEY (inspection_id) REFERENCES inspections (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Línea de tiempo (solo inserción): created | action_created | annulled | comment
CREATE TABLE IF NOT EXISTS inspection_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    inspection_id INT UNSIGNED NOT NULL,
    type VARCHAR(20) NOT NULL,
    comment TEXT NULL,
    data TEXT NULL,
    user_id INT UNSIGNED NULL,
    actor_name VARCHAR(120) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_inspection_events_uuid (uuid),
    KEY idx_inspection_events_inspection (inspection_id, id),
    CONSTRAINT fk_inspection_events_inspection FOREIGN KEY (inspection_id) REFERENCES inspections (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
