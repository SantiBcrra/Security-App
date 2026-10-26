<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\UserError;
use App\Models\Employees;
use App\Models\Roles;
use App\Models\Users;

/**
 * Guardias del celular compartido cargados como EMPLEADOS (sin usuario ni contraseña), como en los sistemas de seguridad de
 * un solo celular: el celular tiene la cuenta del puesto y cada guardia se identifica con su PIN. Por detrás cada empleado
 * tiene un perfil interno sin acceso (`users.no_login = 1`, `users.employee_id`) para que turnos, relevos, rondas, libro y
 * reportes queden a su nombre con la misma lógica de siempre. Ese perfil nunca puede ingresar (`Users::canSignIn`).
 */
final class PostGuards
{
    /** Perfil interno del empleado (lo crea la primera vez). */
    public static function userFor(array $employee): array
    {
        $name = trim($employee['first_name'] . ' ' . $employee['last_name']);
        $active = (int) $employee['is_active'] === 1 && $employee['deleted_at'] === null ? 1 : 0;
        if ($u = self::linkedUser((int) $employee['id'])) {
            if ($u['name'] !== $name || (int) $u['is_active'] !== $active || $u['deleted_at'] !== null) {
                Users::update((int) $u['id'], ['name' => $name, 'is_active' => $active, 'deleted_at' => null]);
                $u = Users::findById((int) $u['id']);
            }
            return $u;
        }
        $role = Roles::findBySlug('reportante');
        if ($role === null) throw new UserError('Falta el rol base de guardia (reportante).');
        $id = Users::create(['name' => $name, 'role_id' => (int) $role['id'], 'employee_id' => (int) $employee['id'], 'no_login' => 1, 'is_active' => $active]);
        Audit::tenant('guard_profile.create', 'employee', $employee['uuid'], null, ['empleado' => $name]);
        return Users::findById($id);
    }

    public static function linkedUser(int $employeeId): ?array
    {
        $q = DB::tenant()->prepare('SELECT id FROM users WHERE employee_id = ? LIMIT 1');
        $q->execute([$employeeId]);
        $id = $q->fetchColumn();
        return $id ? Users::findById((int) $id) : null;
    }

    /** El empleado cambió (nombre, activo, eliminado): el perfil interno lo sigue. */
    public static function syncEmployee(int $employeeId): void
    {
        $e = Employees::findById($employeeId);
        if ($e !== null && self::linkedUser($employeeId) !== null) self::userFor($e);
    }

    /** PIN del empleado para el celular compartido (null = quitar). Auditado sin el PIN. */
    public static function setPin(array $employee, ?string $pin): void
    {
        if ((int) $employee['is_active'] !== 1 || $employee['deleted_at'] !== null) throw new UserError('El empleado está desactivado.');
        $u = self::userFor($employee);
        ActingGuard::setPin((int) $u['id'], $pin);
        Audit::tenant($pin === null ? 'guard_pin.remove' : 'guard_pin.set', 'employee', $employee['uuid'], null, ['empleado' => $u['name']]);
    }

    /** Empleados activos para elegir como guardias, con su PIN y los puestos en los que están. */
    public static function list(string $q = '', int $limit = 300): array
    {
        $where = 'e.deleted_at IS NULL AND e.is_active = 1';
        $params = [];
        if (($q = trim($q)) !== '') {
            $where .= ' AND (e.last_name LIKE ? OR e.first_name LIKE ? OR e.dni LIKE ? OR c.name LIKE ?)';
            array_push($params, "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%");
        }
        $st = DB::tenant()->prepare("SELECT e.id, e.uuid, e.first_name, e.last_name, e.dni, c.name AS contractor_name, u.id AS guard_user_id, u.uuid AS guard_user_uuid,
                u.pin_set_at, (u.pin_hash IS NOT NULL) AS has_pin
            FROM employees e LEFT JOIN contractors c ON c.id = e.contractor_id
            LEFT JOIN users u ON u.employee_id = e.id AND u.deleted_at IS NULL
            WHERE {$where} ORDER BY (u.id IS NULL), (c.id IS NULL), e.last_name, e.first_name LIMIT " . max(1, $limit));
        $st->execute($params);
        $rows = $st->fetchAll();
        $posts = self::postsByUser();
        foreach ($rows as &$r) $r['posts'] = $r['guard_user_id'] ? ($posts[(int) $r['guard_user_id']] ?? []) : [];
        return $rows;
    }

    /** Nombres de los puestos (programaciones por puesto activas) de cada guardia. @return array<int, list<string>> */
    private static function postsByUser(): array
    {
        $out = [];
        foreach (DB::tenant()->query("SELECT g.name, g.user_ids, s.name AS site_name FROM guard_shifts g JOIN sites s ON s.id = g.site_id
            WHERE g.mode = 'puesto' AND g.is_active = 1 AND g.deleted_at IS NULL")->fetchAll() as $g) {
            foreach (json_decode((string) $g['user_ids'], true) ?: [] as $id) $out[(int) $id][] = $g['name'] ?: $g['site_name'];
        }
        return $out;
    }

    /** uuid del empleado de cada perfil interno (para marcar el formulario de la programación). @return array<int, string> */
    public static function employeeUuids(array $userIds): array
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if (!$userIds) return [];
        $rows = DB::tenant()->query('SELECT u.id, e.uuid FROM users u JOIN employees e ON e.id = u.employee_id WHERE u.id IN (' . implode(',', $userIds) . ')')->fetchAll();
        return array_column($rows, 'uuid', 'id');
    }
}
