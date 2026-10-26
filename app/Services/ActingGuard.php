<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\UserError;
use App\Models\Users;

/**
 * Celular compartido del puesto: la empresa de seguridad tiene un solo celular para varios guardias. El celular queda abierto
 * con la cuenta del puesto (guard_shifts.device_user_id) y cada guardia se identifica con su PIN al empezar el turno. Lo que
 * hace en la app viaja con `as_user` (su uuid) y el servidor lo registra a su nombre: rondas, libro, pánico, observaciones.
 *
 * El PIN lo verifica el celular (también sin señal) contra el hash que le baja la sincronización; el servidor confía en la
 * cuenta del puesto y controla que el guardia sea del grupo de ese puesto y esté activo. Es para saber QUIÉN estuvo, no una
 * contraseña: la cuenta del puesto es la que protege el acceso.
 */
final class ActingGuard
{
    public const PIN_ITERATIONS = 20000;

    /** Cuenta del puesto mientras se procesa una operación a nombre de un guardia (null si no hay). */
    private static ?array $device = null;

    public static function device(): ?array
    {
        return self::$device;
    }

    /** Programaciones por puesto que usan este celular. @return list<array> */
    public static function posts(int $deviceUserId): array
    {
        // Sin las migraciones de turnos por puesto (0077/0078) no hay celulares de puesto: el ingreso y la sincronización
        // tienen que seguir funcionando igual hasta que se actualice la base.
        try {
            $q = DB::tenant()->prepare("SELECT g.*, s.uuid AS site_uuid, s.name AS site_name FROM guard_shifts g JOIN sites s ON s.id = g.site_id
                WHERE g.mode = 'puesto' AND g.device_user_id = ? AND g.is_active = 1 AND g.deleted_at IS NULL ORDER BY s.name, g.name");
            $q->execute([$deviceUserId]);
            return $q->fetchAll();
        } catch (\PDOException $e) {
            if (in_array((string) $e->getCode(), ['42S22', '42S02'], true)) return []; // columna o tabla que todavía no existe
            throw $e;
        }
    }

    public static function isDevice(int $userId): bool
    {
        return self::posts($userId) !== [];
    }

    /** Guardias del grupo de este celular (unión de los grupos de sus puestos), activos. @return list<int> */
    public static function poolIds(int $deviceUserId): array
    {
        $ids = [];
        foreach (self::posts($deviceUserId) as $p) {
            foreach (ShiftPlanner::userIds($p) as $id) $ids[$id] = true;
        }
        if (!$ids) return [];
        $q = DB::tenant()->query('SELECT id FROM users WHERE is_active = 1 AND deleted_at IS NULL AND id IN (' . implode(',', array_keys($ids)) . ')');
        return array_map('intval', $q->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Guardias del grupo con su PIN para que el celular los identifique sin señal. */
    public static function poolForDevice(int $deviceUserId): array
    {
        $ids = self::poolIds($deviceUserId);
        if (!$ids) return [];
        $rows = DB::tenant()->query('SELECT id, uuid, name, dni, pin_hash FROM users WHERE id IN (' . implode(',', $ids) . ') ORDER BY name')->fetchAll();
        return array_map(function ($u) {
            $pin = self::parse($u['pin_hash']);
            return ['uuid' => $u['uuid'], 'name' => $u['name'], 'dni_end' => $u['dni'] ? substr((string) $u['dni'], -3) : null,
                'consentimiento' => Consent::accepted((int) $u['id']),
                'pin' => $pin ? ['salt' => $pin['salt'], 'iterations' => $pin['iterations'], 'hash' => $pin['hash']] : null];
        }, $rows);
    }

    /**
     * Guardia a cuyo nombre se registra la operación. Solo desde la cuenta de un celular de puesto y para un guardia de su
     * grupo; sin `as_user` la operación queda a nombre de la cuenta del puesto.
     */
    public static function resolve(?string $asUserUuid): ?array
    {
        if ($asUserUuid === null || $asUserUuid === '') return null;
        $device = UserAuth::user();
        if ($device === null) throw new UserError('Sin sesión.');
        $guard = Users::findByUuid(strtolower($asUserUuid));
        if ($guard === null || (int) $guard['is_active'] !== 1 || $guard['deleted_at'] !== null) {
            throw new UserError('El guardia no existe o está desactivado. Pedí al supervisor que lo revise.');
        }
        if ((int) $guard['id'] === (int) $device['id']) return null;
        if (!in_array((int) $guard['id'], self::poolIds((int) $device['id']), true)) {
            throw new UserError("{$guard['name']} no está en el grupo de guardias de este celular.");
        }
        return $guard;
    }

    /** Ejecuta $fn a nombre del guardia (y vuelve a la cuenta del puesto al terminar). */
    public static function run(?array $guard, callable $fn): mixed
    {
        if ($guard === null) return $fn();
        $device = UserAuth::user();
        self::$device = $device;
        UserAuth::setCurrent($guard);
        try {
            return $fn();
        } finally {
            UserAuth::setCurrent($device);
            self::$device = null;
        }
    }

    /**
     * Ids que valen como "yo" para el usuario actual: él mismo y, si opera desde un celular de puesto, los guardias del grupo y
     * la cuenta del puesto (otro guardia puede seguir la ronda que empezó el anterior antes del relevo).
     * @return list<int>
     */
    public static function selfIds(): array
    {
        $me = (int) (UserAuth::user()['id'] ?? 0);
        $device = self::$device ?? (self::isDevice($me) ? UserAuth::user() : null);
        if ($device === null) return [$me];
        return array_values(array_unique(array_merge([$me, (int) $device['id']], self::poolIds((int) $device['id']))));
    }

    /** ¿El usuario actual puede actuar sobre algo que es de $userId? (propio, o del mismo celular de puesto) */
    public static function canActFor(int $userId): bool
    {
        return in_array($userId, self::selfIds(), true);
    }

    /**
     * Lo que necesita el celular del puesto (`sync/pull.meta.puesto`): puestos, guardias del grupo con su PIN, franjas a cubrir
     * (de 12 h atrás a 36 h adelante) y quién está de turno ahora. null si la cuenta no es de un celular de puesto.
     */
    public static function meta(int $deviceUserId): ?array
    {
        $posts = self::posts($deviceUserId);
        if (!$posts) return null;
        $iso = fn ($v) => $v ? str_replace(' ', 'T', $v) . 'Z' : null;
        $ids = implode(',', array_map(fn ($p) => (int) $p['id'], $posts));
        $db = DB::tenant();
        $franjas = $db->prepare("SELECT c.uuid, c.start_at, c.end_at, c.status, c.grace_minutes, g.uuid AS shift_uuid, s.uuid AS site_uuid FROM guard_post_coverage c
            JOIN guard_shifts g ON g.id = c.shift_id JOIN sites s ON s.id = c.site_id
            WHERE c.shift_id IN ({$ids}) AND c.status <> 'anulado' AND c.end_at > ? AND c.start_at < ? ORDER BY c.start_at");
        $franjas->execute([gmdate('Y-m-d H:i:s', time() - 12 * 3600), gmdate('Y-m-d H:i:s', time() + 36 * 3600)]);
        $duty = $db->query("SELECT t.uuid, t.started_at, u.uuid AS user_uuid, u.name AS user_name, g.uuid AS shift_uuid, s.uuid AS site_uuid,
                c.uuid AS coverage_uuid, (SELECT e.uuid FROM guard_shift_exits e WHERE e.slot_id = t.id AND e.returned_at IS NULL LIMIT 1) AS open_exit_uuid
            FROM guard_shift_slots t JOIN users u ON u.id = t.user_id JOIN guard_shifts g ON g.id = t.shift_id JOIN sites s ON s.id = t.site_id
            LEFT JOIN guard_post_coverage c ON c.id = t.coverage_id
            WHERE t.shift_id IN ({$ids}) AND t.status = 'en_curso' ORDER BY t.started_at")->fetchAll();
        return [
            'puestos' => array_map(fn ($p) => ['shift_uuid' => $p['uuid'], 'name' => $p['name'] ?: $p['site_name'], 'site_uuid' => $p['site_uuid'],
                'site_name' => $p['site_name'], 'describe' => ShiftPlanner::describe($p)], $posts),
            'guardias' => self::poolForDevice($deviceUserId),
            'franjas' => array_map(fn ($c) => ['uuid' => $c['uuid'], 'shift_uuid' => $c['shift_uuid'], 'site_uuid' => $c['site_uuid'],
                'start_at' => $iso($c['start_at']), 'end_at' => $iso($c['end_at']), 'status' => $c['status'], 'grace_minutes' => (int) $c['grace_minutes']], $franjas->fetchAll()),
            'de_turno' => array_map(fn ($t) => ['slot_uuid' => $t['uuid'], 'user_uuid' => $t['user_uuid'], 'user_name' => $t['user_name'],
                'shift_uuid' => $t['shift_uuid'], 'site_uuid' => $t['site_uuid'], 'coverage_uuid' => $t['coverage_uuid'],
                'started_at' => $iso($t['started_at']), 'open_exit_uuid' => $t['open_exit_uuid']], $duty),
            'minutos_relevo' => PostCoverage::reliefMinutes(),
            'consentimiento' => ['version' => Consent::VERSION, 'texto' => Consent::text()],
        ];
    }

    // ── PIN ───────────────────────────────────────────────────────

    /** PIN de 4 a 6 números (null lo borra). */
    public static function setPin(int $userId, ?string $pin): void
    {
        if ($pin === null || $pin === '') {
            DB::tenant()->prepare('UPDATE users SET pin_hash = NULL, pin_set_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$userId]);
            return;
        }
        if (!preg_match('/^\d{4,6}$/', $pin)) throw new UserError('El PIN tiene que tener de 4 a 6 números.');
        DB::tenant()->prepare('UPDATE users SET pin_hash = ?, pin_set_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([self::hash($pin), $userId]);
    }

    /** "pbkdf2$iteraciones$sal(hex)$hash(hex)" — el celular calcula lo mismo con PBKDF2WithHmacSHA256. */
    public static function hash(string $pin, ?string $saltHex = null, int $iterations = self::PIN_ITERATIONS): string
    {
        $saltHex ??= bin2hex(random_bytes(16));
        return 'pbkdf2$' . $iterations . '$' . $saltHex . '$' . hash_pbkdf2('sha256', $pin, (string) hex2bin($saltHex), $iterations, 64);
    }

    public static function verifyPin(?string $stored, string $pin): bool
    {
        $p = self::parse($stored);
        return $p !== null && hash_equals($p['hash'], hash_pbkdf2('sha256', $pin, (string) hex2bin($p['salt']), $p['iterations'], 64));
    }

    /** @return ?array{iterations:int, salt:string, hash:string} */
    private static function parse(?string $stored): ?array
    {
        if ($stored === null || !preg_match('/^pbkdf2\$(\d+)\$([0-9a-f]{32})\$([0-9a-f]{64})$/', $stored, $m)) return null;
        return ['iterations' => (int) $m[1], 'salt' => $m[2], 'hash' => $m[3]];
    }
}
