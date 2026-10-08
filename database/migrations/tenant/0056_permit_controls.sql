-- Etapa 13 (entrega 2): controles específicos de los permisos de trabajo.
-- Mediciones de gases (espacio confinado): solo inserción; ok = todos los valores medidos dentro de los límites.
CREATE TABLE IF NOT EXISTS work_permit_measurements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    permit_id INT UNSIGNED NOT NULL,
    measured_at DATETIME NOT NULL,
    o2 DECIMAL(5,2) NULL,
    lel DECIMAL(5,2) NULL,
    co DECIMAL(7,2) NULL,
    h2s DECIMAL(7,2) NULL,
    instrument VARCHAR(120) NULL,
    measured_by VARCHAR(160) NULL,
    ok TINYINT(1) NOT NULL,
    out_of_range VARCHAR(191) NULL,
    user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_work_permit_measurements_uuid (uuid),
    KEY idx_work_permit_measurements_permit (permit_id, measured_at),
    CONSTRAINT fk_work_permit_measurements_permit FOREIGN KEY (permit_id) REFERENCES work_permits (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Puntos de bloqueo (LOTO): energía, dispositivo y candado; quién lo colocó y quién lo retiró.
CREATE TABLE IF NOT EXISTS work_permit_isolations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    permit_id INT UNSIGNED NOT NULL,
    point VARCHAR(160) NOT NULL,
    energy VARCHAR(20) NOT NULL,
    device VARCHAR(120) NULL,
    lock_number VARCHAR(40) NULL,
    placed_by VARCHAR(160) NOT NULL,
    placed_at DATETIME NOT NULL,
    zero_verified TINYINT(1) NOT NULL DEFAULT 0,
    removed_by VARCHAR(160) NULL,
    removed_at DATETIME NULL,
    user_id INT UNSIGNED NULL,
    UNIQUE KEY uq_work_permit_isolations_uuid (uuid),
    KEY idx_work_permit_isolations_permit (permit_id, removed_at),
    CONSTRAINT fk_work_permit_isolations_permit FOREIGN KEY (permit_id) REFERENCES work_permits (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
