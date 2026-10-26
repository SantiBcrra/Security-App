<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Uuid;
use App\Models\NotificationMarks;
use App\Models\Settings;
use App\Services\Notify\Notifier;

/**
 * Turnos por puesto (guardias tercerizados que se organizan solos): la programación (guard_shifts.mode = 'puesto') define
 * las franjas que hay que cubrir y el grupo de guardias; NO se sabe de antemano quién viene. Cada franja es una fila de
 * guard_post_coverage; cada guardia que empieza su turno abre un tramo (guard_shift_slots.coverage_id) y el que llega
 * después lo releva (el tramo anterior se cierra con end_mode = relevo). Un tramo puede terminar "sin persona" con motivo
 * (viene el encargado, el dueño…): ese hueco queda justificado y no avisa.
 *
 * Avisos (cron): nadie empezó a la hora + 2×tolerancia (`guard.shift_post_uncovered`), el guardia terminó y nadie lo relevó
 * en `turnos.minutos_relevo` (`guard.shift_relief_missing`), y muchas horas seguidas de la misma persona
 * (`turnos.horas_maximas`, `guard.shift_long`). Al terminar la franja queda cubierta | parcial | sin_cubrir con los
 * segundos cubiertos.
 */
final class PostCoverage
{
    public static function reliefMinutes(): int { return max(5, min(240, (int) Settings::get('turnos.minutos_relevo', '15'))); }
    public static function maxHours(): int { return max(0, min(72, (int) Settings::get('turnos.horas_maximas', '24'))); }

    public const STATUS = ['pendiente' => ['Sin empezar', 'light'], 'en_curso' => ['En curso', 'success'], 'cubierta' => ['Cubierta', 'secondary'],
        'parcial' => ['Cubierta en parte', 'warning'], 'sin_cubrir' => ['Sin cubrir', 'danger'], 'anulado' => ['Anulada', 'light']];

    private const SELECT = 'SELECT c.*, g.uuid AS shift_uuid, g.name AS shift_name, g.user_ids, g.device_user_id, s.uuid AS site_uuid, s.name AS site_name
        FROM guard_post_coverage c JOIN guard_shifts g ON g.id = c.shift_id JOIN sites s ON s.id = c.site_id';

    public static function find(string $uuid): ?array
    {
        $q = DB::tenant()->prepare(self::SELECT . ' WHERE c.uuid = ?');
        $q->execute([strtolower($uuid)]);
        return $q->fetch() ?: null;
    }

