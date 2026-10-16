<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Tenant;
use App\Core\Uuid;
use App\Models\NotificationMarks;
use App\Models\Patrols;
use App\Models\Settings;
use App\Models\Users;
use App\Services\Notify\Notifier;

/**
 * Programación de rondas (automatizaciones, fase 3). Una ruta puede tener varias programaciones:
 * - horarios fijos ("22:00, 02:00, 06:00") o intervalo ("cada 120 min de 22:00 a 06:00", puede cruzar la medianoche;
 *   el día que cuenta es el del comienzo de la franja);
 * - días de la semana, vigencia desde/hasta, fechas excluidas (feriados), guardias propios (si no, los de la ruta);
 * - gracia (a tiempo = empieza hasta `gracia` min antes o después de la hora) y demora máxima (hasta cuándo puede empezar
 *   tarde; después queda NO REALIZADA).
 * El cron genera las rondas programadas (patrol_slots) de ayer a pasado mañana (idempotente), las une con la ronda que
 * empieza el guardia y avisa: a SyH y supervisores si a la hora + 2×gracia no empezó, y cuando queda no realizada.
 */
final class PatrolPlanner
{
    public const DAYS = [1 => 'Lun', 2 => 'Mar', 3 => 'Mié', 4 => 'Jue', 5 => 'Vie', 6 => 'Sáb', 7 => 'Dom'];
    public const STATUS = ['pendiente' => 'Pendiente', 'realizada' => 'Realizada', 'no_realizada' => 'No realizada', 'justificada' => 'Justificada (guardia ausente)'];

    public static function defaultGrace(): int
    {
        return max(1, min(240, (int) Settings::get('rondas.inicio_gracia_minutos', '10')));
    }

    private static function tz(): \DateTimeZone
    {
        return new \DateTimeZone(Tenant::timezone() ?: 'America/Argentina/Buenos_Aires');
    }

    // ── alta y edición ───────────────────────────────────────────

