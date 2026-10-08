<?php
declare(strict_types=1);

/** Etapa 12 (entrega 3): fotos por partes también para incidentes reportados desde el celular. Idempotente. */
return function (PDO $db): void {
    $cols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uploads'")
        ->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('incident_uuid', $cols, true)) {
        $db->exec('ALTER TABLE uploads ADD COLUMN incident_uuid CHAR(36) NULL AFTER item_key, ADD KEY idx_uploads_incident (incident_uuid)');
    }
};
