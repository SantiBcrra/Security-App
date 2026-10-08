<?php
declare(strict_types=1);

use App\Core\Uuid;
use App\Policies\Permissions;

/**
 * Etapa 13: las plantillas de checklist pueden ser de un tipo de permiso (scope = permiso + permit_type); la acción
 * "aprobar" para los roles base que la tienen (SyH, admin) y las reglas de aviso. Solo suma. Idempotente.
 */
return function (PDO $db): void {
    $cols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspection_templates'")
        ->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('permit_type', $cols, true)) {
        $db->exec('ALTER TABLE inspection_templates ADD COLUMN permit_type VARCHAR(20) NULL AFTER equipment_type_id, ADD KEY idx_inspection_templates_permit (permit_type, is_active)');
    }

    $roles = Permissions::baseRoles();
    $update = $db->prepare('UPDATE roles SET permissions = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?');
    foreach ($db->query('SELECT id, slug, permissions FROM roles WHERE is_system = 1')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $base = $roles[$row['slug']]['permissions']['permisos_trabajo'] ?? null;
        if ($base === null) {
            continue;
        }
        $permissions = json_decode((string) $row['permissions'], true) ?: [];
        $current = (array) ($permissions['permisos_trabajo']['acciones'] ?? []);
        $missing = array_values(array_diff($base['acciones'], $current));
        if (!isset($permissions['permisos_trabajo'])) {
            $permissions['permisos_trabajo'] = $base;
        } elseif ($missing) {
            $permissions['permisos_trabajo']['acciones'] = array_values(array_merge($current, $missing));
        } else {
            continue;
        }
        $update->execute([json_encode($permissions, JSON_UNESCAPED_UNICODE), (int) $row['id']]);
    }

    $rules = [
        ['Permiso de trabajo para autorizar', 'permit.requested', [['type' => 'approvers']], ['app', 'email', 'push']],
        ['Permiso de trabajo autorizado o rechazado', 'permit.decided', [['type' => 'reporter']], ['app', 'push']],
        ['Permiso de trabajo por vencer', 'permit.expiring', [['type' => 'reporter'], ['type' => 'assignee']], ['app', 'push']],
        ['Permiso de trabajo vencido', 'permit.expired', [['type' => 'reporter'], ['type' => 'assignee'], ['type' => 'role', 'value' => 'responsable_hys']], ['app', 'email']],
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