    public static function findById(int $id): ?array
    {
        $q = DB::tenant()->prepare(self::SELECT . ' WHERE c.id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    // ── generación ────────────────────────────────────────────────

    /** Franjas de ayer a +6 días de las programaciones por puesto activas (idempotente). @return int creadas */
    public static function generate(?int $now = null): int
    {
        $now ??= time();
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone(ShiftPlanner::tz());
        $from = $local->modify('-1 day')->format('Y-m-d');
        $to = $local->modify('+' . ShiftPlanner::AHEAD_DAYS . ' days')->format('Y-m-d');
        $db = DB::tenant();
        $find = $db->prepare('SELECT id, status FROM guard_post_coverage WHERE shift_id = ? AND start_at = ?');
        $insert = $db->prepare("INSERT INTO guard_post_coverage (uuid, shift_id, site_id, start_at, end_at, grace_minutes, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 'pendiente', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $revive = $db->prepare("UPDATE guard_post_coverage SET end_at = ?, grace_minutes = ?, site_id = ?, status = 'pendiente', updated_at = UTC_TIMESTAMP()
            WHERE id = ? AND status = 'anulado' AND NOT EXISTS (SELECT 1 FROM guard_shift_slots t WHERE t.coverage_id = guard_post_coverage.id)");
        $created = 0;
        foreach ($db->query("SELECT * FROM guard_shifts WHERE mode = 'puesto' AND is_active = 1 AND deleted_at IS NULL")->fetchAll() as $s) {
            $grace = (int) ($s['grace_minutes'] ?? ShiftPlanner::defaultGrace());
            $since = strtotime($s['updated_at'] . ' UTC');
            $closed = ShiftPlanner::closures((int) $s['site_id'], $from, $to);
            foreach (ShiftPlanner::occurrences($s, $from, $to) as $occ) {
                if (isset($closed[$occ['day']]) || strtotime($occ['end'] . ' UTC') <= $since) continue;
                $find->execute([(int) $s['id'], $occ['start']]);
                $row = $find->fetch();
                if ($row === false) {
                    $insert->execute([Uuid::v4(), (int) $s['id'], (int) $s['site_id'], $occ['start'], $occ['end'], $grace]);
                    $created++;
                } elseif ($row['status'] === 'anulado') {
                    $revive->execute([$occ['end'], $grace, (int) $s['site_id'], (int) $row['id']]);
                }
            }
        }
        return $created;
    }

    /** Al editar, pausar o eliminar la programación: se anulan las franjas futuras que nadie empezó. */
    public static function dropFuture(int $shiftId): void
    {
        DB::tenant()->prepare("UPDATE guard_post_coverage SET status = 'anulado', updated_at = UTC_TIMESTAMP() WHERE shift_id = ? AND status = 'pendiente'
            AND start_at > UTC_TIMESTAMP() AND NOT EXISTS (SELECT 1 FROM guard_shift_slots t WHERE t.coverage_id = guard_post_coverage.id)")->execute([$shiftId]);
    }

    // ── empezar un turno en un puesto ─────────────────────────────

    /**
     * Franja que corresponde al guardia que empieza a $at: la indicada (coverage_uuid) o la del predio que esté vigente (se
     * puede empezar hasta EARLY_MINUTES antes). Solo si el guardia es del grupo de esa programación.
     */
    public static function forStart(int $userId, array $d, string $at): ?array
    {
        $ts = strtotime($at . ' UTC');
        $early = gmdate('Y-m-d H:i:s', $ts + ShiftService::EARLY_MINUTES * 60);
        if (!empty($d['coverage_uuid'])) {
            $c = self::find((string) $d['coverage_uuid']);
            $rows = $c ? [$c] : [];
        } else {
            $site = \App\Models\Sites::findByUuid((string) ($d['site_uuid'] ?? ''));
            if ($site === null) return null;
            $q = DB::tenant()->prepare(self::SELECT . " WHERE c.site_id = ? AND c.status <> 'anulado' AND c.start_at <= ? AND c.end_at > ? ORDER BY c.start_at");
            $q->execute([(int) $site['id'], $early, $at]);
            $rows = $q->fetchAll();
        }
        foreach ($rows as $c) {
            if ($c['status'] === 'anulado' || $c['start_at'] > $early || $c['end_at'] <= $at) continue;
            if (in_array($userId, ShiftPlanner::userIds($c), true)) return $c;
        }
        return null;
    }

    /** Guardia en el puesto ahora (tramo en curso de cualquier franja de esa programación). */
    public static function onDuty(int $shiftId): ?array
    {
        $q = DB::tenant()->prepare("SELECT t.id FROM guard_shift_slots t WHERE t.shift_id = ? AND t.coverage_id IS NOT NULL AND t.status = 'en_curso'
            ORDER BY t.started_at DESC LIMIT 1");
        $q->execute([$shiftId]);
        $id = $q->fetchColumn();
        return $id ? ShiftService::findById((int) $id) : null;
    }

    /** Marca la franja como empezada (primer guardia). */
    public static function markStarted(array $c, string $at): void
    {
        DB::tenant()->prepare("UPDATE guard_post_coverage SET status = IF(status = 'pendiente', 'en_curso', status), first_start_at = COALESCE(first_start_at, ?),
            updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$at, (int) $c['id']]);
    }

    // ── tramos, huecos y cobertura ────────────────────────────────

    /** Tramos de guardias de una franja, en orden. */
    public static function segments(int $coverageId): array
    {
        $q = DB::tenant()->prepare("SELECT t.*, u.name AS user_name, u.uuid AS user_uuid FROM guard_shift_slots t JOIN users u ON u.id = t.user_id
            WHERE t.coverage_id = ? AND t.status IN ('en_curso', 'cumplido') AND t.started_at IS NOT NULL ORDER BY t.started_at, t.id");
        $q->execute([$coverageId]);
        return $q->fetchAll();
    }

    /**
     * Huecos sin guardia dentro de la franja (hasta $until). Un hueco que empieza cuando alguien dejó el puesto "sin persona"
     * está justificado (con el motivo).
     * @return list<array{from:string, to:string, seconds:int, justified:bool, reason:?string, after:?string}>
     */
    public static function gaps(array $c, ?int $until = null): array
    {
        $start = strtotime($c['start_at'] . ' UTC');
        $end = min(strtotime($c['end_at'] . ' UTC'), $until ?? time());
        if ($end <= $start) return [];
        $gaps = [];
        $cursor = $start;
        $prev = null;
        foreach (self::segments((int) $c['id']) as $s) {
            $a = max($start, strtotime($s['started_at'] . ' UTC'));
            $b = $s['ended_at'] ? strtotime($s['ended_at'] . ' UTC') : $end;
            if ($a > $cursor && $cursor < $end) {
                $gaps[] = self::gap($cursor, min($a, $end), $prev);
            }
            $cursor = max($cursor, min($b, $end));
            $prev = $s;
        }
        if ($cursor < $end && !($prev !== null && $prev['ended_at'] === null)) $gaps[] = self::gap($cursor, $end, $prev);
        return array_values(array_filter($gaps, fn ($g) => $g['seconds'] >= 60)); // menos de un minuto (el relevo) no es un hueco
    }

    private static function gap(int $a, int $b, ?array $prev): array
    {
        $justified = $prev !== null && $prev['end_mode'] === 'sin_persona';
        return ['from' => gmdate('Y-m-d H:i:s', $a), 'to' => gmdate('Y-m-d H:i:s', $b), 'seconds' => $b - $a, 'justified' => $justified,
            'reason' => $justified ? $prev['end_note'] : null, 'after' => $prev['user_name'] ?? null];
    }

    /** Segundos con guardia dentro de la franja (hasta $until). */
    public static function coveredSeconds(array $c, ?int $until = null): int
    {
        $start = strtotime($c['start_at'] . ' UTC');
        $end = min(strtotime($c['end_at'] . ' UTC'), $until ?? time());
        if ($end <= $start) return 0;
        // Unión de los tramos recortada a la franja (dos tramos que se pisan en un relevo no se cuentan dos veces).
        $covered = 0;
        $cursor = $start;
        foreach (self::segments((int) $c['id']) as $s) {
            $a = max($cursor, strtotime($s['started_at'] . ' UTC'));
            $b = min($end, $s['ended_at'] ? strtotime($s['ended_at'] . ' UTC') : $end);
            if ($b > $a) { $covered += $b - $a; $cursor = $b; }
        }
        return $covered;
    }

    // ── cron ──────────────────────────────────────────────────────

    public static function run(?int $now = null): array
    {
        $now ??= time();
        $out = ['coverage' => self::generate($now), 'uncovered' => 0, 'relief' => 0, 'long' => 0, 'closed' => 0];
        $notify = ShiftService::enabled();
        $nowSql = gmdate('Y-m-d H:i:s', $now);
        $db = DB::tenant();
        $q = $db->prepare("SELECT id FROM guard_post_coverage WHERE status IN ('pendiente', 'en_curso') AND start_at <= ?");
        $q->execute([$nowSql]);
        foreach ($q->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $c = self::findById((int) $id);
            $start = strtotime($c['start_at'] . ' UTC');
            $end = strtotime($c['end_at'] . ' UTC');
            $segments = self::segments((int) $c['id']);
            $open = array_values(array_filter($segments, fn ($s) => $s['status'] === 'en_curso'));
            if ($now >= $end) {
                if ($open && $now < $end + ShiftService::autoEndMinutes() * 60) continue; // espera el cierre del último tramo
                $covered = self::coveredSeconds($c, $end);
                $total = max(1, $end - $start);
                $status = $covered >= $total - 120 ? 'cubierta' : ($covered > 0 ? 'parcial' : 'sin_cubrir');
                $db->prepare('UPDATE guard_post_coverage SET status = ?, covered_seconds = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
                    ->execute([$status, $covered, (int) $c['id']]);
                $out['closed']++;
                // Reporte de fin de turno (RRHH y quien diga la regla `guard.shift_report`), una vez por franja.
                if ($notify) ShiftEndReport::send(self::findById((int) $c['id']));
                continue;
            }
            $subject = $c + ['_post' => true, 'uuid' => $c['uuid']];
            if (!$segments) {
                if ($now >= $start + 2 * (int) $c['grace_minutes'] * 60 && NotificationMarks::claim("post_uncovered:{$c['id']}")) {
                    $out['uncovered']++;
                    if ($notify) Notifier::dispatch('guard.shift_post_uncovered', $subject + ['user_id' => null]);
                }
                continue;
            }
            if (!$open) {
                // El último en irse (por hora de fin; a igual hora, el último cargado).
                $last = $segments[0];
                foreach ($segments as $sg) if ([$sg['ended_at'], (int) $sg['id']] >= [$last['ended_at'], (int) $last['id']]) $last = $sg;
                $ended = strtotime($last['ended_at'] . ' UTC');
                if ($last['end_mode'] !== 'sin_persona' && $now >= $ended + self::reliefMinutes() * 60
                    && NotificationMarks::claim("post_relief:{$c['id']}:{$last['id']}")) {
                    $out['relief']++;
                    if ($notify) Notifier::dispatch('guard.shift_relief_missing', $subject + ['user_id' => null, 'user_name' => $last['user_name'], 'ended_at' => $last['ended_at']]);
                }
            }
        }
        // Muchas horas seguidas de la misma persona (en cualquier puesto o turno).
        if (($max = self::maxHours()) > 0) {
            $q = $db->prepare("SELECT id FROM guard_shift_slots WHERE status = 'en_curso' AND started_at <= ?");
            $q->execute([gmdate('Y-m-d H:i:s', $now - $max * 3600)]);
            foreach ($q->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                if (!NotificationMarks::claim("shift_long:{$id}")) continue;
                $s = ShiftService::findById((int) $id);
                $out['long']++;
                if ($notify) Notifier::dispatch('guard.shift_long', $s + ['hours' => intdiv($now - strtotime($s['started_at'] . ' UTC'), 3600)]);
            }
        }
        return $out;
    }

    // ── panel ─────────────────────────────────────────────────────

    /** Franjas que se cruzan con un día local, con tramos, huecos y cobertura. */
    public static function day(string $localDay): array
    {
        [$from, $to] = ShiftPlanner::dayRange($localDay);
        $q = DB::tenant()->prepare(self::SELECT . " WHERE c.status <> 'anulado' AND c.start_at < ? AND c.end_at > ? ORDER BY s.name, c.start_at");
        $q->execute([$to, $from]);
        return array_map(fn ($c) => self::detail($c), $q->fetchAll());
    }

    public static function detail(array $c, ?int $now = null): array
    {
        $now ??= time();
        $start = strtotime($c['start_at'] . ' UTC');
        $end = strtotime($c['end_at'] . ' UTC');
        $until = min($now, $end);
        $segments = self::segments((int) $c['id']);
        $elapsed = max(0, $until - $start);
        $covered = $c['covered_seconds'] !== null && $now >= $end ? (int) $c['covered_seconds'] : self::coveredSeconds($c, $now);
        $open = array_values(array_filter($segments, fn ($s) => $s['status'] === 'en_curso'));
        return $c + ['segments' => $segments, 'gaps' => self::gaps($c, $now), 'covered' => $covered, 'elapsed' => $elapsed,
            'percent' => $elapsed > 0 ? (int) round(100 * $covered / $elapsed) : null, 'on_duty' => $open[0] ?? null];
    }

    /**
     * Reporte de cobertura entre dos días locales: por puesto, horas a cubrir, cubiertas, huecos justificados y sin justificar.
     * @return list<array>
     */
    public static function report(string $fromDay, string $toDay, ?int $siteId = null): array
    {
        [$from] = ShiftPlanner::dayRange($fromDay);
        [, $to] = ShiftPlanner::dayRange($toDay);
        $q = DB::tenant()->prepare(self::SELECT . " WHERE c.status <> 'anulado' AND c.start_at >= ? AND c.start_at < ? AND c.start_at <= UTC_TIMESTAMP()"
            . ($siteId !== null ? ' AND c.site_id = ' . (int) $siteId : '') . ' ORDER BY s.name, c.start_at');
        $q->execute([$from, $to]);
        $posts = [];
        $now = time();
        foreach ($q->fetchAll() as $c) {
            $d = self::detail($c, $now);
            $key = (int) $c['shift_id'];
            $posts[$key] ??= ['shift_uuid' => $c['shift_uuid'], 'name' => $c['shift_name'], 'site_name' => $c['site_name'], 'scheduled' => 0, 'covered' => 0,
                'justified' => 0, 'unjustified' => 0, 'franjas' => 0, 'sin_cubrir' => 0, 'rows' => []];
            $p = &$posts[$key];
            $p['franjas']++;
            $p['scheduled'] += $d['elapsed'];
            $p['covered'] += $d['covered'];
            foreach ($d['gaps'] as $g) $p[$g['justified'] ? 'justified' : 'unjustified'] += $g['seconds'];
            if ($d['covered'] === 0 && $d['elapsed'] > 0) $p['sin_cubrir']++;
            $p['rows'][] = $d;
            unset($p);
        }
        return array_values($posts);
    }
}
