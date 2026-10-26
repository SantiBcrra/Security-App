<?php
declare(strict_types=1);

/**
 * El rol del sistema "Responsable de Seguridad e Higiene" deja de ver Usuarios (decisión del usuario 27/10/2026): los usuarios
 * los administra el administrador de la empresa. Solo toca el rol base; los roles propios quedan como estén. Idempotente.
 */
return function (PDO $db): void {
    $row = $db->query("SELECT id, permissions FROM roles WHERE slug = 'responsable_hys' AND is_system = 1")->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;
    $permissions = json_decode((string) $row['permissions'], true) ?: [];
    if (!isset($permissions['usuarios'])) return;
    unset($permissions['usuarios']);
    $db->prepare('UPDATE roles SET permissions = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
        ->execute([json_encode($permissions, JSON_UNESCAPED_UNICODE), (int) $row['id']]);
};
