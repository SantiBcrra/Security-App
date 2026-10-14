<?php
declare(strict_types=1);

use App\Core\Uuid;

/** Automatizaciones fase 5: último estado informado por cada celular (batería, GPS, pendientes) y regla de aviso. Idempotente. */
return function (PDO $db): void {
    $cols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_devices'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $add = [];
    if (!in_array('status', $cols, true)) $add[] = 'ADD COLUMN status TEXT NULL';
    if (!in_array('status_at', $cols, true)) $add[] = 'ADD COLUMN status_at DATETIME NULL';
    if ($add) $db->exec('ALTER TABLE user_devices ' . implode(', ', $add));
    $db->prepare('INSERT INTO notification_rules (uuid, name, event, min_severity_level, sector_id, recipients, channels, is_active, created_at, updated_at)
        SELECT ?, ?, ?, NULL, NULL, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notification_rules WHERE event = ?)')
        ->execute([Uuid::v4(), 'Celular de un guardia con problemas durante la ronda', 'guard.device',
            json_encode([['type' => 'role', 'value' => 'responsable_hys'], ['type' => 'role', 'value' => 'supervisor']]), json_encode(['app', 'push']), 'guard.device']);
};