    /** @return array{0: array, 1: array<string,string>} datos normalizados y errores */
    public static function validate(array $in): array
    {
        $errors = [];
        $route = Patrols::route((string) ($in['route'] ?? ''));
        if (!$route || (int) $route['is_active'] !== 1 || $route['deleted_at'] !== null) $errors['route'] = 'Elegí una ruta activa.';
        $mode = ($in['mode'] ?? 'fixed') === 'interval' ? 'interval' : 'fixed';
        $hhmm = fn ($v) => preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim((string) $v), $m) ? sprintf('%02d:%02d', $m[1], $m[2]) : null;
        $times = [];
        if ($mode === 'fixed') {
            $raw = is_array($in['times'] ?? null) ? $in['times'] : preg_split('/[\s,;]+/', (string) ($in['times'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
            foreach ($raw as $t) {
                $ok = $hhmm($t);
                if ($ok === null) { $errors['times'] = "Horario inválido: «{$t}» (usá HH:MM, por ejemplo 22:00)."; break; }
                $times[$ok] = true;
            }
            $times = array_keys($times);
            sort($times);
            if (!$times && !isset($errors['times'])) $errors['times'] = 'Cargá al menos un horario.';
            if (count($times) > 48) $errors['times'] = 'Máximo 48 horarios por día.';
        }
        $start = $end = null;
        $every = null;
        if ($mode === 'interval') {
            $start = $hhmm($in['window_start'] ?? '');
            $end = $hhmm($in['window_end'] ?? '');
            $every = (int) ($in['every_minutes'] ?? 0);
            if ($start === null || $end === null) $errors['window'] = 'Indicá desde y hasta (HH:MM).';
            if ($every < 15 || $every > 720) $errors['every_minutes'] = 'El intervalo va de 15 a 720 minutos.';
        }
        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($in['days'] ?? [])), fn ($d) => $d >= 1 && $d <= 7)));
        sort($days);
        if (!$days) $errors['days'] = 'Elegí al menos un día.';
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) && strtotime((string) $v) !== false ? (string) $v : null;
        $from = $date($in['valid_from'] ?? '');
        $until = $date($in['valid_until'] ?? '');
        if ($from && $until && $until < $from) $errors['valid_until'] = 'La vigencia termina antes de empezar.';
        $except = [];
        foreach (preg_split('/[\s,;]+/', (string) ($in['except_dates'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $d) {
            $iso = preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $d, $m) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : $d; // acepta 25/12/2026
            if ($date($iso) === null) { $errors['except_dates'] = "Fecha excluida inválida: «{$d}»."; break; }
            $except[$iso] = true;
        }
        $except = array_keys($except);
        sort($except);
        $users = [];
        foreach ((array) ($in['users'] ?? []) as $uuid) {
            $u = Users::findByUuid((string) $uuid);
            if ($u === null) continue;
            if ($route && !Patrols::isAssigned((int) $route['id'], (int) $u['id'])) {
                $errors['users'] = "{$u['name']} no está asignado a la ruta: asignalo primero en la ruta.";
                break;
            }
            $users[] = (int) $u['id'];
        }
        $grace = ($in['grace_minutes'] ?? '') === '' ? null : max(1, min(120, (int) $in['grace_minutes']));
        $delay = max(1, min(720, (int) ($in['max_delay_minutes'] ?? 60)));
        if ($delay < ($grace ?? self::defaultGrace())) $errors['max_delay_minutes'] = 'La demora máxima no puede ser menor que la gracia.';
        if ($mode === 'interval' && $every && $delay > $every) $errors['max_delay_minutes'] = 'Con intervalo, la demora máxima no puede superar el intervalo (se pisaría con la siguiente).';
        $data = ['route_id' => $route ? (int) $route['id'] : null, 'name' => mb_substr(trim((string) ($in['name'] ?? '')), 0, 120) ?: null, 'mode' => $mode,
            'times' => $times ? json_encode($times) : null, 'window_start' => $start, 'window_end' => $end, 'every_minutes' => $every ?: null,
            'days' => implode(',', $days), 'valid_from' => $from, 'valid_until' => $until, 'except_dates' => $except ? json_encode($except) : null,
            'user_ids' => $users ? json_encode($users) : null, 'grace_minutes' => $grace, 'max_delay_minutes' => $delay];
        return [$data, $errors];
    }

    /** Alta o edición. Al editar, las rondas programadas futuras pendientes se vuelven a generar con la configuración nueva. */
    public static function save(?array $schedule, array $data): string
    {
        $db = DB::tenant();
        $cols = array_keys($data);
        if ($schedule === null) {
            $uuid = Uuid::v4();
            $db->prepare('INSERT INTO patrol_schedules (uuid, ' . implode(', ', $cols) . ', is_active, created_by, created_at, updated_at) VALUES (?, '
                . implode(', ', array_fill(0, count($cols), '?')) . ', 1, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
                ->execute(array_merge([$uuid], array_values($data), [UserAuth::user()['id'] ?? null]));
            Audit::tenant('patrol_schedule.create', 'patrol_schedule', $uuid, null, $data);
        } else {
            $uuid = $schedule['uuid'];
            $db->prepare('UPDATE patrol_schedules SET ' . implode(', ', array_map(fn ($c) => "{$c} = ?", $cols)) . ', updated_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute(array_merge(array_values($data), [(int) $schedule['id']]));
            self::dropFuture((int) $schedule['id']);
            Audit::tenant('patrol_schedule.update', 'patrol_schedule', $uuid, array_intersect_key($schedule, $data), $data);
        }
        self::generate();
        return $uuid;
    }

    public static function setActive(array $schedule, bool $active): void
    {
        DB::tenant()->prepare('UPDATE patrol_schedules SET is_active = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$active ? 1 : 0, (int) $schedule['id']]);
        self::dropFuture((int) $schedule['id']);
        Audit::tenant($active ? 'patrol_schedule.activate' : 'patrol_schedule.deactivate', 'patrol_schedule', $schedule['uuid']);
        if ($active) self::generate();
    }

    /** Las pendientes que todavía no llegaron a su hora se borran (y bajan como baja al celular); las pasadas quedan como registro. */
    private static function dropFuture(int $scheduleId): void
    {
        // Se marcan como baja para el celular antes de borrarlas: cambia updated_at y el pull las manda como "deleted".
        DB::tenant()->prepare("UPDATE patrol_slots SET status = 'anulada', updated_at = UTC_TIMESTAMP() WHERE schedule_id = ? AND status = 'pendiente' AND due_at > UTC_TIMESTAMP()")
            ->execute([$scheduleId]);
    }

    public static function find(string $uuid): ?array
    {
        $s = DB::tenant()->prepare('SELECT s.*, r.uuid AS route_uuid, r.name AS route_name, r.expected_minutes FROM patrol_schedules s JOIN patrol_routes r ON r.id = s.route_id WHERE s.uuid = ? AND s.deleted_at IS NULL');
        $s->execute([$uuid]);
        return $s->fetch() ?: null;
    }

    public static function all(): array
    {
        return DB::tenant()->query('SELECT s.*, r.uuid AS route_uuid, r.name AS route_name, r.expected_minutes FROM patrol_schedules s
            JOIN patrol_routes r ON r.id = s.route_id WHERE s.deleted_at IS NULL ORDER BY s.is_active DESC, r.name, s.id')->fetchAll();
    }

    /** "22:00, 02:00 · Lun a Vie" o "cada 120 min de 22:00 a 06:00 · todos los días". */
    public static function describe(array $s): string
    {
        $when = $s['mode'] === 'interval'
            ? 'cada ' . (int) $s['every_minutes'] . " min de {$s['window_start']} a {$s['window_end']}"
            : implode(', ', json_decode((string) $s['times'], true) ?: []);
        $days = array_map('intval', explode(',', (string) $s['days']));
        $dayText = count($days) === 7 ? 'todos los días' : ($days === [1, 2, 3, 4, 5] ? 'Lun a Vie' : ($days === [6, 7] ? 'fines de semana'
            : implode(', ', array_map(fn ($d) => self::DAYS[$d], $days))));
        return "{$when} · {$dayText}";
    }

    // ── generación ───────────────────────────────────────────────

    /**
     * Horarios (UTC, 'Y-m-d H:i:s') de una programación entre dos días locales inclusive.
     * @return list<string>
     */
    public static function occurrences(array $s, string $fromDay, string $toDay): array
    {
        $tz = self::tz();
        $utc = new \DateTimeZone('UTC');
        $days = array_map('intval', explode(',', (string) $s['days']));
        $except = json_decode((string) ($s['except_dates'] ?? ''), true) ?: [];
        $out = [];
        for ($d = new \DateTimeImmutable($fromDay, $tz); $d->format('Y-m-d') <= $toDay; $d = $d->modify('+1 day')) {
            $day = $d->format('Y-m-d');
            if (!in_array((int) $d->format('N'), $days, true) || in_array($day, $except, true)) continue;
            if (($s['valid_from'] && $day < $s['valid_from']) || ($s['valid_until'] && $day > $s['valid_until'])) continue;
            if ($s['mode'] === 'interval') {
                $t = new \DateTimeImmutable("{$day} {$s['window_start']}", $tz);
                $end = new \DateTimeImmutable("{$day} {$s['window_end']}", $tz);
                if ($end <= $t) $end = $end->modify('+1 day'); // la franja cruza la medianoche
                for ($n = 0; $t <= $end && $n < 200; $t = $t->modify('+' . (int) $s['every_minutes'] . ' minutes'), $n++) {
                    $out[] = $t->setTimezone($utc)->format('Y-m-d H:i:s');
                }
            } else {
                foreach (json_decode((string) $s['times'], true) ?: [] as $time) {
                    $out[] = (new \DateTimeImmutable("{$day} {$time}", $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
                }
            }
        }
        sort($out);
        return array_values(array_unique($out));
    }

    /** Genera las rondas programadas de ayer a pasado mañana (hora local). Idempotente. @return int creadas */
    public static function generate(?int $now = null): int
    {
        $now ??= time();
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone(self::tz());
        $from = $local->modify('-1 day')->format('Y-m-d');
        $to = $local->modify('+2 days')->format('Y-m-d');
        $created = 0;
        // Si el horario ya existía anulado (se editó la programación y quedó igual), vuelve a pendiente con los plazos nuevos.
        $insert = DB::tenant()->prepare("INSERT INTO patrol_slots (uuid, schedule_id, route_id, due_at, grace_minutes, deadline_at, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pendiente', UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE grace_minutes = IF(status = 'anulada', VALUES(grace_minutes), grace_minutes),
                deadline_at = IF(status = 'anulada', VALUES(deadline_at), deadline_at),
                updated_at = IF(status = 'anulada', UTC_TIMESTAMP(), updated_at),
                status = IF(status = 'anulada', 'pendiente', status)");
        $schedules = DB::tenant()->query('SELECT s.* FROM patrol_schedules s JOIN patrol_routes r ON r.id = s.route_id
            WHERE s.is_active = 1 AND s.deleted_at IS NULL AND r.is_active = 1 AND r.deleted_at IS NULL')->fetchAll();
        foreach ($schedules as $s) {
            $grace = (int) ($s['grace_minutes'] ?? self::defaultGrace());
            $since = strtotime($s['updated_at'] . ' UTC');
            foreach (self::occurrences($s, $from, $to) as $due) {
                $deadline = strtotime($due . ' UTC') + (int) $s['max_delay_minutes'] * 60;
                if ($deadline <= $since) continue; // horarios que ya habían pasado cuando se creó o cambió la programación
                $insert->execute([Uuid::v4(), (int) $s['id'], (int) $s['route_id'], $due, $grace, gmdate('Y-m-d H:i:s', $deadline)]);
                $created += $insert->rowCount() === 1 ? 1 : 0; // 1 = nueva, 2 = reactivada, 0 = ya estaba
            }
        }
        return $created;
    }

    /** Guardias a cargo: los de la programación, o si no tiene, los asignados a la ruta. @return list<int> */
    public static function assignees(int $scheduleId): array
    {
        $s = DB::tenant()->prepare('SELECT user_ids, route_id FROM patrol_schedules WHERE id = ?');
        $s->execute([$scheduleId]);
        $row = $s->fetch();
        if (!$row) return [];
        $ids = json_decode((string) ($row['user_ids'] ?? ''), true) ?: [];
        if ($ids) return array_map('intval', $ids);
        $q = DB::tenant()->prepare('SELECT user_id FROM patrol_route_assignments WHERE route_id = ? AND is_active = 1');
        $q->execute([(int) $row['route_id']]);
        return array_map('intval', $q->fetchAll(\PDO::FETCH_COLUMN));
    }

    // ── cumplimiento ─────────────────────────────────────────────

    /**
     * Une la ronda que empezó con su ronda programada: la indicada por el celular o la pendiente de esa ruta cuya ventana
     * (hora − gracia … demora máxima) contiene el inicio, del guardia a cargo, la más cercana a su hora.
     */
    public static function attach(array $round, ?string $slotUuid = null): void
    {
        if ($round['route_id'] === null) return;
        $started = strtotime($round['started_at'] . ' UTC');
        $q = DB::tenant()->prepare("SELECT * FROM patrol_slots WHERE route_id = ? AND status = 'pendiente'
            AND DATE_SUB(due_at, INTERVAL grace_minutes MINUTE) <= ? AND deadline_at >= ? ORDER BY ABS(TIMESTAMPDIFF(SECOND, due_at, ?))");
        $q->execute([(int) $round['route_id'], $round['started_at'], $round['started_at'], $round['started_at']]);
        $candidates = $q->fetchAll();
        if ($slotUuid) usort($candidates, fn ($a, $b) => ($b['uuid'] === $slotUuid) <=> ($a['uuid'] === $slotUuid));
        foreach ($candidates as $slot) {
            if (!in_array((int) $round['user_id'], self::assignees((int) $slot['schedule_id']), true)) continue;
            $onTime = $started <= strtotime($slot['due_at'] . ' UTC') + (int) $slot['grace_minutes'] * 60;
            DB::tenant()->prepare("UPDATE patrol_slots SET status = 'realizada', round_id = ?, started_at = ?, on_time = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'pendiente'")
                ->execute([(int) $round['id'], $round['started_at'], $onTime ? 1 : 0, (int) $slot['id']]);
            return;
        }
    }

    /** Cron: genera, avisa las que no empezaron y cierra como no realizadas las vencidas. */
    public static function run(?int $now = null): array
    {
        $now ??= time();
        $out = ['created' => self::generate($now), 'not_started' => 0, 'missed' => 0];
        $notify = RoundAutomation::enabled();
        $previous = UserAuth::user();
        UserAuth::setCurrent(null);
        try {
            $q = DB::tenant()->prepare("SELECT sl.*, r.name AS route_name, sc.name AS schedule_name FROM patrol_slots sl
                JOIN patrol_routes r ON r.id = sl.route_id JOIN patrol_schedules sc ON sc.id = sl.schedule_id
                WHERE sl.status = 'pendiente' AND sl.due_at <= ?");
            $q->execute([gmdate('Y-m-d H:i:s', $now)]);
            foreach ($q->fetchAll() as $slot) {
                $due = strtotime($slot['due_at'] . ' UTC');
                // Todos los guardias a cargo con ausencia aprobada: queda justificada, sin alarmas.
                if (ShiftAbsences::allAbsentAt(self::assignees((int) $slot['schedule_id']), $slot['due_at'])) {
                    DB::tenant()->prepare("UPDATE patrol_slots SET status = 'justificada', note = 'Guardias con ausencia aprobada', updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'pendiente'")
                        ->execute([(int) $slot['id']]);
                    $out['justified'] = ($out['justified'] ?? 0) + 1;
                    continue;
                }
                $row = $slot + ['guards' => self::guardNames((int) $slot['schedule_id'])];
                if ($now >= strtotime($slot['deadline_at'] . ' UTC')) {
                    DB::tenant()->prepare("UPDATE patrol_slots SET status = 'no_realizada', updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'pendiente'")
                        ->execute([(int) $slot['id']]);
                    $out['missed']++;
                    if ($notify && NotificationMarks::claim("slot_missed:{$slot['id']}")) Notifier::dispatch('guard.round_missed', $row);
                } elseif ($now >= $due + 2 * (int) $slot['grace_minutes'] * 60 && NotificationMarks::claim("slot_late:{$slot['id']}")) {
                    $out['not_started']++;
                    if ($notify) Notifier::dispatch('guard.round_not_started', $row + ['late_minutes' => intdiv($now - $due, 60)]);
                }
            }
        } finally {
            UserAuth::setCurrent($previous);
        }
        return $out;
    }

    private static function guardNames(int $scheduleId): string
    {
        $ids = self::assignees($scheduleId);
        if (!$ids) return 'sin guardias asignados';
        $q = DB::tenant()->query('SELECT name FROM users WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY name');
        return implode(', ', $q->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Rondas programadas de un día local (panel), con su estado y la ronda que las cumplió. */
    public static function day(string $localDay, ?int $onlyUser = null): array
    {
        $tz = self::tz();
        $from = (new \DateTimeImmutable("{$localDay} 00:00:00", $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $to = (new \DateTimeImmutable("{$localDay} 00:00:00", $tz))->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $q = DB::tenant()->prepare("SELECT sl.*, r.name AS route_name, r.uuid AS route_uuid, sc.uuid AS schedule_uuid, sc.name AS schedule_name,
                ro.uuid AS round_uuid, ro.status AS round_status, u.name AS user_name
            FROM patrol_slots sl JOIN patrol_routes r ON r.id = sl.route_id JOIN patrol_schedules sc ON sc.id = sl.schedule_id
            LEFT JOIN patrol_rounds ro ON ro.id = sl.round_id LEFT JOIN users u ON u.id = ro.user_id
            WHERE sl.due_at >= ? AND sl.due_at < ? AND sl.status <> 'anulada' ORDER BY sl.due_at, r.name");
        $q->execute([$from, $to]);
        $rows = $q->fetchAll();
        if ($onlyUser !== null) {
            $rows = array_values(array_filter($rows, fn ($r) => in_array($onlyUser, self::assignees((int) $r['schedule_id']), true)));
        }
        return $rows;
    }

    /** "realizada a tiempo" / "realizada tarde" / "no realizada" / "pendiente" / "en hora" (ventana abierta). */
    public static function label(array $slot, ?int $now = null): array
    {
        $now ??= time();
        return match (true) {
            $slot['status'] === 'realizada' && (int) $slot['on_time'] === 1 => ['Realizada a tiempo', 'success'],
            $slot['status'] === 'realizada' => ['Realizada tarde', 'warning'],
            $slot['status'] === 'no_realizada' => ['No realizada', 'danger'],
            $slot['status'] === 'justificada' => ['Justificada (guardia ausente)', 'info'],
            $now < strtotime($slot['due_at'] . ' UTC') - (int) $slot['grace_minutes'] * 60 => ['Pendiente', 'secondary'],
            $now <= strtotime($slot['due_at'] . ' UTC') + (int) $slot['grace_minutes'] * 60 => ['Es la hora', 'info'],
            default => ['Sin empezar (atrasada)', 'warning'],
        };
    }
}
