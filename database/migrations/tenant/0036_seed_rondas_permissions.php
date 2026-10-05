<?php
declare(strict_types=1);

use App\Policies\Permissions;

/** Agrega el módulo de rondas a los roles base creados antes de la Etapa 9. */
return function (PDO $db): void {
    $roles = Permissions::baseRoles();
    $rows = $db->query('SELECT id, slug, permissions FROM roles WHERE is_system = 1')->fetchAll(PDO::FETCH_ASSOC);
    $update = $db->prepare('UPDATE roles SET permissions = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?');
    foreach ($rows as $row) {
        $base = $roles[$row['slug']]['permissions']['rondas'] ?? null;
        if ($base === null) {
            continue;
        }
        $permissions = json_decode((string) $row['permissions'], true);
        if (!is_array($permissions)) {
            $permissions = [];
        }
        if (!isset($permissions['rondas'])) {
            $permissions['rondas'] = $base;
            $update->execute([json_encode($permissions, JSON_UNESCAPED_UNICODE), (int) $row['id']]);
        }
    }
};
