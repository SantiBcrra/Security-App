-- Investigación de un incidente (Etapa 12, entrega 2). Una por incidente.
-- team: JSON [user_id]. five_whys: JSON {problem, whys: [texto]}. cause_tree: JSON [{id, parent, text, type}]
-- (type: hecho | causa_inmediata | causa_basica). root_causes: JSON [catalog_items.id del catálogo "causa"].
CREATE TABLE IF NOT EXISTS incident_investigations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    incident_id INT UNSIGNED NOT NULL,
    team TEXT NULL,
    five_whys LONGTEXT NULL,
    cause_tree LONGTEXT NULL,
    root_causes TEXT NULL,
    conclusions TEXT NULL,
    lessons TEXT NULL,
    started_at DATETIME NOT NULL,
    started_by INT UNSIGNED NULL,
    completed_at DATETIME NULL,
    completed_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_incident_investigations_incident (incident_id),
    CONSTRAINT fk_incident_investigations_incident FOREIGN KEY (incident_id) REFERENCES incidents (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Horas-hombre trabajadas y dotación promedio por planta y mes (base de los índices de frecuencia y gravedad).
CREATE TABLE IF NOT EXISTS worked_hours (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    month CHAR(7) NOT NULL,
    hours DECIMAL(12,1) NOT NULL DEFAULT 0,
    headcount INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_worked_hours_site_month (site_id, month),
    CONSTRAINT fk_worked_hours_site FOREIGN KEY (site_id) REFERENCES sites (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
