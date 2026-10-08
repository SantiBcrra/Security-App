<?php
declare(strict_types=1);

/** Etapa 11 (entrega 3): fotos por partes también para inspecciones (por ítem). Idempotente. */
return function (PDO $db): void {
    $cols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uploads'")
        ->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('inspection_uuid', $cols, true)) {
        $db->exec('ALTER TABLE uploads ADD COLUMN inspection_uuid CHAR(36) NULL AFTER action_uuid, ADD COLUMN item_key CHAR(36) NULL AFTER inspection_uuid,
            ADD KEY idx_uploads_inspection (inspection_uuid)');
    }
};
