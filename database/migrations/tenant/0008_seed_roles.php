<?php
// Roles base de la empresa (definidos en app/Policies/Permissions.php). Idempotente:
// solo inserta los que falten; nunca pisa roles existentes.
use App\Core\Uuid;
use App\Policies\Permissions;

return function (PDO $db): void {
    $exists = $db->prepare('SELECT 1 FROM roles WHERE slug = ?');
    $insert = $db->prepare('INSERT INTO roles (uuid, slug, name, description, permissions, is_system, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    foreach (Permissions::baseRoles() as $slug => $role) {
        $exists->execute([$slug]);
        if (!$exists->fetchColumn()) {
            $insert->execute([Uuid::v4(), $slug, $role['name'], $role['description'], json_encode($role['permissions'])]);
        }
    }
};
