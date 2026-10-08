<?php
declare(strict_types=1);

/**
 * Etapa 10 (entrega 3): las fotos por partes también pueden ser evidencia de una acción CAPA.
 * Una subida pertenece a una observación (observation_uuid) o a una acción (action_uuid).
 * Idempotente: revisa las columnas antes de cambiarlas.
 */
return function (PDO $db): void {
    $cols = $db->query("SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uploads'")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (($cols['observation_uuid'] ?? 'YES') === 'NO') {
        $db->exec('ALTER TABLE uploads MODIFY observation_uuid CHAR(36) NULL');
    }
    if (!isset($cols['action_uuid'])) {
        $db->exec('ALTER TABLE uploads ADD COLUMN action_uuid CHAR(36) NULL AFTER observation_uuid, ADD KEY idx_uploads_action (action_uuid)');
    }
};
