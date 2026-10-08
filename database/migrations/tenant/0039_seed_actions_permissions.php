<?php
declare(strict_types=1);

use App\Policies\Permissions;

/**
 * Etapa 10: agrega a los roles base ya creados lo nuevo del módulo "acciones" (la acción
 * "verificar" y el acceso del reportante). Solo suma: no quita nada que la empresa haya configurado.
 */
return function (PDO $db): void {
    $roles = Permissions::baseRoles();
    $rows = $db->query('SELECT id, slug, permissions FROM roles WHERE is_system = 1')->fetchAll(PDO::FETCH_ASSOC);
    $update = $db->prepare('UPDATE roles SET permissions = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?');
    foreach ($rows as $row) {
        $base = $roles[$row['slug']]['permissions']['acciones'] ?? null;
        if ($base === null) {
            continue;
        }
        $permissions = json_decode((string) $row['permissions'], true);
        if (!is_array($permissions)) {
            $permissions = [];
        }
        $current = $permissions['acciones'] ?? null;
        if ($current === null) {
            $permissions['acciones'] = $base;
        } else {
            $missing = array_values(array_diff($base['acciones'], (array) ($current['acciones'] ?? [])));
            if (!$missing) {
                continue;
            }
            $permissions['acciones']['acciones'] = array_values(array_merge((array) ($current['acciones'] ?? []), $missing));
        }
        $update->execute([json_encode($permissions, JSON_UNESCAPED_UNICODE), (int) $row['id']]);
    }
};
