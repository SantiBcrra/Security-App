<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Tenant;
use App\Core\UserError;
use App\Core\Uuid;
use App\Models\NotificationMarks;
use App\Models\Patrols;
use App\Models\Settings;
use App\Services\Notify\Notifier;

/**
 * Turnos de guardias en ejecución (docs/design/turnos-guardias.md): empezar / terminar (desde la app), salidas del predio
 * con motivo, posiciones fuera del predio y el cron (sin empezar, ausente, cierre automático, salida sin motivo, sin
 * contacto, borrado de posiciones viejas). La ubicación se registra SOLO durante el turno.
 */
final class ShiftService
{
    public const STATUS = ['programado' => 'Programado', 'en_curso' => 'En turno', 'cumplido' => 'Cumplido', 'ausente' => 'No vino',
        'justificado' => 'Ausencia justificada', 'anulado' => 'Anulado'];
    public const REASONS = ['robo' => 'Persecución / robo', 'comisaria' => 'Comisaría / denuncia', 'medica' => 'Emergencia médica',
        'encargado' => 'Me lo pidió el encargado', 'servicio' => 'Trámite del servicio', 'otro' => 'Otro'];
    /** Se puede empezar el turno hasta esta cantidad de minutos antes del inicio. */
    public const EARLY_MINUTES = 60;
    /** Un turno no programado dura como máximo esto (si no lo termina, se cierra solo). */
    public const UNSCHEDULED_HOURS = 12;

    // ── ajustes (Configuración → notificaciones → Turnos) ───────────

    public static function enabled(): bool { return Settings::bool('turnos.automatizaciones', true); }
    public static function toleranceM(): int { return self::int('turnos.tolerancia_m', 100, 0, 2000); }
    public static function outMinutes(): int { return self::int('turnos.minutos_fuera', 3, 1, 60); }
    public static function noReasonMinutes(): int { return self::int('turnos.minutos_sin_motivo', 10, 1, 240); }
    public static function autoEndMinutes(): int { return self::int('turnos.cierre_auto_minutos', 120, 15, 720); }
    public static function silentMinutes(): int { return self::int('turnos.minutos_sin_contacto', 30, 10, 720); }
    public static function retentionDays(): int { return self::int('turnos.dias_posiciones', 90, 7, 3650); }

    private static function int(string $key, int $default, int $min, int $max): int
    {
        return max($min, min($max, (int) Settings::get($key, (string) $default)));
    }

    /** Para la app (`sync/pull.meta.turnos`). */
    public static function meta(): array
    {
        return ['activas' => self::enabled(), 'tolerancia_m' => self::toleranceM(), 'minutos_fuera' => self::outMinutes(),
            'minutos_sin_motivo' => self::noReasonMinutes(), 'inicio_anticipado_minutos' => self::EARLY_MINUTES,
            'gracia_minutos' => ShiftPlanner::defaultGrace(), 'motivos' => self::REASONS];
    }

    // ── consultas ─────────────────────────────────────────────────

    private const SELECT = 'SELECT t.*, u.name AS user_name, u.uuid AS user_uuid, s.uuid AS site_uuid, s.name AS site_name, s.lat AS site_lat,
            s.lng AS site_lng, s.geofence_radius_m AS site_radius, g.uuid AS shift_uuid, g.name AS shift_name, g.mode AS shift_mode,
            (SELECT c.uuid FROM guard_post_coverage c WHERE c.id = t.coverage_id) AS coverage_uuid,
            (SELECT name FROM users eu WHERE eu.id = t.edited_by) AS edited_by_name,
            (SELECT COUNT(*) FROM guard_shift_exits e WHERE e.slot_id = t.id) AS exits_count,
            (SELECT e.uuid FROM guard_shift_exits e WHERE e.slot_id = t.id AND e.returned_at IS NULL ORDER BY e.left_at DESC LIMIT 1) AS open_exit_uuid
        FROM guard_shift_slots t JOIN users u ON u.id = t.user_id JOIN sites s ON s.id = t.site_id LEFT JOIN guard_shifts g ON g.id = t.shift_id';

    public static function find(string $uuid): ?array
    {
        $q = DB::tenant()->prepare(self::SELECT . ' WHERE t.uuid = ?');
        $q->execute([$uuid]);
        return $q->fetch() ?: null;
    }

