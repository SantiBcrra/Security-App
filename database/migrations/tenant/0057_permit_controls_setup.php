<?php
declare(strict_types=1);

use App\Core\Uuid;

/** Etapa 13 (entrega 2): vigía de fuego, extensión y suspensión en work_permits + reglas de aviso. Idempotente. */
return function (PDO $db): void {
    $cols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_permits'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $add = [
        'fire_watch_minutes' => 'ADD COLUMN fire_watch_minutes SMALLINT UNSIGNED NULL AFTER critical_fails',
        'fire_watch_until'   => 'ADD COLUMN fire_watch_until DATETIME NULL AFTER fire_watch_minutes',
        'extended_by'        => 'ADD COLUMN extended_by INT UNSIGNED NULL AFTER extended_until',
        'extended_at'        => 'ADD COLUMN extended_at DATETIME NULL AFTER extended_by',
        'suspended_at'       => 'ADD COLUMN suspended_at DATETIME NULL AFTER started_at',
    ];
    $missing = array_values(array_diff_key($add, array_flip($cols)));
    if ($missing) {
        $db->exec('ALTER TABLE work_permits ' . implode(', ', $missing));
    }
    $everyone = [['type' => 'approvers'], ['type' => 'reporter'], ['type' => 'sector_supervisors'], ['type' => 'role', 'value' => 'responsable_hys']];
    $rules = [
        ['Gases fuera de rango (permiso suspendido)', 'permit.gas_alarm', $everyone, ['app', 'email', 'push', 'whatsapp']],
        ['Permiso de trabajo suspendido', 'permit.suspended', [['type' => 'approvers'], ['type' => 'reporter'], ['type' => 'sector_supervisors']], ['app', 'push']],
    ];
    $exists = $db->prepare('SELECT COUNT(*) FROM notification_rules WHERE event = ?');
    $insert = $db->prepare('INSERT INTO notification_rules (uuid, name, event, min_severity_level, sector_id, recipients, channels, is_active, created_at, updated_at)
        VALUES (?, ?, ?, NULL, NULL, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    foreach ($rules as [$name, $event, $recipients, $channels]) {
        $exists->execute([$event]);
        if ((int) $exists->fetchColumn() === 0) {
            $insert->execute([Uuid::v4(), $name, $event, json_encode($recipients), json_encode($channels)]);
        }
    }
};
