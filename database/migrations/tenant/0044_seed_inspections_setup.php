<?php
declare(strict_types=1);

use App\Core\Uuid;
use App\Policies\Permissions;

/**
 * Etapa 11: el operario (rol reportante) hace sus checklists de pre-uso → inspecciones ver + crear
 * (propios) en los roles base ya creados. Y la regla de aviso de falla crítica.
 * Solo suma: no quita nada que la empresa haya configurado. Idempotente.
 */
return function (PDO $db): void {
    $roles = Permissions::baseRoles();
    $update = $db->prepare('UPDATE roles SET permissions = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?');
    foreach ($db->query('SELECT id, slug, permissions FROM roles WHERE is_system = 1')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $base = $roles[$row['slug']]['permissions']['inspecciones'] ?? null;
        if ($base === null) {
            continue;
        }
        $permissions = json_decode((string) $row['permissions'], true) ?: [];
        $current = (array) ($permissions['inspecciones']['acciones'] ?? []);
        $missing = array_values(array_diff($base['acciones'], $current));
        if (!isset($permissions['inspecciones'])) {
            $permissions['inspecciones'] = $base;
        } elseif ($missing) {
            $permissions['inspecciones']['acciones'] = array_values(array_merge($current, $missing));
        } else {
            continue;
        }
        $update->execute([json_encode($permissions, JSON_UNESCAPED_UNICODE), (int) $row['id']]);
    }

    $exists = (int) $db->query("SELECT COUNT(*) FROM notification_rules WHERE event = 'inspection.critical_fail'")->fetchColumn();
    if ($exists === 0) {
        $db->prepare('INSERT INTO notification_rules (uuid, name, event, min_severity_level, sector_id, recipients, channels, is_active, created_at, updated_at)
            VALUES (?, ?, ?, NULL, NULL, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute([
            Uuid::v4(), 'Inspección con falla crítica', 'inspection.critical_fail',
            json_encode([['type' => 'sector_supervisors'], ['type' => 'role', 'value' => 'responsable_hys']]), json_encode(['app', 'email', 'push']),
        ]);
    }
};
