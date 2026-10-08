<?php
declare(strict_types=1);

use App\Core\Uuid;

/** Etapa 12 (entrega 2): recordatorios de incidentes a SyH. Idempotente por evento. */
return function (PDO $db): void {
    $hys = [['type' => 'role', 'value' => 'responsable_hys']];
    $rules = [
        ['Investigación sin empezar', 'incident.investigation_overdue', array_merge($hys, [['type' => 'sector_supervisors']]), ['app', 'email']],
        ['Falta el N° de siniestro de la ART', 'incident.art_pending', $hys, ['app', 'email']],
        ['Resumen semanal de bajas abiertas', 'incident.open_leaves', $hys, ['app', 'email']],
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
