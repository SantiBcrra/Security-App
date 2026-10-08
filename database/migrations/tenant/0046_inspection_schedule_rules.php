<?php
declare(strict_types=1);

use App\Core\Uuid;

/** Etapa 11 (entrega 2): avisos de inspecciones programadas. Idempotente por evento. */
return function (PDO $db): void {
    $rules = [
        ['Inspección para hoy', 'inspection.due', [['type' => 'assignee']], ['app', 'push']],
        ['Inspección vencida', 'inspection.overdue', [['type' => 'assignee'], ['type' => 'sector_supervisors']], ['app', 'email']],
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
