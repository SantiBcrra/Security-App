<?php
declare(strict_types=1);

namespace App\Services\Notify;

use App\Core\DB;
use App\Models\Sectors;
use App\Models\Users;

/**
 * Resuelve los destinatarios de una regla para una observación.
 * Tipos: role (slug), user (uuid), assignee, reporter, sector_supervisors (usuarios con un
 * sector asignado igual o por encima del sector de la observación).
 */
final class Recipients
{
    /** @return array<int, array> user_id => usuario (solo activos y habilitados para ingresar) */
    public static function resolve(array $recipients, array $obs): array
    {
        $users = [];
        foreach ($recipients as $r) {
            foreach (self::idsFor($r, $obs) as $id) {
                $users[$id] = true;
            }
        }
        $out = [];
        foreach (array_keys($users) as $id) {
            $user = Users::findById((int) $id);
            if ($user !== null && Users::canSignIn($user)) {
                $out[(int) $id] = $user;
            }
        }
        return $out;
    }

    /** @return list<int> */
    private static function idsFor(array $r, array $obs): array
    {
        $db = DB::tenant();
        switch ($r['type'] ?? '') {
            case 'role':
                $stmt = $db->prepare('SELECT u.id FROM users u JOIN roles ro ON ro.id = u.role_id WHERE ro.slug = ? AND u.is_active = 1');
                $stmt->execute([(string) ($r['value'] ?? '')]);
                return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
            case 'user':
                $u = Users::findByUuid((string) ($r['value'] ?? ''));
                return $u ? [(int) $u['id']] : [];
            case 'assignee':
                return !empty($obs['assigned_user_id']) ? [(int) $obs['assigned_user_id']] : [];
            case 'reporter':
                return !empty($obs['reporter_user_id']) ? [(int) $obs['reporter_user_id']] : [];
            case 'sector_supervisors':
                $sector = !empty($obs['sector_id']) ? Sectors::findById((int) $obs['sector_id']) : null;
                if ($sector === null) {
                    return [];
                }
                // El sector de un usuario "cubre" la observación si es el mismo o un antecesor (path prefijo).
                $stmt = $db->prepare("SELECT DISTINCT us.user_id FROM user_sectors us JOIN sectors s ON s.id = us.sector_id
                    WHERE ? LIKE CONCAT(s.path, '%')");
                $stmt->execute([$sector['path']]);
                return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        }
        return [];
    }

    /** Todos los responsables SyH y administradores (escalamiento, resúmenes). */
    public static function managers(): array
    {
        return self::resolve([['type' => 'role', 'value' => 'responsable_hys'], ['type' => 'role', 'value' => 'admin_empresa']], []);
    }
}
