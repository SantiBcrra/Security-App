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
 * - rotativo: N días trabaja / M descansa desde una fecha de referencia (12×36 = 1/1 con turno de 12 h, 24×48 = 1/2, 2×2, 4×4…);
 * - por día (custom): franjas semanales con día y hora de entrada y de salida, cada una de hasta MAX_BLOCK_HOURS
 *   (ej. Lun a Vie 20:00 → 08:00 del día siguiente y Sáb 14:00 → Lun 08:00). Sin superposiciones.
 * El cron genera los turnos (guard_shift_slots) de ayer a +6 días, uno por guardia, sin días sin servicio; si el guardia
 * tiene una ausencia aprobada el turno nace "justificado".
 */
final class ShiftPlanner
{
    public const DAYS = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
    public const AHEAD_DAYS = 6;
    public const MAX_BLOCK_HOURS = 72;
    public const MAX_BLOCKS = 21;

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
        $pattern = in_array($in['pattern'] ?? '', ['rotation', 'custom'], true) ? (string) $in['pattern'] : 'weekly';
        $blocks = null;
        if ($pattern === 'custom') {
            [$blocks, $blockError] = self::parseBlocks($in['blocks'] ?? null);
            if ($blockError !== null) $errors['blocks'] = $blockError;
            $start = $blocks[0]['start'] ?? '00:00';
            $end = $blocks[0]['end'] ?? '00:00';
        } else {
            $start = $hhmm($in['start_time'] ?? '');
            $end = $hhmm($in['end_time'] ?? '');
            if ($start === null || $end === null) $errors['time'] = 'Indicá el horario (HH:MM a HH:MM).';
            elseif ($start === $end) $errors['time'] = 'Para un turno de 24 h poné el mismo horario en "rotativo" con fin un minuto antes (ej. 08:00 a 07:59), o usá "Horario por día".';
        }
        $days = null;
        $on = $off = null;
        $anchor = null;
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) && strtotime((string) $v) !== false ? (string) $v : null;
        if ($pattern === 'weekly') {
            $list = array_values(array_unique(array_filter(array_map('intval', (array) ($in['days'] ?? [])), fn ($d) => $d >= 1 && $d <= 7)));
            sort($list);
            if (!$list) $errors['days'] = 'Elegí al menos un día.';
            $days = implode(',', $list);
        } elseif ($pattern === 'custom') {
            $list = array_values(array_unique(array_column($blocks ?? [], 'day')));
            sort($list);
            $days = implode(',', $list) ?: null;
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
        $mode = ($in['mode'] ?? 'personal') === 'puesto' ? 'puesto' : 'personal';
        if (!$users) $errors['users'] = $mode === 'puesto' ? 'Elegí los guardias del grupo que cubre el puesto.' : 'Elegí al menos un guardia.';
        // Por puesto: el celular compartido es opcional (si cada guardia usa el suyo, no hace falta).
        $device = null;
        if ($mode === 'puesto' && !empty($in['device'])) {
            $device = Users::findByUuid((string) $in['device']);
            if ($device === null || (int) $device['is_active'] !== 1) $errors['device'] = 'El usuario del celular del puesto no existe o está desactivado.';
            elseif (in_array((int) $device['id'], $users, true)) $errors['device'] = 'El usuario del celular no puede ser también un guardia del grupo: es la cuenta del puesto.';
        }
        $from = $date($in['valid_from'] ?? '');
        $until = $date($in['valid_until'] ?? '');
        if ($from && $until && $until < $from) $errors['valid_until'] = 'La vigencia termina antes de empezar.';
        $grace = ($in['grace_minutes'] ?? '') === '' ? null : max(1, min(120, (int) $in['grace_minutes']));
        $data = ['name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 120) ?: null, 'mode' => $mode, 'site_id' => $site ? (int) $site['id'] : null,
            'user_ids' => json_encode($users), 'device_user_id' => $device ? (int) $device['id'] : null, 'pattern' => $pattern, 'days' => $days, 'rotation_on' => $on, 'rotation_off' => $off,
            'anchor_date' => $anchor, 'start_time' => $start, 'end_time' => $end,
            'blocks' => $blocks ? json_encode($blocks) : null, 'valid_from' => $from, 'valid_until' => $until, 'grace_minutes' => $grace];
        return [$data, $errors];
    }

    /**
     * Franjas del horario por día: [{day 1..7, start HH:MM, end_day 1..7, end HH:MM}] (JSON o arreglo). La salida es el primer
     * end_day/end posterior a la entrada (mismo día si la hora es mayor). Cada una dura hasta MAX_BLOCK_HOURS y no se pisan
     * entre sí (también de una semana a la otra).
     * @return array{0: ?list<array{day:int,start:string,end_day:int,end:string}>, 1: ?string}
     */
    public static function parseBlocks(mixed $raw): array
    {
        if (is_string($raw)) $raw = json_decode($raw, true);
        if (!is_array($raw) || !$raw) return [null, 'Agregá al menos una franja (día y hora de entrada y de salida).'];
        if (count($raw) > self::MAX_BLOCKS) return [null, 'Máximo ' . self::MAX_BLOCKS . ' franjas.'];
        $hhmm = fn ($v) => preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim((string) $v), $m) ? sprintf('%02d:%02d', $m[1], $m[2]) : null;
        $out = [];
        foreach (array_values($raw) as $i => $b) {
            $n = $i + 1;
            $day = (int) ($b['day'] ?? 0);
            $endDay = (int) ($b['end_day'] ?? $day);
            $start = $hhmm($b['start'] ?? '');
            $end = $hhmm($b['end'] ?? '');
            if ($day < 1 || $day > 7 || $endDay < 1 || $endDay > 7 || $start === null || $end === null) return [null, "Franja {$n}: completá día y hora de entrada y de salida."];
            $row = ['day' => $day, 'start' => $start, 'end_day' => $endDay, 'end' => $end];
            $minutes = self::blockMinutes($row);
            if ($minutes > self::MAX_BLOCK_HOURS * 60) {
                return [null, "Franja {$n} (" . self::blockLabel($row) . '): dura más de ' . self::MAX_BLOCK_HOURS . ' horas.'];
            }
            $out[] = $row;
        }
        usort($out, fn ($a, $b) => self::blockOffset($a) <=> self::blockOffset($b));
        // Superposición: cada franja tiene que terminar antes de que empiece la siguiente (la última, antes de la primera + 1 semana).
        $count = count($out);
        for ($i = 0; $i < $count; $i++) {
            $a = $out[$i];
            $b = $out[($i + 1) % $count];
            $nextStart = self::blockOffset($b) + ($i + 1 === $count ? 7 * 1440 : 0);
            if ($count > 1 && self::blockOffset($a) + self::blockMinutes($a) > $nextStart) {
                return [null, 'Las franjas ' . self::blockLabel($a) . ' y ' . self::blockLabel($b) . ' se superponen.'];
            }
        }
        return [$out, null];
    }

    /** Minuto de la semana (lunes 00:00 = 0) en que empieza una franja. */
    private static function blockOffset(array $b): int
    {
        [$h, $m] = array_map('intval', explode(':', $b['start']));
        return ((int) $b['day'] - 1) * 1440 + $h * 60 + $m;
    }

    /** Duración en minutos: de la entrada a la primera salida posterior (mismo día si la hora de salida es mayor; si no, hasta 7 días). */
    public static function blockMinutes(array $b): int
    {
        [$sh, $sm] = array_map('intval', explode(':', $b['start']));
        [$eh, $em] = array_map('intval', explode(':', $b['end']));
        $days = ((int) $b['end_day'] - (int) $b['day'] + 7) % 7;
        $minutes = $days * 1440 + ($eh * 60 + $em) - ($sh * 60 + $sm);
        return $minutes <= 0 ? $minutes + 7 * 1440 : $minutes;
    }

    /** "Sáb 14:00 a Lun 08:00" / "Lun 20:00 a 08:00" (sale al día siguiente) / "Lun 08:00 a 16:00". */
    public static function blockLabel(array $b): string
    {
        $endDays = intdiv(self::blockMinutes($b) + self::minuteOfDay($b['start']), 1440);
        $end = $endDays === 0 ? $b['end'] : ($endDays === 1 ? $b['end'] . ' del día siguiente' : self::DAYS[(int) $b['end_day']] . ' ' . $b['end']);
        return self::DAYS[(int) $b['day']] . " {$b['start']} a {$end}";
    }

    private static function minuteOfDay(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));
        return $h * 60 + $m;
    }

    /** @return list<array{day:int,start:string,end_day:int,end:string}> */
    public static function blocks(array $s): array
    {
        return array_values(json_decode((string) ($s['blocks'] ?? ''), true) ?: []);
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
            PostCoverage::dropFuture((int) $shift['id']);
            Audit::tenant('guard_shift.update', 'guard_shift', $uuid, array_intersect_key($shift, $data), $data);
        }
        self::generate();
        PostCoverage::generate();
        return $uuid;
    }

    public static function setActive(array $shift, bool $active): void
    {
        DB::tenant()->prepare('UPDATE guard_shifts SET is_active = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$active ? 1 : 0, (int) $shift['id']]);
        self::dropFuture((int) $shift['id']);
        PostCoverage::dropFuture((int) $shift['id']);
        Audit::tenant($active ? 'guard_shift.activate' : 'guard_shift.deactivate', 'guard_shift', $shift['uuid']);
        if ($active) { self::generate(); PostCoverage::generate(); }
    }

    /** Los turnos futuros que todavía no empezaron se anulan (baja al celular) y se vuelven a generar con lo nuevo. */
    private static function dropFuture(int $shiftId): void
    {
        // Los editados a mano por un supervisor se respetan.
        DB::tenant()->prepare("UPDATE guard_shift_slots SET status = 'anulado', updated_at = UTC_TIMESTAMP()
            WHERE shift_id = ? AND status IN ('programado', 'justificado') AND start_at > UTC_TIMESTAMP() AND edited_at IS NULL")->execute([$shiftId]);
    }

    /** Eliminar una programación: deja de generar turnos y se anulan los futuros que no empezaron (lo pasado queda). */
    public static function delete(array $shift): void
    {
        DB::tenant()->prepare('UPDATE guard_shifts SET is_active = 0, deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([(int) $shift['id']]);
        DB::tenant()->prepare("UPDATE guard_shift_slots SET status = 'anulado', updated_at = UTC_TIMESTAMP()
            WHERE shift_id = ? AND status IN ('programado', 'justificado') AND start_at > UTC_TIMESTAMP()")->execute([(int) $shift['id']]);
        PostCoverage::dropFuture((int) $shift['id']);
        Audit::tenant('guard_shift.delete', 'guard_shift', $shift['uuid'], ['nombre' => $shift['name'], 'horario' => self::describe($shift)], null);
    }

    public static function find(string $uuid): ?array
    {
        $s = DB::tenant()->prepare('SELECT g.*, s.uuid AS site_uuid, s.name AS site_name, d.uuid AS device_uuid, d.name AS device_name FROM guard_shifts g
            JOIN sites s ON s.id = g.site_id LEFT JOIN users d ON d.id = g.device_user_id WHERE g.uuid = ? AND g.deleted_at IS NULL');
        $s->execute([$uuid]);
        return $s->fetch() ?: null;
    }

    public static function all(): array
    {
        return DB::tenant()->query('SELECT g.*, s.uuid AS site_uuid, s.name AS site_name, d.uuid AS device_uuid, d.name AS device_name FROM guard_shifts g
            JOIN sites s ON s.id = g.site_id LEFT JOIN users d ON d.id = g.device_user_id
            WHERE g.deleted_at IS NULL ORDER BY g.is_active DESC, s.name, g.start_time')->fetchAll();
    }

    /** "22:00 a 06:00 · Lun a Vie" o "08:00 a 20:00 · rotativo 1×1 (desde 01/11)". */
    public static function describe(array $s): string
    {
        if ($s['pattern'] === 'custom') {
            // Agrupa los días con el mismo horario: "Lun a Vie 20:00 a 08:00 del día siguiente · Sáb 14:00 a Lun 08:00".
            $groups = [];
            foreach (self::blocks($s) as $b) {
                $span = intdiv(self::blockMinutes($b) + self::minuteOfDay($b['start']), 1440);
                $key = $span <= 1 ? "{$b['start']}|{$b['end']}|{$span}" : 'x' . count($groups);
                $groups[$key][] = $b;
            }
            $parts = [];
            foreach ($groups as $list) {
                $days = array_map(fn ($b) => (int) $b['day'], $list);
                if (count($list) === 1) { $parts[] = self::blockLabel($list[0]); continue; }
                $label = self::blockLabel($list[0]);
                $parts[] = self::daysText($days) . substr($label, strlen(self::DAYS[$days[0]]));
            }
            return implode(' · ', $parts) ?: 'sin franjas';
        }
        $when = "{$s['start_time']} a {$s['end_time']}";
        if ($s['pattern'] === 'rotation') {
            return "{$when} · rotativo {$s['rotation_on']} día(s) sí / {$s['rotation_off']} no (ciclo desde " . date('d/m/Y', strtotime((string) $s['anchor_date'])) . ')';
        }
        return "{$when} · " . self::daysText(array_map('intval', explode(',', (string) $s['days'])));
    }

    /** "todos los días", "Lun a Vie", "Lun a Jue", "fines de semana" o "Lun, Mié". */
    public static function daysText(array $days): string
    {
        sort($days);
        if (count($days) === 7) return 'todos los días';
        if ($days === [6, 7]) return 'fines de semana';
        if (count($days) >= 3 && $days === range($days[0], end($days))) return self::DAYS[$days[0]] . ' a ' . self::DAYS[end($days)];
        return implode(', ', array_map(fn ($d) => self::DAYS[$d], $days));
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
        $weekday = (int) (new \DateTimeImmutable($day))->format('N');
        if ($s['pattern'] === 'custom') return in_array($weekday, array_map(fn ($b) => (int) $b['day'], self::blocks($s)), true);
        return in_array($weekday, array_map('intval', explode(',', (string) $s['days'])), true);
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
            if ($s['pattern'] === 'custom') {
                $weekday = (int) $d->format('N');
                foreach (self::blocks($s) as $b) {
                    if ((int) $b['day'] !== $weekday) continue;
                    $start = new \DateTimeImmutable("{$day} {$b['start']}", $tz);
                    $end = $start->modify('+' . self::blockMinutes($b) . ' minutes');
                    $out[] = ['day' => $day, 'start' => $start->setTimezone($utc)->format('Y-m-d H:i:s'), 'end' => $end->setTimezone($utc)->format('Y-m-d H:i:s')];
                }
                continue;
            }
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
        // Cada turno recuerda su origen (programación + guardia + hora originales): si un supervisor lo editó (otra hora u otro
        // guardia) o lo dio de baja a mano, no se vuelve a crear ni se reactiva. Uno anulado por editar la programación que
        // vuelve a quedar igual, sí se reactiva.
        $find = $db->prepare('SELECT id, status, edited_at FROM guard_shift_slots WHERE shift_id = ? AND COALESCE(origin_user_id, user_id) = ?
            AND COALESCE(origin_start_at, start_at) = ? LIMIT 1');
        $insertRow = $db->prepare("INSERT INTO guard_shift_slots (uuid, shift_id, origin_user_id, origin_start_at, user_id, site_id, start_at, end_at, grace_minutes,
            status, absence_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $revive = $db->prepare("UPDATE guard_shift_slots SET end_at = ?, grace_minutes = ?, absence_id = ?, status = ?, updated_at = UTC_TIMESTAMP()
            WHERE id = ? AND status = 'anulado' AND edited_at IS NULL");
        $insert = new class ($find, $insertRow, $revive) {
            public int $last = 0;
            public function __construct(private \PDOStatement $find, private \PDOStatement $insert, private \PDOStatement $revive) {}
            public function execute(array $v): void
            {
                [$uuid, $shift, $user, $site, $start, $end, $grace, $status, $absence] = $v;
                $this->last = 0;
                $this->find->execute([$shift, $user, $start]);
                $row = $this->find->fetch();
                if ($row === false) {
                    try {
                        $this->insert->execute([$uuid, $shift, $user, $start, $user, $site, $start, $end, $grace, $status, $absence]);
                        $this->last = 1;
                    } catch (\PDOException) {
                        // otro turno editado ocupa exactamente ese horario y guardia: no se duplica
                    }
                } elseif ($row['status'] === 'anulado' && $row['edited_at'] === null) {
                    $this->revive->execute([$end, $grace, $absence, $status, (int) $row['id']]);
                }
            }
            public function rowCount(): int { return $this->last; }
        };
        $created = 0;
        $touched = [];
        // Por puesto no hay turnos por persona: se generan franjas a cubrir (PostCoverage).
        $shifts = $db->query("SELECT * FROM guard_shifts WHERE is_active = 1 AND deleted_at IS NULL AND mode <> 'puesto'")->fetchAll();
        $activeUsers = array_map('intval', $db->query('SELECT id FROM users WHERE is_active = 1')->fetchAll(\PDO::FETCH_COLUMN));
        foreach ($shifts as $s) {
            $grace = (int) ($s['grace_minutes'] ?? self::defaultGrace());
            $since = strtotime($s['updated_at'] . ' UTC');
            $closed = self::closures((int) $s['site_id'], $from, $to);
            foreach (self::occurrences($s, $from, $to) as $occ) {
                if (isset($closed[$occ['day']]) || strtotime($occ['end'] . ' UTC') <= $since) continue; // sin servicio, o ya había pasado al crear/editar
                foreach (array_intersect(self::userIds($s), $activeUsers) as $userId) {
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
