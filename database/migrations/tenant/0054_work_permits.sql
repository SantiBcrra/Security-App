-- Permisos de trabajo (Etapa 13). Lo aprobado queda congelado en original_data + SHA-256.
-- types: JSON [altura | caliente | espacio_confinado | loto | electrico] (un permiso puede combinar tipos).
-- status: solicitado | aprobado | rechazado | en_ejecucion | suspendido | cerrado | vencido | cancelado
CREATE TABLE IF NOT EXISTS work_permits (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    number INT UNSIGNED NOT NULL,
    types VARCHAR(191) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'solicitado',
    site_id INT UNSIGNED NULL,
    sector_id INT UNSIGNED NULL,
    equipment_id INT UNSIGNED NULL,
    location_text VARCHAR(191) NULL,
    lat DECIMAL(10,7) NULL,
    lng DECIMAL(10,7) NULL,
    task TEXT NOT NULL,
    contractor_id INT UNSIGNED NULL,
    valid_from DATETIME NOT NULL,
    valid_until DATETIME NOT NULL,
    extended_until DATETIME NULL,
    requested_by INT UNSIGNED NULL,
    approved_by INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    started_at DATETIME NULL,
    closed_at DATETIME NULL,
    closed_by INT UNSIGNED NULL,
    critical_fails SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status_reason TEXT NULL,
    original_data LONGTEXT NOT NULL,
    original_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY uq_work_permits_uuid (uuid),
    UNIQUE KEY uq_work_permits_number (number),
    KEY idx_work_permits_status (status, valid_until),
    KEY idx_work_permits_site (site_id, status),
    KEY idx_work_permits_sector (sector_id, status),
    KEY idx_work_permits_equipment (equipment_id, status),
    KEY idx_work_permits_requested (requested_by),
    KEY idx_work_permits_updated (updated_at, id),
    CONSTRAINT fk_work_permits_site FOREIGN KEY (site_id) REFERENCES sites (id),
    CONSTRAINT fk_work_permits_sector FOREIGN KEY (sector_id) REFERENCES sectors (id),
    CONSTRAINT fk_work_permits_equipment FOREIGN KEY (equipment_id) REFERENCES equipment (id),
    CONSTRAINT fk_work_permits_contractor FOREIGN KEY (contractor_id) REFERENCES contractors (id),
    CONSTRAINT fk_work_permits_requested FOREIGN KEY (requested_by) REFERENCES users (id),
    CONSTRAINT fk_work_permits_approved FOREIGN KEY (approved_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ejecutores y vigías: empleado (propio o de contratista) o externo con nombre y DNI.
CREATE TABLE IF NOT EXISTS work_permit_workers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    permit_id INT UNSIGNED NOT NULL,
    role VARCHAR(12) NOT NULL DEFAULT 'ejecutor',
    employee_id INT UNSIGNED NULL,
    external_name VARCHAR(160) NULL,
    external_dni VARCHAR(12) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_work_permit_workers_uuid (uuid),
    KEY idx_work_permit_workers_permit (permit_id),
    KEY idx_work_permit_workers_employee (employee_id),
    CONSTRAINT fk_work_permit_workers_permit FOREIGN KEY (permit_id) REFERENCES work_permits (id),
    CONSTRAINT fk_work_permit_workers_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Checklist previo de cada tipo (motor de la Etapa 11): versión usada y respuestas evaluadas en el servidor.
CREATE TABLE IF NOT EXISTS work_permit_checklists (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    permit_id INT UNSIGNED NOT NULL,
    permit_type VARCHAR(20) NOT NULL,
    template_version_id INT UNSIGNED NOT NULL,
    answers LONGTEXT NOT NULL,
    critical_fails SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    fails SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_work_permit_checklists_type (permit_id, permit_type),
    CONSTRAINT fk_work_permit_checklists_permit FOREIGN KEY (permit_id) REFERENCES work_permits (id),
    CONSTRAINT fk_work_permit_checklists_version FOREIGN KEY (template_version_id) REFERENCES inspection_template_versions (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Firmas (inmutables): imagen PNG con SHA-256, quién, cuándo, desde dónde.
-- role: solicitante | autorizante | ejecutor | vigia | cierre | recepcion | extension
CREATE TABLE IF NOT EXISTS work_permit_signatures (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    permit_id INT UNSIGNED NOT NULL,
    role VARCHAR(12) NOT NULL,
    worker_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NULL,
    signer_name VARCHAR(160) NOT NULL,
    signer_dni VARCHAR(12) NULL,
    path VARCHAR(191) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(191) NULL,
    lat DECIMAL(10,7) NULL,
    lng DECIMAL(10,7) NULL,
    signed_at DATETIME NOT NULL,
    UNIQUE KEY uq_work_permit_signatures_uuid (uuid),
    KEY idx_work_permit_signatures_permit (permit_id, role),
    CONSTRAINT fk_work_permit_signatures_permit FOREIGN KEY (permit_id) REFERENCES work_permits (id),
    CONSTRAINT fk_work_permit_signatures_worker FOREIGN KEY (worker_id) REFERENCES work_permit_workers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Línea de tiempo (solo inserción).
CREATE TABLE IF NOT EXISTS work_permit_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    permit_id INT UNSIGNED NOT NULL,
    type VARCHAR(20) NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NULL,
    comment TEXT NULL,
    data TEXT NULL,
    user_id INT UNSIGNED NULL,
    actor_name VARCHAR(120) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_work_permit_events_uuid (uuid),
    KEY idx_work_permit_events_permit (permit_id, id),
    CONSTRAINT fk_work_permit_events_permit FOREIGN KEY (permit_id) REFERENCES work_permits (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
