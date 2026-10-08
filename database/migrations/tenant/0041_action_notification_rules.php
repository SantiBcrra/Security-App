<?php
declare(strict_types=1);

use App\Core\Uuid;

/**
 * Etapa 10 (entrega 2): las reglas "acción asignada / vencida" de observaciones pasan a los eventos
 * de acciones CAPA (mismos destinatarios y canales) y se agregan las reglas nuevas.
 * Idempotente: solo agrega un evento si la empresa todavía no tiene ninguna regla para él.
 */
return function (PDO $db): void {
    $db->exec("UPDATE notification_rules SET event = 'action.assigned', updated_at = UTC_TIMESTAMP() WHERE event = 'observation.assigned'");
    $db->exec("UPDATE notification_rules SET event = 'action.overdue', updated_at = UTC_TIMESTAMP() WHERE event = 'observation.overdue'");

    $hys = ['type' => 'role', 'value' => 'responsable_hys'];
    $rules = [
        ['Acción asignada', 'action.assigned', [['type' => 'assignee']], ['app', 'email', 'push']],
        ['Acción por vencer', 'action.due_soon', [['type' => 'assignee']], ['app', 'email', 'push']],
        ['Acción vencida', 'action.overdue', [['type' => 'assignee']], ['app', 'email']],
        ['Acción vencida hace varios días', 'action.overdue_escalated', [['type' => 'sector_supervisors'], $hys], ['app', 'email']],
        ['Acción para verificar', 'action.closed', [['type' => 'verifiers']], ['app', 'email']],
        ['Verificación atrasada', 'action.verify_overdue', [['type' => 'verifiers']], ['app']],
        ['Acción verificada', 'action.verified', [['type' => 'assignee'], ['type' => 'reporter']], ['app']],
        ['Cierre rechazado', 'action.rejected', [['type' => 'assignee']], ['app', 'email', 'push']],
        ['Acción cancelada', 'action.cancelled', [['type' => 'assignee']], ['app']],
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
