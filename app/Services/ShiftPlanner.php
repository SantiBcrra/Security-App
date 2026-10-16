<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Tenant;
use App\Core\Uuid;
use App\Models\Settings;
use App\Models\Users;

/**
 * Programación de turnos de guardias (docs/design/turnos-guardias.md):
 * - semanal: días de la semana + horario (puede cruzar la medianoche; el día que cuenta es el del comienzo);
 * - rotativo: N días trabaja / M descansa desde una fecha de referencia (12×36 = 1/1 con turno de 12 h, 24×48 = 1/2, 2×2, 4×4…).
 * El cron genera los turnos (guard_shift_slots) de ayer a +6 días, uno por guardia, sin días sin servicio; si el guardia
 * tiene una ausencia aprobada el turno nace "justificado".
 */
final class ShiftPlanner
{
    public const DAYS = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
    public const AHEAD_DAYS = 6;

    public static function tz(): \DateTimeZone
    {
        return new \DateTimeZone(Tenant::timezone() ?: 'America/Argentina/Buenos_Aires');
    }

    public static function defaultGrace(): int
    {
        return max(1, min(120, (int) Settings::get('turnos.gracia_minutos', '15')));
    }

    /** @return array{0: array, 1: array<string,string>} */
    public static function validate(array $in): array
    {
        $errors = [];
        $site = \App\Models\Sites::findByUuid((string) ($in['site'] ?? ''));
        if (!$site || (int) $site['is_active'] !== 1) $errors['site'] = 'Elegí la planta / predio.';
        $hhmm = fn ($v) => preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim((string) $v), $m) ? sprintf('%02d:%02d', $m[1], $m[2]) : null;
        $start = $hhmm($in['start_time'] ?? '');
        $end = $hhmm($in['end_time'] ?? '');
        if ($start === null || $end === null) $errors['time'] = 'Indicá el horario (HH:MM a HH:MM).';
        elseif ($start === $end) $errors['time'] = 'Para un turno de 24 h poné el mismo horario en "rotativo" con fin un minuto antes (ej. 08:00 a 07:59).';
        $pattern = ($in['pattern'] ?? 'weekly') === 'rotation' ? 'rotation' : 'weekly';
        $days = null;
        $on = $off = null;
        $anchor = null;
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) && strtotime((string) $v) !== false ? (string) $v : null;
        if ($pattern === 'weekly') {
            $list = array_values(array_unique(array_filter(array_map('intval', (array) ($in['days'] ?? [])), fn ($d) => $d >= 1 && $d <= 7)));
            sort($list);
            if (!$list) $errors['days'] = 'Elegí al menos un día.';
            $days = implode(',', $list);
        } else {
            $on = (int) ($in['rotation_on'] ?? 0);
            $off = (int) ($in['rotation_off'] ?? 0);
            $anchor = $date($in['anchor_date'] ?? '');
            if ($on < 1 || $on > 14 || $off < 1 || $off > 14) $errors['rotation'] = 'Días de trabajo y de descanso: de 1 a 14.';
            if ($anchor === null) $errors['anchor_date'] = 'Indicá el primer día de trabajo del ciclo.';
        }
        $users = [];
        foreach ((array) ($in['users'] ?? []) as $uuid) {
            $u = Users::findByUuid((string) $uuid);
            if ($u !== null && (int) $u['is_active'] === 1) $users[] = (int) $u['id'];
        }
        $users = array_values(array_unique($users));
        if (!$users) $errors['users'] = 'Elegí al menos un guardia.';
        $from = $date($in['valid_from'] ?? '');
        $until = $date($in['valid_until'] ?? '');
        if ($from && $until && $until < $from) $errors['valid_until'] = 'La vigencia termina antes de empezar.';
        $grace = ($in['grace_minutes'] ?? '') === '' ? null : max(1, min(120, (int) $in['grace_minutes']));
        $data = ['name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 120) ?: null, 'site_id' => $site ? (int) $site['id'] : null,
            'user_ids' => json_encode($users), 'pattern' => $pattern, 'days' => $days, 'rotation_on' => $on, 'rotation_off' => $off,
            'anchor_date' => $anchor, 'start_time' => $start, 'end_time' => $end, 'valid_from' => $from, 'valid_until' => $until, 'grace_minutes' => $grace];
        return [$data, $errors];
    }

    public static function save(?array $shift, array $data): string
    {
        $db = DB::tenant();
        $cols = array_keys($data);
        if ($shift === null) {
            $uuid = Uuid::v4();
            $db->prepare('INSERT INTO guard_shifts (uuid, ' . implode(', ', $cols) . ', is_active, created_by, created_at, updated_at) VALUES (?, '
                . implode(', ', array_fill(0, count($cols), '?')) . ', 1, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
                ->execute(array_merge([$uuid], array_values($data), [UserAuth::user()['id'] ?? null]));
            Audit::tenant('guard_shift.create', 'guard_shift', $uuid, null, $data);
        } else {
            $uuid = $shift['uuid'];
            $db->prepare('UPDATE guard_shifts SET ' . implode(', ', array_map(fn ($c) => "{$c} = ?", $cols)) . ', updated_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute(array_merge(array_values($data), [(int) $shift['id']]));
            self::dropFuture((int) $shift['id']);
            Audit::tenant('guard_shift.update', 'guard_shift', $uuid, array_intersect_key($shift, $data), $data);
        }
        self::generate();
        return $uuid;
    }

    public static function setActive(array $shift, bool $active): void
    {
        DB::tenant()->prepare('UPDATE guard_shifts SET is_active = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$active ? 1 : 0, (int) $shift['id']]);
        self::dropFuture((int) $shift['id']);
        Audit::tenant($active ? 'guard_shift.activate' : 'guard_shift.deactivate', 'guard_shift', $shift['uuid']);
        if ($active) self::generate();
    }

    /** Los turnos futuros que todavía no empezaron se anulan (baja al celular) y se vuelven a generar con lo nuevo. */
    private static function dropFuture(int $shiftId): void
    {
        DB::tenant()->prepare("UPDATE guard_shift_slots SET status = 'anulado', updated_at = UTC_TIMESTAMP()
            WHERE shift_id = ? AND status IN ('programado', 'justificado') AND start_at > UTC_TIMESTAMP()")->execute([$shiftId]);
    }

    public static function find(string $uuid): ?array
    {
        $s = DB::tenant()->prepare('SELECT g.*, s.uuid AS site_uuid, s.name AS site_name FROM guard_shifts g JOIN sites s ON s.id = g.site_id WHERE g.uuid = ? AND g.deleted_at IS NULL');
        $s->execute([$uuid]);
        return $s->fetch() ?: null;
    }

    public static function all(): array
    {
        return DB::tenant()->query('SELECT g.*, s.uuid AS site_uuid, s.name AS site_name FROM guard_shifts g JOIN sites s ON s.id = g.site_id
            WHERE g.deleted_at IS NULL ORDER BY g.is_active DESC, s.name, g.start_time')->fetchAll();
    }

    /** "22:00 a 06:00 · Lun a Vie" o "08:00 a 20:00 · rotativo 1×1 (desde 01/11)". */
    public static function describe(array $s): string
    {
        $when = "{$s['start_time']} a {$s['end_time']}";
        if ($s['pattern'] === 'rotation') {
            return "{$when} · rotativo {$s['rotation_on']} día(s) sí / {$s['rotation_off']} no (ciclo desde " . date('d/m/Y', strtotime((string) $s['anchor_date'])) . ')';
        }
        $days = array_map('intval', explode(',', (string) $s['days']));
        $txt = count($days) === 7 ? 'todos los días' : ($days === [1, 2, 3, 4, 5] ? 'Lun a Vie' : ($days === [6, 7] ? 'fines de semana'
            : implode(', ', array_map(fn ($d) => self::DAYS[$d], $days))));
        return "{$when} · {$txt}";
    }

    /** Nombres de los guardias de una programación. */
    public static function guardNames(array $shift): string
    {
        $ids = self::userIds($shift);
        if (!$ids) return '—';
        return implode(', ', DB::tenant()->query('SELECT name FROM users WHERE id IN (' . implode(',', $ids) . ') ORDER BY name')->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<int> */
    public static function userIds(array $shift): array
    {
        return array_values(array_map('intval', json_decode((string) $shift['user_ids'], true) ?: []));
    }

    /** ¿Se trabaja ese día local según el patrón? */
    public static function worksOn(array $s, string $day): bool
    {
        if (($s['valid_from'] && $day < $s['valid_from']) || ($s['valid_until'] && $day > $s['valid_until'])) return false;
        if ($s['pattern'] === 'rotation') {
            if (!$s['anchor_date']) return false;
            $diff = (int) round((strtotime($day . ' 12:00 UTC') - strtotime($s['anchor_date'] . ' 12:00 UTC')) / 86400);
            $cycle = (int) $s['rotation_on'] + (int) $s['rotation_off'];
            return $cycle > 0 && ((($diff % $cycle) + $cycle) % $cycle) < (int) $s['rotation_on'];
        }
        return in_array((int) (new \DateTimeImmutable($day))->format('N'), array_map('intval', explode(',', (string) $s['days'])), true);
    }

    /**
     * Turnos (inicio y fin UTC) de una programación entre dos días locales inclusive.
     * @return list<array{day:string, start:string, end:string}>
     */
    public static function occurrences(array $s, string $fromDay, string $toDay): array
    {
        $tz = self::tz();
        $utc = new \DateTimeZone('UTC');
        $out = [];
        for ($d = new \DateTimeImmutable($fromDay, $tz); $d->format('Y-m-d') <= $toDay; $d = $d->modify('+1 day')) {
            $day = $d->format('Y-m-d');
            if (!self::worksOn($s, $day)) continue;
            $start = new \DateTimeImmutable("{$day} {$s['start_time']}", $tz);
            $end = new \DateTimeImmutable("{$day} {$s['end_time']}", $tz);
            if ($end <= $start) $end = $end->modify('+1 day');
            $out[] = ['day' => $day, 'start' => $start->setTimezone($utc)->format('Y-m-d H:i:s'), 'end' => $end->setTimezone($utc)->format('Y-m-d H:i:s')];
        }
        return $out;
    }

    /** Días sin servicio de un predio (o de todos) entre dos fechas. @return array<string,string> día => motivo */
    public static function closures(int $siteId, string $from, string $to): array
    {
        $q = DB::tenant()->prepare('SELECT day, reason FROM guard_closures WHERE day BETWEEN ? AND ? AND (site_id IS NULL OR site_id = ?)');
        $q->execute([$from, $to, $siteId]);
        return array_column($q->fetchAll(), 'reason', 'day');
    }

    /** Genera los turnos de ayer a +6 días (hora local). Idempotente. @return int creados */
    public static function generate(?int $now = null): int
    {
        $now ??= time();
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone(self::tz());
        $from = $local->modify('-1 day')->format('Y-m-d');
        $to = $local->modify('+' . self::AHEAD_DAYS . ' days')->format('Y-m-d');
        $db = DB::tenant();
        // Un turno anulado (se editó la programación) que vuelve a quedar igual se reactiva.
        $insert = $db->prepare("INSERT INTO guard_shift_slots (uuid, shift_id, user_id, site_id, start_at, end_at, grace_minutes, status, absence_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE end_at = IF(status = 'anulado', VALUES(end_at), end_at), grace_minutes = IF(status = 'anulado', VALUES(grace_minutes), grace_minutes),
                absence_id = IF(status = 'anulado', VALUES(absence_id), absence_id), updated_at = IF(status = 'anulado', UTC_TIMESTAMP(), updated_at),
                status = IF(status = 'anulado', VALUES(status), status)");
        $created = 0;
        $touched = [];
        $shifts = $db->query('SELECT * FROM guard_shifts WHERE is_active = 1 AND deleted_at IS NULL')->fetchAll();
        foreach ($shifts as $s) {
            $grace = (int) ($s['grace_minutes'] ?? self::defaultGrace());
            $since = strtotime($s['updated_at'] . ' UTC');
            $closed = self::closures((int) $s['site_id'], $from, $to);
            foreach (self::occurrences($s, $from, $to) as $occ) {
                if (isset($closed[$occ['day']]) || strtotime($occ['end'] . ' UTC') <= $since) continue; // sin servicio, o ya había pasado al crear/editar
                foreach (self::userIds($s) as $userId) {
                    $absence = ShiftAbsences::approvedFor($userId, $occ['day']);
                    if ($absence) $touched[(int) $absence['id']] = $absence;
                    $insert->execute([Uuid::v4(), (int) $s['id'], $userId, (int) $s['site_id'], $occ['start'], $occ['end'], $grace,
                        $absence ? 'justificado' : 'programado', $absence ? (int) $absence['id'] : null]);
                    $created += $insert->rowCount() === 1 ? 1 : 0;
                }
            }
        }
        foreach ($touched as $absence) ShiftAbsences::apply($absence); // turnos nuevos dentro de una ausencia: también su reemplazo
        return $created;
    }

    /** Día local de un turno (el del comienzo). */
    public static function localDay(string $utc): string
    {
        return (new \DateTimeImmutable($utc . ' UTC'))->setTimezone(self::tz())->format('Y-m-d');
    }

    /** [desde, hasta) en UTC de un día local. */
    public static function dayRange(string $localDay): array
    {
        $tz = self::tz();
        $utc = new \DateTimeZone('UTC');
        $a = new \DateTimeImmutable("{$localDay} 00:00:00", $tz);
        return [$a->setTimezone($utc)->format('Y-m-d H:i:s'), $a->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
    }
}