    public static function findById(int $id): ?array
    {
        $q = DB::tenant()->prepare(self::SELECT . ' WHERE t.id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    /** Turnos que se cruzan con un día local (panel "Hoy" y calendario). */
    public static function day(string $localDay, ?int $onlyUser = null): array
    {
        [$from, $to] = ShiftPlanner::dayRange($localDay);
        $q = DB::tenant()->prepare(self::SELECT . " WHERE t.status <> 'anulado' AND t.start_at < ? AND t.end_at > ?"
            . ($onlyUser !== null ? ' AND t.user_id = ' . (int) $onlyUser : '') . ' ORDER BY t.start_at, u.name');
        $q->execute([$to, $from]);
        return $q->fetchAll();
    }

    /** Turnos entre dos instantes UTC (reporte de horas / historial). */
    public static function between(string $fromUtc, string $toUtc, ?int $userId = null, ?int $siteId = null): array
    {
        $q = DB::tenant()->prepare(self::SELECT . " WHERE t.status <> 'anulado' AND t.start_at >= ? AND t.start_at < ?"
            . ($userId !== null ? ' AND t.user_id = ' . (int) $userId : '') . ($siteId !== null ? ' AND t.site_id = ' . (int) $siteId : '') . ' ORDER BY t.start_at');
        $q->execute([$fromUtc, $toUtc]);
        return $q->fetchAll();
    }

    public static function exits(int $slotId): array
    {
        $q = DB::tenant()->prepare('SELECT * FROM guard_shift_exits WHERE slot_id = ? ORDER BY left_at');
        $q->execute([$slotId]);
        return $q->fetchAll();
    }

    public static function positions(int $slotId): array
    {
        $q = DB::tenant()->prepare('SELECT p.at, p.lat, p.lng, p.accuracy_m, p.distance_m, e.uuid AS exit_uuid FROM guard_shift_positions p
            LEFT JOIN guard_shift_exits e ON e.id = p.exit_id WHERE p.slot_id = ? ORDER BY p.at');
        $q->execute([$slotId]);
        return $q->fetchAll();
    }

    /** ¿Es guardia de alguna programación activa (por persona o del grupo de un puesto)? */
    public static function isGuard(int $userId): bool
    {
        foreach (DB::tenant()->query('SELECT user_ids FROM guard_shifts WHERE is_active = 1 AND deleted_at IS NULL')->fetchAll(\PDO::FETCH_COLUMN) as $ids) {
            if (in_array($userId, array_map('intval', json_decode((string) $ids, true) ?: []), true)) return true;
        }
        return false;
    }

    /** Turno en curso del usuario (uno a la vez). */
    public static function current(int $userId): ?array
    {
        $q = DB::tenant()->prepare(self::SELECT . " WHERE t.user_id = ? AND t.status = 'en_curso' ORDER BY t.started_at DESC LIMIT 1");
        $q->execute([$userId]);
        return $q->fetch() ?: null;
    }

    /** Estado para mostrar: [texto, color bootstrap]. */
    public static function label(array $s, ?int $now = null): array
    {
        $now ??= time();
        $start = strtotime($s['start_at'] . ' UTC');
        return match (true) {
            $s['status'] === 'en_curso' && $s['open_exit_uuid'] => ['FUERA del predio', 'danger'],
            $s['status'] === 'en_curso' => ['En turno', 'success'],
            $s['status'] === 'cumplido' => [match ($s['end_mode']) { 'auto' => 'Cumplido (sin marcar el fin)', 'relevo' => 'Cumplido (relevado)',
                'sin_persona' => 'Dejó el puesto sin persona', default => 'Cumplido' }, 'secondary'],
            $s['status'] === 'ausente' => ['No vino', 'danger'],
            $s['status'] === 'justificado' => ['Ausencia justificada', 'info'],
            $s['status'] === 'anulado' => ['Anulado', 'light'],
            $now < $start - self::EARLY_MINUTES * 60 => ['Programado', 'light'],
            $now <= $start + (int) $s['grace_minutes'] * 60 => ['Es la hora', 'info'],
            default => ['Sin empezar', 'warning'],
        };
    }

    /** Distancia al centro del predio y si está adentro (radio + tolerancia; un GPS impreciso no se acusa). */
    public static function where(array $slot, ?float $lat, ?float $lng, ?float $accuracy, bool $mock = false): array
    {
        if ($lat === null || $lng === null || $slot['site_lat'] === null || $slot['site_lng'] === null) {
            return ['distance' => null, 'inside' => null, 'status' => $lat === null ? 'sin_gps' : 'predio_sin_ubicacion'];
        }
        $d = Patrols::distance($lat, $lng, (float) $slot['site_lat'], (float) $slot['site_lng']);
        $radius = (int) ($slot['site_radius'] ?: 200) + self::toleranceM();
        $status = Patrols::locationStatus($d, $accuracy, $radius, $mock);
        return ['distance' => round($d, 2), 'inside' => in_array($status, ['ok', 'impreciso'], true), 'status' => $status];
    }

    // ── alta, edición y baja desde el panel (supervisor) ──────────

    /** "2026-10-23T22:00" (hora de la empresa) → UTC 'Y-m-d H:i:s', o null. */
    private static function localInput(mixed $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '' || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $v)) return null;
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i', substr(str_replace('T', ' ', $v), 0, 16), ShiftPlanner::tz());
        return $d ? $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null;
    }

    /**
     * Crear un turno suelto (sin programación) o editar uno: guardia, predio, horario programado, tolerancia y, como
     * corrección, el inicio y el fin reales (con motivo obligatorio: queda auditado con el antes y el después).
     * @return array el turno guardado
     */
    public static function adminSave(?array $slot, array $in): array
    {
        if (!UserAuth::can('rondas', 'editar')) throw new UserError('No tenés permiso para editar turnos.');
        $user = \App\Models\Users::findByUuid((string) ($in['user'] ?? ''));
        if ($user === null || (int) $user['is_active'] !== 1) throw new UserError('Elegí el guardia.');
        $site = \App\Models\Sites::findByUuid((string) ($in['site'] ?? ''));
        if ($site === null) throw new UserError('Elegí el predio.');
        $start = self::localInput($in['start_at'] ?? '') ?? throw new UserError('Indicá el inicio programado.');
        $end = self::localInput($in['end_at'] ?? '') ?? throw new UserError('Indicá el fin programado.');
        if ($end <= $start) throw new UserError('El fin tiene que ser posterior al inicio (para un turno nocturno, poné la fecha del día siguiente).');
        if (strtotime($end . ' UTC') - strtotime($start . ' UTC') > ShiftPlanner::MAX_BLOCK_HOURS * 3600) throw new UserError('Un turno no puede durar más de ' . ShiftPlanner::MAX_BLOCK_HOURS . ' horas.');
        $grace = ($in['grace_minutes'] ?? '') === '' ? ShiftPlanner::defaultGrace() : max(1, min(120, (int) $in['grace_minutes']));
        $realStart = self::localInput($in['started_at'] ?? '');
        $realEnd = self::localInput($in['ended_at'] ?? '');
        if ($realEnd !== null && $realStart === null) throw new UserError('Para cargar el fin real, cargá también el inicio real.');
        if ($realStart !== null && $realEnd !== null && $realEnd < $realStart) throw new UserError('El fin real es anterior al inicio real.');
        if ($realStart !== null && strtotime($realStart . ' UTC') > time() + 300) throw new UserError('El inicio real no puede ser en el futuro.');
        $note = mb_substr(trim((string) ($in['note'] ?? '')), 0, 500);
        $realChanged = $slot === null ? $realStart !== null : ($realStart !== $slot['started_at'] || $realEnd !== $slot['ended_at']);
        if ($realChanged && $note === '') throw new UserError('Explicá el motivo de la corrección del inicio o fin real (queda en la auditoría).');
        if ($slot !== null && $note === '') $note = 'Editado desde el panel';
        $status = match (true) {
            $realStart !== null && $realEnd !== null => 'cumplido',
            $realStart !== null => 'en_curso',
            ($slot['status'] ?? '') === 'justificado' => 'justificado',
            strtotime($end . ' UTC') <= time() => 'ausente',
            default => 'programado',
        };
        $endMode = $realEnd === null ? null : ($slot !== null && $realEnd === $slot['ended_at'] ? $slot['end_mode'] : 'manual');
        $db = DB::tenant();
        $me = (int) UserAuth::user()['id'];
        $data = ['user_id' => (int) $user['id'], 'site_id' => (int) $site['id'], 'start_at' => $start, 'end_at' => $end, 'grace_minutes' => $grace,
            'started_at' => $realStart, 'ended_at' => $realEnd, 'end_mode' => $endMode, 'status' => $status];
        if ($slot === null) {
            $uuid = Uuid::v4();
            $db->prepare("INSERT INTO guard_shift_slots (uuid, shift_id, user_id, site_id, start_at, end_at, grace_minutes, started_at, ended_at, end_mode, status,
                unscheduled, edited_by, edited_at, edit_note, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
                ->execute([$uuid, $data['user_id'], $data['site_id'], $start, $end, $grace, $realStart, $realEnd, $endMode, $status, $me, $note ?: 'Turno cargado desde el panel']);
            Audit::tenant('guard_shift_slot.create', 'guard_shift_slot', $uuid, null, $data + ['guardia' => $user['name'], 'predio' => $site['name'], 'motivo' => $note ?: null]);
            return self::find($uuid);
        }
        $cols = array_keys($data);
        $db->prepare('UPDATE guard_shift_slots SET ' . implode(', ', array_map(fn ($c) => "{$c} = ?", $cols)) . ', edited_by = ?, edited_at = UTC_TIMESTAMP(), edit_note = ?,
            updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute(array_merge(array_values($data), [$me, $note, (int) $slot['id']]));
        Audit::tenant('guard_shift_slot.update', 'guard_shift_slot', $slot['uuid'], array_intersect_key($slot, $data), $data + ['motivo' => $note]);
        return self::findById((int) $slot['id']);
    }

    /** Eliminar (anular) un turno: deja de contar en horas y avisos; queda el registro con el motivo. No se reactiva solo. */
    public static function adminCancel(array $slot, string $note): void
    {
        if (!UserAuth::can('rondas', 'editar')) throw new UserError('No tenés permiso para eliminar turnos.');
        $note = mb_substr(trim($note), 0, 500);
        if ($note === '') throw new UserError('Indicá el motivo.');
        if ($slot['status'] === 'anulado') return;
        foreach (self::exits((int) $slot['id']) as $e) {
            if ($e['returned_at'] === null) self::closeExit($e, gmdate('Y-m-d H:i:s'));
        }
        DB::tenant()->prepare("UPDATE guard_shift_slots SET status = 'anulado', edited_by = ?, edited_at = UTC_TIMESTAMP(), edit_note = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([(int) UserAuth::user()['id'], $note, (int) $slot['id']]);
        Audit::tenant('guard_shift_slot.delete', 'guard_shift_slot', $slot['uuid'], ['estado' => $slot['status'], 'inicio_real' => $slot['started_at'],
            'fin_real' => $slot['ended_at']], ['estado' => 'anulado', 'motivo' => $note]);
    }

    // ── operaciones de la app (idempotentes) ──────────────────────

    /** Hora del celular: no más de 2 días atrás ni 5 min en el futuro. */
    private static function deviceTime(mixed $v, string $what): string
    {
        $t = is_string($v) ? strtotime($v) : false;
        if ($t === false || $t > time() + 300 || $t < time() - 2 * 86400) throw new UserError("Hora inválida ({$what}).");
        return gmdate('Y-m-d H:i:s', $t);
    }

    private static function coord(array $d, string $k): ?float
    {
        return isset($d[$k]) && is_numeric($d[$k]) ? (float) $d[$k] : null;
    }

    private static function mySlot(string $uuid): array
    {
        $slot = self::find(strtolower($uuid));
        // En el celular compartido del puesto otro guardia del grupo puede terminar un turno que quedó abierto.
        if ($slot === null || !ActingGuard::canActFor((int) $slot['user_id'])) throw new UserError('No se encontró tu turno.');
        return $slot;
    }

    public static function start(array $d): array
    {
        if (!UserAuth::can('rondas', 'ver')) throw new UserError('Tu rol no usa turnos de guardia.');
        $me = (int) UserAuth::user()['id'];
        $at = self::deviceTime($d['started_at_device'] ?? null, 'inicio');
        $uuid = strtolower((string) ($d['uuid'] ?? ''));
        if (!Uuid::isValid($uuid)) throw new UserError('Falta el identificador del turno.');
        if (($existing = self::find($uuid)) !== null && (int) $existing['user_id'] === $me && $existing['status'] !== 'programado' && $existing['status'] !== 'justificado') {
            return self::shape($existing) + ['duplicate' => true];
        }
        $current = self::current($me);
        if ($current !== null && $current['uuid'] !== $uuid) throw new UserError('Ya tenés un turno en curso: terminalo antes de empezar otro.');
        $db = DB::tenant();
        $coverage = $existing === null ? PostCoverage::forStart($me, $d, $at) : null;
        if ($coverage !== null) { // turno por puesto: el guardia del grupo que llega toma la franja y releva al que estaba
            $relieved = PostCoverage::onDuty((int) $coverage['shift_id']);
            if ($relieved !== null && (int) $relieved['user_id'] !== $me) {
                self::close($relieved, max($at, (string) $relieved['started_at']), 'relevo');
                Audit::tenant('guard_shift.relief', 'guard_shift_slot', $relieved['uuid'], null, ['relevado_por' => UserAuth::user()['name']]);
                // Relevo entre franjas (el de la mañana releva al de la noche): la franja anterior se cierra y manda su reporte.
                if ($relieved['coverage_id'] && (int) $relieved['coverage_id'] !== (int) $coverage['id']) PostCoverage::closeIfDone((int) $relieved['coverage_id']);
            }
            $db->prepare("INSERT INTO guard_shift_slots (uuid, shift_id, coverage_id, user_id, site_id, start_at, end_at, grace_minutes, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'programado', UTC_TIMESTAMP(), UTC_TIMESTAMP())")
                ->execute([$uuid, (int) $coverage['shift_id'], (int) $coverage['id'], $me, (int) $coverage['site_id'], $at,
                    $coverage['end_at'], (int) $coverage['grace_minutes']]);
            PostCoverage::markStarted($coverage, $at);
            $slot = self::find($uuid);
        } elseif ($existing !== null) { // turno programado
            $slot = self::mySlot($uuid);
            $ts = strtotime($at . ' UTC');
            if ($ts < strtotime($slot['start_at'] . ' UTC') - self::EARLY_MINUTES * 60) throw new UserError('Todavía es temprano: el turno se puede empezar desde ' . self::EARLY_MINUTES . ' min antes.');
            if ($ts > strtotime($slot['end_at'] . ' UTC')) throw new UserError('Ese turno ya terminó.');
        } else { // turno no programado (cubre a alguien, horas extra)
            $site = \App\Models\Sites::findByUuid((string) ($d['site_uuid'] ?? ''));
            if ($site === null || (int) $site['is_active'] !== 1) throw new UserError('Elegí el predio donde empezás el turno.');
            $db->prepare("INSERT INTO guard_shift_slots (uuid, shift_id, user_id, site_id, start_at, end_at, grace_minutes, status, unscheduled, created_at, updated_at)
                VALUES (?, NULL, ?, ?, ?, ?, ?, 'programado', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
                ->execute([$uuid, $me, (int) $site['id'], $at, gmdate('Y-m-d H:i:s', strtotime($at . ' UTC') + self::UNSCHEDULED_HOURS * 3600), ShiftPlanner::defaultGrace()]);
            $slot = self::find($uuid);
        }
        $mock = !empty($d['mock']);
        $lat = self::coord($d, 'lat');
        $lng = self::coord($d, 'lng');
        $acc = self::coord($d, 'accuracy_m');
        $w = self::where($slot, $lat, $lng, $acc, $mock);
        $db->prepare("UPDATE guard_shift_slots SET status = 'en_curso', started_at = ?, started_received_at = UTC_TIMESTAMP(), start_lat = ?, start_lng = ?,
            start_accuracy_m = ?, start_distance_m = ?, start_inside = ?, start_mock = ?, last_contact_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([$at, $lat, $lng, $acc, $w['distance'], $w['inside'] === null ? null : ($w['inside'] ? 1 : 0), $mock ? 1 : 0, (int) $slot['id']]);
        $slot = self::findById((int) $slot['id']);
        Audit::tenant('guard_shift.start', 'guard_shift_slot', $slot['uuid'], null, ['predio' => $slot['site_name'], 'en_el_predio' => $w['inside'], 'distancia_m' => $w['distance']]);
        if (($w['inside'] === false || $mock) && self::enabled()) {
            Notifier::dispatch('guard.shift_start_outside', $slot + ['_kind' => $mock ? 'simulada' : 'lejos', 'distance_m' => $w['distance']]);
        }
        return self::shape($slot);
    }

    /**
     * Terminar el turno. En un puesto, `leave_empty` + `note` = "Dejar el puesto sin persona" (viene el encargado, el dueño…):
     * el hueco hasta el próximo guardia queda justificado y no avisa "relevo que no llegó".
     */
    public static function end(array $d): array
    {
        $slot = self::mySlot((string) ($d['slot_uuid'] ?? $d['uuid'] ?? ''));
        if ($slot['status'] === 'cumplido') return self::shape($slot) + ['duplicate' => true];
        if ($slot['status'] !== 'en_curso') throw new UserError('El turno no está en curso.');
        $at = self::deviceTime($d['ended_at_device'] ?? null, 'fin');
        if ($at < $slot['started_at']) $at = $slot['started_at'];
        $empty = !empty($d['leave_empty']);
        $note = mb_substr(trim((string) ($d['note'] ?? '')), 0, 255);
        if ($empty && mb_strlen($note) < 3) throw new UserError('Contá por qué el puesto queda sin persona (por ejemplo: "Llegó el encargado").');
        self::close($slot, $at, $empty ? 'sin_persona' : 'manual', $empty ? $note : null);
        Audit::tenant('guard_shift.end', 'guard_shift_slot', $slot['uuid'], null, $empty ? ['sin_persona' => $note] : null);
        if ($slot['coverage_id']) PostCoverage::closeIfDone((int) $slot['coverage_id']); // fin de la franja: el reporte sale ahora
        return self::shape(self::findById((int) $slot['id']));
    }

    private static function close(array $slot, string $at, string $mode, ?string $note = null): void
    {
        $db = DB::tenant();
        foreach (self::exits((int) $slot['id']) as $e) { // salida abierta: termina con el turno
            if ($e['returned_at'] === null) self::closeExit($e, max($at, $e['left_at']));
        }
        $db->prepare("UPDATE guard_shift_slots SET status = 'cumplido', ended_at = ?, end_mode = ?, end_note = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'en_curso'")
            ->execute([$at, $mode, $note, (int) $slot['id']]);
    }

    private static function closeExit(array $e, string $at): void
    {
        $seconds = max(0, strtotime($at . ' UTC') - strtotime($e['left_at'] . ' UTC'));
        $db = DB::tenant();
        $u = $db->prepare('UPDATE guard_shift_exits SET returned_at = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND returned_at IS NULL');
        $u->execute([$at, (int) $e['id']]);
        if ($u->rowCount() === 1) {
            $db->prepare('UPDATE guard_shift_slots SET out_seconds = out_seconds + ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$seconds, (int) $e['slot_id']]);
        }
    }

    private static function findExit(string $uuid): ?array
    {
        $q = DB::tenant()->prepare('SELECT * FROM guard_shift_exits WHERE uuid = ?');
        $q->execute([strtolower($uuid)]);
        return $q->fetch() ?: null;
    }

    /** Salió del predio (lo detecta la app). Puede venir con motivo ("Salgo por una emergencia") o sin él. */
    public static function exit(array $d): array
    {
        $slot = self::mySlot((string) ($d['slot_uuid'] ?? ''));
        $uuid = strtolower((string) ($d['uuid'] ?? ''));
        if (!Uuid::isValid($uuid)) throw new UserError('Falta el identificador de la salida.');
        if (($e = self::findExit($uuid)) !== null) return ['uuid' => $e['uuid'], 'duplicate' => true];
        if (!in_array($slot['status'], ['en_curso', 'cumplido'], true)) throw new UserError('El turno no está en curso.');
        $at = self::deviceTime($d['left_at_device'] ?? null, 'salida');
        $reason = isset(self::REASONS[$d['reason'] ?? '']) ? (string) $d['reason'] : null;
        $lat = self::coord($d, 'lat');
        $lng = self::coord($d, 'lng');
        $w = self::where($slot, $lat, $lng, self::coord($d, 'accuracy_m'));
        DB::tenant()->prepare('INSERT INTO guard_shift_exits (uuid, slot_id, left_at, reason, note, reason_at, announced, lat, lng, max_distance_m, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute([$uuid, (int) $slot['id'], $at, $reason, $reason ? (mb_substr(trim((string) ($d['note'] ?? '')), 0, 500) ?: null) : null,
                $reason ? gmdate('Y-m-d H:i:s') : null, !empty($d['announced']) ? 1 : 0, $lat, $lng, $w['distance']]);
        self::touch((int) $slot['id']);
        $e = self::findExit($uuid);
        if ($reason) self::notifyExit($slot, $e);
        if (!empty($d['returned_at_device'])) self::closeExit($e, max(self::deviceTime($d['returned_at_device'], 'vuelta'), $e['left_at']));
        return ['uuid' => $uuid];
    }

    public static function exitReason(array $d): array
    {
        $e = self::findExit((string) ($d['uuid'] ?? '')) ?? throw new UserError('No se encontró la salida.');
        $slot = self::findById((int) $e['slot_id']);
        if ((int) $slot['user_id'] !== (int) UserAuth::user()['id']) throw new UserError('No se encontró la salida.');
        $reason = isset(self::REASONS[$d['reason'] ?? '']) ? (string) $d['reason'] : throw new UserError('Elegí el motivo.');
        if ($e['reason'] !== null) return ['uuid' => $e['uuid'], 'duplicate' => true];
        DB::tenant()->prepare('UPDATE guard_shift_exits SET reason = ?, note = ?, reason_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$reason, mb_substr(trim((string) ($d['note'] ?? '')), 0, 500) ?: null, (int) $e['id']]);
        self::touch((int) $slot['id']);
        self::notifyExit($slot, self::findExit($e['uuid']));
        return ['uuid' => $e['uuid']];
    }

    public static function exitReturn(array $d): array
    {
        $e = self::findExit((string) ($d['uuid'] ?? '')) ?? throw new UserError('No se encontró la salida.');
        $slot = self::findById((int) $e['slot_id']);
        if ((int) $slot['user_id'] !== (int) UserAuth::user()['id']) throw new UserError('No se encontró la salida.');
        if ($e['returned_at'] !== null) return ['uuid' => $e['uuid'], 'duplicate' => true];
        self::closeExit($e, max(self::deviceTime($d['returned_at_device'] ?? null, 'vuelta'), $e['left_at']));
        self::touch((int) $slot['id']);
        return ['uuid' => $e['uuid']];
    }

    /** Posiciones mientras está fuera del predio (lotes; idempotente por uuid de cada posición). */
    public static function track(array $d): array
    {
        $slot = self::mySlot((string) ($d['slot_uuid'] ?? ''));
        $exit = !empty($d['exit_uuid']) ? self::findExit((string) $d['exit_uuid']) : null;
        if ($exit !== null && (int) $exit['slot_id'] !== (int) $slot['id']) $exit = null;
        $ins = DB::tenant()->prepare('INSERT IGNORE INTO guard_shift_positions (uuid, slot_id, exit_id, at, lat, lng, accuracy_m, distance_m, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        $n = 0;
        $max = (float) ($exit['max_distance_m'] ?? 0);
        foreach (array_slice((array) ($d['points'] ?? []), 0, 500) as $p) {
            if (!is_array($p) || !Uuid::isValid(strtolower((string) ($p['uuid'] ?? ''))) || !is_numeric($p['lat'] ?? null) || !is_numeric($p['lng'] ?? null)) continue;
            try { $at = self::deviceTime($p['at'] ?? null, 'posición'); } catch (UserError) { continue; }
            $w = self::where($slot, (float) $p['lat'], (float) $p['lng'], self::coord($p, 'accuracy_m'));
            $ins->execute([strtolower((string) $p['uuid']), (int) $slot['id'], $exit['id'] ?? null, $at, (float) $p['lat'], (float) $p['lng'], self::coord($p, 'accuracy_m'), $w['distance']]);
            $n += $ins->rowCount();
            $max = max($max, (float) $w['distance']);
        }
        if ($exit !== null) DB::tenant()->prepare('UPDATE guard_shift_exits SET max_distance_m = ? WHERE id = ?')->execute([$max, (int) $exit['id']]);
        self::touch((int) $slot['id']);
        return ['saved' => $n];
    }

    private static function touch(int $slotId): void
    {
        DB::tenant()->prepare('UPDATE guard_shift_slots SET last_contact_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$slotId]);
    }

    private static function notifyExit(array $slot, array $exit): void
    {
        if (self::enabled() && NotificationMarks::claim("shift_exit:{$exit['id']}")) {
            Notifier::dispatch('guard.shift_exit', $slot + ['exit' => $exit, 'reason_label' => self::REASONS[$exit['reason']] ?? '']);
        }
    }

    /** Respuesta para la app. */
    public static function shape(array $s): array
    {
        $iso = fn ($v) => $v ? str_replace(' ', 'T', $v) . 'Z' : null;
        return ['uuid' => $s['uuid'], 'status' => $s['status'], 'site_uuid' => $s['site_uuid'], 'site_name' => $s['site_name'],
            'site_lat' => $s['site_lat'] !== null ? (float) $s['site_lat'] : null, 'site_lng' => $s['site_lng'] !== null ? (float) $s['site_lng'] : null,
            'site_radius_m' => (int) ($s['site_radius'] ?: 200), 'start_at' => $iso($s['start_at']), 'end_at' => $iso($s['end_at']),
            'grace_minutes' => (int) $s['grace_minutes'], 'started_at' => $iso($s['started_at']), 'ended_at' => $iso($s['ended_at']),
            'start_inside' => $s['start_inside'] === null ? null : (bool) $s['start_inside'], 'unscheduled' => (bool) $s['unscheduled'],
            'name' => $s['shift_name'], 'open_exit_uuid' => $s['open_exit_uuid'] ?? null,
            'user_uuid' => $s['user_uuid'] ?? null, 'user_name' => $s['user_name'] ?? null, 'coverage_uuid' => $s['coverage_uuid'] ?? null,
            'end_mode' => $s['end_mode'] ?? null];
    }

    // ── cron ──────────────────────────────────────────────────────

    public static function run(?int $now = null): array
    {
        $now ??= time();
        $out = ['created' => ShiftPlanner::generate($now), 'not_started' => 0, 'missed' => 0, 'auto_end' => 0, 'exit_alarm' => 0, 'silent' => 0, 'purged' => 0];
        $notify = self::enabled();
        $nowSql = gmdate('Y-m-d H:i:s', $now);
        $db = DB::tenant();
        $previous = UserAuth::user();
        UserAuth::setCurrent(null);
        try {
            // Sin empezar / no vino
            $q = $db->prepare("SELECT id FROM guard_shift_slots WHERE status = 'programado' AND start_at <= ?");
            $q->execute([$nowSql]);
            foreach ($q->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                $s = self::findById((int) $id);
                $start = strtotime($s['start_at'] . ' UTC');
                if ($now >= strtotime($s['end_at'] . ' UTC')) {
                    $db->prepare("UPDATE guard_shift_slots SET status = 'ausente', updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'programado'")->execute([(int) $id]);
                    $out['missed']++;
                    if ($notify && NotificationMarks::claim("shift_missed:{$id}")) Notifier::dispatch('guard.shift_missed', $s);
                } elseif ($now >= $start + 2 * (int) $s['grace_minutes'] * 60 && NotificationMarks::claim("shift_late:{$id}")) {
                    $out['not_started']++;
                    if ($notify) Notifier::dispatch('guard.shift_not_started', $s + ['late_minutes' => intdiv($now - $start, 60)]);
                }
            }
            // En curso: cierre automático, salida sin motivo, sin contacto
            foreach ($db->query("SELECT id FROM guard_shift_slots WHERE status = 'en_curso'")->fetchAll(\PDO::FETCH_COLUMN) as $id) {
                $s = self::findById((int) $id);
                if ($now >= strtotime($s['end_at'] . ' UTC') + self::autoEndMinutes() * 60) {
                    self::close($s, $s['end_at'] > $s['started_at'] ? $s['end_at'] : $s['started_at'], 'auto');
                    $out['auto_end']++;
                    if ($notify && NotificationMarks::claim("shift_no_end:{$id}")) Notifier::dispatch('guard.shift_no_end', $s);
                    continue;
                }
                foreach (self::exits((int) $id) as $e) {
                    if ($e['returned_at'] === null && $e['reason'] === null && $now >= strtotime($e['left_at'] . ' UTC') + self::noReasonMinutes() * 60
                        && NotificationMarks::claim("shift_exit_alarm:{$e['id']}")) {
                        $out['exit_alarm']++;
                        if ($notify) Notifier::dispatch('guard.shift_exit_alarm', $s + ['exit' => $e]);
                    }
                }
                $device = DeviceStatus::latestForUser((int) $s['user_id']);
                $last = max(strtotime(($s['last_contact_at'] ?? $s['started_received_at'] ?? $s['started_at']) . ' UTC'),
                    $device ? strtotime($device['received_at'] . ' UTC') : 0);
                if ($now - $last >= self::silentMinutes() * 60 && NotificationMarks::claim("shift_silent:{$id}:{$last}")) {
                    $out['silent']++;
                    if ($notify) Notifier::dispatch('guard.shift_silent', $s + ['since' => gmdate('Y-m-d H:i:s', $last), '_kind' => 'contacto']);
                } elseif ($device && empty($device['gps']) && !empty($device['gps_off_since'])
                    && $now - strtotime($device['gps_off_since'] . ' UTC') >= DeviceStatus::GPS_OFF_MINUTES * 60
                    && strtotime($device['gps_off_since'] . ' UTC') >= strtotime($s['started_at'] . ' UTC') - 60
                    && NotificationMarks::claim("shift_gps:{$id}:" . strtotime($device['gps_off_since'] . ' UTC'))) {
                    $out['silent']++;
                    if ($notify) Notifier::dispatch('guard.shift_silent', $s + ['since' => $device['gps_off_since'], '_kind' => 'gps']);
                }
            }
            $out += PostCoverage::run($now);
            $out['purged'] = self::purge($now);
        } finally {
            UserAuth::setCurrent($previous);
        }
        return $out;
    }

    /** Borra las posiciones detalladas viejas (turnos y rondas). Las horas, salidas y motivos quedan. */
    public static function purge(?int $now = null): int
    {
        $limit = gmdate('Y-m-d H:i:s', ($now ?? time()) - self::retentionDays() * 86400);
        $a = DB::tenant()->prepare('DELETE FROM guard_shift_positions WHERE created_at < ? LIMIT 5000');
        $a->execute([$limit]);
        $b = DB::tenant()->prepare('DELETE FROM patrol_tracks WHERE created_at < ? LIMIT 5000');
        $b->execute([$limit]);
        return $a->rowCount() + $b->rowCount();
    }
}
