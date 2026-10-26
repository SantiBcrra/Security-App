<?php
declare(strict_types=1);

use App\Core\Uuid;

/**
 * Reglas por defecto de: reporte de fin de turno de cada franja del puesto (`guard.shift_report`, por email a SyH; la casilla de
 * RRHH se agrega en Configuración → Notificaciones y alertas) y condición insegura anotada en el libro (`logbook.unsafe`, crítico,
 * a SyH y supervisores por app, notificación y email). Idempotente: no toca reglas existentes de esos eventos.
 */
return function (PDO $db): void {
    $q = $db->prepare('INSERT INTO notification_rules (uuid, name, event, min_severity_level, sector_id, recipients, channels, is_active, created_at, updated_at)
        SELECT ?, ?, ?, NULL, NULL, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notification_rules WHERE event = ?)');
    $hys = ['type' => 'role', 'value' => 'responsable_hys'];
    $sup = ['type' => 'role', 'value' => 'supervisor'];
    foreach ([
        ['Reporte de fin de turno (RRHH)', 'guard.shift_report', [$hys], ['email']],
        ['Condición insegura en el libro de guardia', 'logbook.unsafe', [$hys, $sup], ['app', 'push', 'email']],
    ] as [$name, $event, $recipients, $channels]) {
        $q->execute([Uuid::v4(), $name, $event, json_encode($recipients), json_encode($channels), $event]);
    }
};
