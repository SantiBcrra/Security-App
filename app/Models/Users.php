<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Usuarios de la empresa activa (base de la empresa: DB::tenant()). */
final class Users
{
    private const SELECT = 'SELECT u.*, r.uuid AS role_uuid, r.slug AS role_slug, r.name AS role_name, r.permissions AS role_permissions
        FROM users u JOIN roles r ON r.id = u.role_id';

    public static function all(): array
    {
        return DB::tenant()->query(self::SELECT . ' ORDER BY u.is_active DESC, u.name')->fetchAll();
    }

    public static function findByUuid(string $uuid): ?array
    {
        return self::one(' WHERE u.uuid = ?', [$uuid]);
    }

    public static function findById(int $id): ?array
    {
        return self::one(' WHERE u.id = ?', [$id]);
    }

    /** Login con email o DNI. */
    public static function findByLogin(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if (str_contains($identifier, '@')) {
            return self::one(' WHERE u.email = ?', [mb_strtolower($identifier)]);
        }
        $dni = self::normalizeDni($identifier);
        return $dni === '' ? null : self::one(' WHERE u.dni = ?', [$dni]);
    }

    public static function findByActivationHash(string $hash): ?array
    {
        return self::one(' WHERE u.activation_token_hash = ?', [$hash]);
    }

    public static function emailTaken(string $email, ?int $exceptId = null): bool
    {
        return self::taken('email', mb_strtolower(trim($email)), $exceptId);
    }

    public static function dniTaken(string $dni, ?int $exceptId = null): bool
    {
        return self::taken('dni', self::normalizeDni($dni), $exceptId);
    }

    /** @return int id */
    public static function create(array $data): int
    {
        $data += ['uuid' => Uuid::v4()];
        $cols = array_keys($data);
        DB::tenant()->prepare('INSERT INTO users (`' . implode('`, `', $cols) . '`, created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute(array_values($data));
        return (int) DB::tenant()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        if (!$data) {
            return;
        }
        $sets = implode(', ', array_map(fn ($c) => "`{$c}` = ?", array_keys($data)));
        DB::tenant()->prepare("UPDATE users SET {$sets}, updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([...array_values($data), $id]);
    }

    public static function touchLogin(int $id): void
    {
        DB::tenant()->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$id]);
    }

    /** Administradores activos (rol admin_empresa), opcionalmente excluyendo a uno. */
    public static function countActiveAdmins(?int $exceptId = null): int
    {
        $stmt = DB::tenant()->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
            WHERE r.slug = 'admin_empresa' AND u.is_active = 1 AND u.id <> ?");
        $stmt->execute([$exceptId ?? 0]);
        return (int) $stmt->fetchColumn();
    }

    public static function countByRole(int $roleId): int
    {
        $stmt = DB::tenant()->prepare('SELECT COUNT(*) FROM users WHERE role_id = ?');
        $stmt->execute([$roleId]);
        return (int) $stmt->fetchColumn();
    }

    public static function normalizeDni(string $dni): string
    {
        return preg_replace('/\D/', '', $dni);
    }

    /** ¿Puede entrar ahora? (activo, con contraseña y sin acceso vencido) */
    public static function canSignIn(array $user): bool
    {
        return (int) $user['is_active'] === 1
            && !empty($user['password_hash'])
            && ($user['access_expires_at'] === null || strtotime($user['access_expires_at'] . ' UTC') > time());
    }

    private static function one(string $where, array $params): ?array
    {
        $stmt = DB::tenant()->prepare(self::SELECT . $where . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    private static function taken(string $column, string $value, ?int $exceptId): bool
    {
        if ($value === '') {
            return false;
        }
        $stmt = DB::tenant()->prepare("SELECT 1 FROM users WHERE `{$column}` = ? AND id <> ? LIMIT 1");
        $stmt->execute([$value, $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }
}
