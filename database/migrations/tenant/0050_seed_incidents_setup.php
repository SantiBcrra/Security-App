<?php
declare(strict_types=1);

use App\Core\Uuid;
use App\Policies\Permissions;

/**
 * Etapa 12: el permiso "datos de salud" (incidentes) para los roles base que lo tienen (SyH, admin) y las
 * reglas de aviso de incidentes. Solo suma. Idempotente.
 */
return function (PDO $db): void {
    $roles = Permissions::baseRoles();
    $update = $db->prepare('UPDATE roles SET permissions = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?');
    foreach ($db->query('SELECT id, slug, permissions FROM roles WHERE is_system = 1')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $base = $roles[$row['slug']]['permissions']['incidentes'] ?? null;
        if ($base === null) {
            continue;
        }
        $permissions = json_decode((string) $row['permissions'], true) ?: [];
        $current = (array) ($permissions['incidentes']['acciones'] ?? []);
        $missing = array_values(array_diff($base['acciones'], $current));
        if (!isset($permissions['incidentes'])) {
            $permissions['incidentes'] = $base;
        } elseif ($missing) {
            $permissions['incidentes']['acciones'] = array_values(array_merge($current, $missing));
        } else {
            continue;
        }
        $update->execute([json_encode($permissions, JSON_UNESCAPED_UNICODE), (int) $row['id']]);
    }

    $hys = ['type' => 'role', 'value' => 'responsable_hys'];
    $admin = ['type' => 'role', 'value' => 'admin_empresa'];
    $rules = [
        ['Accidente grave (con baja / in itinere)', 'incident.serious', [['type' => 'sector_supervisors'], $hys, $admin], ['app', 'email', 'push', 'whatsapp']],
        ['Incidente o accidente reportado', 'incident.reported', [['type' => 'sector_supervisors'], $hys], ['app', 'email', 'push']],
        ['Incidente cerrado', 'incident.closed', [['type' => 'reporter']], ['app']],
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
