<?php
// Reglas de notificación por defecto (editables por cada empresa). Solo si todavía no hay reglas.
use App\Core\Uuid;

return function (PDO $db): void {
    if ((int) $db->query('SELECT COUNT(*) FROM notification_rules')->fetchColumn() > 0) {
        return;
    }
    $rules = [
        ['Riesgo inminente', 'observation.imminent', null,
            [['type' => 'sector_supervisors'], ['type' => 'role', 'value' => 'responsable_hys'], ['type' => 'role', 'value' => 'admin_empresa']],
            ['app', 'email', 'push', 'whatsapp']],
        ['Observación nueva de severidad alta', 'observation.created', 3,
            [['type' => 'sector_supervisors'], ['type' => 'role', 'value' => 'responsable_hys']], ['app', 'email']],
        ['Acción asignada', 'observation.assigned', null, [['type' => 'assignee']], ['app', 'email', 'push']],
        ['Acción vencida', 'observation.overdue', null, [['type' => 'assignee'], ['type' => 'role', 'value' => 'responsable_hys']], ['app', 'email']],
        ['Observación cerrada', 'observation.closed', null, [['type' => 'reporter']], ['app']],
    ];
    $stmt = $db->prepare('INSERT INTO notification_rules (uuid, name, event, min_severity_level, sector_id, recipients, channels, is_active, created_at, updated_at)
        VALUES (?, ?, ?, ?, NULL, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    foreach ($rules as [$name, $event, $minLevel, $recipients, $channels]) {
        $stmt->execute([Uuid::v4(), $name, $event, $minLevel, json_encode($recipients), json_encode($channels)]);
    }
};
