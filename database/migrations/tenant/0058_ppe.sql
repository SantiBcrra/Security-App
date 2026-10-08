-- Etapa 14: elementos de protección personal (EPP). Solo personal propio, sin stock.
-- Catálogo: qué EPP usa la empresa (marca, modelo, certificación, vida útil, si lleva talle).
CREATE TABLE IF NOT EXISTS ppe_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    name VARCHAR(120) NOT NULL,
    category VARCHAR(20) NOT NULL,
    model VARCHAR(120) NULL,
    brand VARCHAR(120) NULL,
    certified TINYINT(1) NOT NULL DEFAULT 0,
    certification VARCHAR(120) NULL,
    life_days SMALLINT UNSIGNED NULL,
    size_type VARCHAR(10) NULL,
    preset_key VARCHAR(80) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    UNIQUE KEY uq_ppe_items_uuid (uuid),
    UNIQUE KEY uq_ppe_items_preset (preset_key),
    KEY idx_ppe_items_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Matriz: qué EPP corresponde a cada puesto (obligatorio o según tarea), cantidad y vida útil propia.
CREATE TABLE IF NOT EXISTS ppe_matrix (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    position_id INT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    life_days SMALLINT UNSIGNED NULL,
    mandatory TINYINT(1) NOT NULL DEFAULT 1,
    notes VARCHAR(191) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_ppe_matrix_uuid (uuid),
    UNIQUE KEY uq_ppe_matrix_position_item (position_id, item_id),
    KEY idx_ppe_matrix_updated (updated_at),
    CONSTRAINT fk_ppe_matrix_position FOREIGN KEY (position_id) REFERENCES positions (id),
    CONSTRAINT fk_ppe_matrix_item FOREIGN KEY (item_id) REFERENCES ppe_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extras por empleado: EPP puntual para una persona (anteojos graduados, respirador por una tarea).
CREATE TABLE IF NOT EXISTS ppe_employee_extras (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    life_days SMALLINT UNSIGNED NULL,
    reason VARCHAR(191) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_ppe_employee_extras_uuid (uuid),
    UNIQUE KEY uq_ppe_employee_extras_employee_item (employee_id, item_id),
    KEY idx_ppe_employee_extras_updated (updated_at),
    CONSTRAINT fk_ppe_employee_extras_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
    CONSTRAINT fk_ppe_employee_extras_item FOREIGN KEY (item_id) REFERENCES ppe_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Entregas: acto firmado (en pantalla o planilla en papel escaneada), inmutable (original_data + hash). Se anula, no se borra.
CREATE TABLE IF NOT EXISTS ppe_deliveries (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    number INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    position_name VARCHAR(120) NULL,
    delivered_at DATETIME NOT NULL,
    delivered_by INT UNSIGNED NULL,
    reason VARCHAR(20) NOT NULL,
    notes TEXT NULL,
    signature_mode VARCHAR(10) NOT NULL,
    signature_path VARCHAR(255) NOT NULL,
    signature_sha256 CHAR(64) NOT NULL,
    signature_mime VARCHAR(60) NOT NULL,
    paper_reason VARCHAR(191) NULL,
    signer_name VARCHAR(160) NOT NULL,
    signer_dni VARCHAR(12) NULL,
    lat DECIMAL(10,7) NULL,
    lng DECIMAL(10,7) NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    original_data LONGTEXT NOT NULL,
    original_hash CHAR(64) NOT NULL,
    voided_at DATETIME NULL,
    voided_by INT UNSIGNED NULL,
    void_reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_ppe_deliveries_uuid (uuid),
    UNIQUE KEY uq_ppe_deliveries_number (number),
    KEY idx_ppe_deliveries_employee (employee_id, delivered_at),
    KEY idx_ppe_deliveries_updated (updated_at),
    CONSTRAINT fk_ppe_deliveries_employee FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ítems de la entrega: copia de lo que era el elemento al entregarlo (la constancia no cambia si cambia el catálogo).
CREATE TABLE IF NOT EXISTS ppe_delivery_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    delivery_id INT UNSIGNED NOT NULL,
    item_id INT UNSIGNED NOT NULL,
    quantity SMALLINT UNSIGNED NOT NULL,
    size VARCHAR(10) NULL,
    item_name VARCHAR(120) NOT NULL,
    model VARCHAR(120) NULL,
    brand VARCHAR(120) NULL,
    certified TINYINT(1) NOT NULL DEFAULT 0,
    certification VARCHAR(120) NULL,
    life_days SMALLINT UNSIGNED NULL,
    next_due_on DATE NULL,
    returned_previous TINYINT(1) NULL,
    KEY idx_ppe_delivery_items_delivery (delivery_id),
    KEY idx_ppe_delivery_items_item (item_id),
    CONSTRAINT fk_ppe_delivery_items_delivery FOREIGN KEY (delivery_id) REFERENCES ppe_deliveries (id),
    CONSTRAINT fk_ppe_delivery_items_item FOREIGN KEY (item_id) REFERENCES ppe_items (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
