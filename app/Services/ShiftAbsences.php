<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\UserError;
use App\Core\Uuid;
use App\Models\Users;
use App\Services\Notify\Notifier;

/**
 * Ausencias de guardias y días sin servicio. El guardia pide (app o web) → el supervisor aprueba o rechaza; el supervisor
 * también puede cargarla aprobada directamente. Aprobada: los turnos del rango quedan "justificado" (sin alarmas), con
 * reemplazo se crea el turno del reemplazo; sin reemplazo, un solo aviso "Puesto sin cubrir".
 */
final class ShiftAbsences
{
    public const TYPES = ['franco' => 'Franco / cambio de turno', 'enfermedad' => 'Enfermedad', 'vacaciones' => 'Vacaciones',
        'licencia' => 'Licencia', 'personal' => 'Motivo personal', 'otro' => 'Otro'];
    public const STATUS = ['solicitada' => ['Pendiente de aprobar', 'warning'], 'aprobada' => ['Aprobada', 'success'],
        'rechazada' => ['Rechazada', 'danger'], 'cancelada' => ['Cancelada', 'secondary']];

    public static function canManage(): bool
    {
        return UserAuth::can('rondas', 'editar');
    }

    /** @return array{0: array, 1: array<string,string>} */
    public static function validate(array $in, bool $forOther): array
    {
        $errors = [];
        $date = fn ($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) && strtotime((string) $v) !== false ? (string) $v : null;
        $from = $date($in['date_from'] ?? '');
        $to = $date($in['date_to'] ?? '') ?? $from;
        if ($from === null) $errors['date_from'] = 'Indicá desde qué día.';
        elseif ($to < $from) $errors['date_to'] = 'La fecha "hasta" es anterior a "desde".';
        elseif ((strtotime($to) - strtotime($from)) / 86400 > 366) $errors['date_to'] = 'Máximo un año.';
        $type = isset(self::TYPES[$in['type'] ?? '']) ? (string) $in['type'] : null;
        if ($type === null) $errors['type'] = 'Elegí el tipo.';
        $user = $forOther ? Users::findByUuid((string) ($in['user'] ?? '')) : UserAuth::user();
        if ($user === null) $errors['user'] = 'Elegí el guardia.';
        $replacement = null;
        if ($forOther && !empty($in['replacement'])) {
            $replacement = Users::findByUuid((string) $in['replacement']);
            if ($replacement === null || (int) $replacement['is_active'] !== 1) $errors['replacement'] = 'El reemplazo no existe o está desactivado.';
            elseif ($user && (int) $replacement['id'] === (int) $user['id']) $errors['replacement'] = 'El reemplazo tiene que ser otra persona.';
        }
        return [['user_id' => $user ? (int) $user['id'] : null, 'date_from' => $from, 'date_to' => $to, 'type' => $type,
            'reason' => mb_substr(trim((string) ($in['reason'] ?? '')), 0, 500) ?: null,
            'replacement_user_id' => $replacement ? (int) $replacement['id'] : null], $errors];
    }

    /** El guardia pide una ausencia (idempotente por uuid: la app la puede mandar sin señal). */
    public static function request(array $in, ?string $uuid = null): array
    {
        [$data, $errors] = self::validate($in, false);
        if ($errors) throw new UserError(implode(' ', $errors));
        if ($uuid !== null && ($existing = self::find($uuid)) !== null) return $existing + ['duplicate' => true];
        $row = self::insert($data + ['status' => 'solicitada'], $uuid);
        Audit::tenant('guard_absence.request', 'guard_absence', $row['uuid'], null, $data);
        Notifier::dispatch('guard.absence_requested', self::subject($row));
        return $row;
    }

    /** El supervisor la carga aprobada. */
    public static function createApproved(array $in): array
    {
        if (!self::canManage()) throw new UserError('No tenés permiso para cargar ausencias.');
        [$data, $errors] = self::validate($in, true);
        if ($errors) throw new UserError(implode(' ', $errors));
        $row = self::insert($data + ['status' => 'aprobada', 'decided_by' => (int) UserAuth::user()['id']]);
        Audit::tenant('guard_absence.create', 'guard_absence', $row['uuid'], null, $data);
        self::apply($row);
        return $row;
    }

    public static function decide(array $absence, bool $approve, ?string $note = null, ?string $replacementUuid = null): array
    {
        if (!self::canManage()) throw new UserError('No tenés permiso para aprobar ausencias.');
        if ($absence['status'] !== 'solicitada') throw new UserError('Esta ausencia ya fue respondida.');
        $replacement = $replacementUuid ? Users::findByUuid($replacementUuid) : null;
        if ($replacement && (int) $replacement['id'] === (int) $absence['user_id']) throw new UserError('El reemplazo tiene que ser otra persona.');
        DB::tenant()->prepare('UPDATE guard_absences SET status = ?, decided_by = ?, decided_at = UTC_TIMESTAMP(), decision_note = ?, replacement_user_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$approve ? 'aprobada' : 'rechazada', (int) UserAuth::user()['id'], mb_substr((string) $note, 0, 500) ?: null,
                $replacement ? (int) $replacement['id'] : null, (int) $absence['id']]);
        $row = self::findById((int) $absence['id']);
        Audit::tenant($approve ? 'guard_absence.approve' : 'guard_absence.reject', 'guard_absence', $row['uuid'], ['status' => 'solicitada'], ['status' => $row['status'], 'nota' => $note]);
        if ($approve) self::apply($row);
        Notifier::dispatch('guard.absence_decided', self::subject($row));
        return $row;
    }

    /** Cancelar (el guardia la suya pendiente, o el supervisor cualquiera): los turnos futuros vuelven a programado. */
    public static function cancel(array $absence): void
    {
        $mine = (int) $absence['user_id'] === (int) (UserAuth::user()['id'] ?? 0) && $absence['status'] === 'solicitada';
        if (!$mine && !self::canManage()) throw new UserError('No podés cancelar esta ausencia.');
        if (!in_array($absence['status'], ['solicitada', 'aprobada'], true)) throw new UserError('La ausencia ya no está vigente.');
        $db = DB::tenant();
        $db->prepare("UPDATE guard_absences SET status = 'cancelada', updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([(int) $absence['id']]);
        self::revert((int) $absence['id']);
        Audit::tenant('guard_absence.cancel', 'guard_absence', $absence['uuid'], ['status' => $absence['status']], ['status' => 'cancelada']);
    }

    /**
     * El supervisor corrige una ausencia pendiente o aprobada (guardia, fechas, tipo, motivo, reemplazo). Si estaba
     * aprobada, los turnos futuros se rearman con los datos nuevos; lo que ya pasó no se toca.
     */
    public static function update(array $absence, array $in): array
    {
        if (!self::canManage()) throw new UserError('No tenés permiso para editar ausencias.');
        if (!in_array($absence['status'], ['solicitada', 'aprobada'], true)) throw new UserError('La ausencia ya no está vigente: no se puede editar.');
        [$data, $errors] = self::validate($in, true);
        if ($errors) throw new UserError(implode(' ', $errors));
        $db = DB::tenant();
        $db->beginTransaction();
        try {
            self::revert((int) $absence['id']);
            $db->prepare('UPDATE guard_absences SET user_id = ?, date_from = ?, date_to = ?, type = ?, reason = ?, replacement_user_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([$data['user_id'], $data['date_from'], $data['date_to'], $data['type'], $data['reason'], $data['replacement_user_id'], (int) $absence['id']]);
            $row = self::findById((int) $absence['id']);
            if ($row['status'] === 'aprobada') self::apply($row);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        $keys = ['user_id', 'date_from', 'date_to', 'type', 'reason', 'replacement_user_id'];
        Audit::tenant('guard_absence.update', 'guard_absence', $absence['uuid'], array_intersect_key($absence, array_flip($keys)), $data);
        return $row;
    }

    /** Deshace lo que aplicó una ausencia en los turnos que todavía no empezaron: vuelven a programado; los del reemplazo se anulan. */
    private static function revert(int $absenceId): void
    {
        $db = DB::tenant();
        $db->prepare("UPDATE guard_shift_slots SET status = 'anulado', updated_at = UTC_TIMESTAMP() WHERE replaces_slot_id IN
            (SELECT id FROM (SELECT id FROM guard_shift_slots WHERE absence_id = ?) x) AND status = 'programado' AND start_at > UTC_TIMESTAMP()")->execute([$absenceId]);
        $db->prepare("UPDATE guard_shift_slots SET status = 'programado', absence_id = NULL, updated_at = UTC_TIMESTAMP()
            WHERE absence_id = ? AND status = 'justificado' AND start_at > UTC_TIMESTAMP()")->execute([$absenceId]);
    }

    /**
     * Aplica una ausencia aprobada: justifica los turnos del rango (pendientes), crea los del reemplazo y si no hay
     * reemplazo avisa una vez "Puesto sin cubrir".
     */
    public static function apply(array $absence): void
    {
        $db = DB::tenant();
        [$from] = ShiftPlanner::dayRange($absence['date_from']);
        [, $to] = ShiftPlanner::dayRange($absence['date_to']);
        $db->prepare("UPDATE guard_shift_slots SET status = 'justificado', absence_id = ?, updated_at = UTC_TIMESTAMP()
            WHERE user_id = ? AND status = 'programado' AND start_at >= ? AND start_at < ?")
            ->execute([(int) $absence['id'], (int) $absence['user_id'], $from, $to]);
        $q = $db->prepare("SELECT * FROM guard_shift_slots WHERE absence_id = ? AND status = 'justificado' ORDER BY start_at");
        $q->execute([(int) $absence['id']]);
        $slots = $q->fetchAll();
        if ($absence['replacement_user_id']) {
            $exists = $db->prepare('SELECT 1 FROM guard_shift_slots WHERE replaces_slot_id = ? AND user_id = ? AND status <> \'anulado\'');
            $ins = $db->prepare("INSERT INTO guard_shift_slots (uuid, shift_id, user_id, site_id, start_at, end_at, grace_minutes, status, replaces_slot_id, created_at, updated_at)
                VALUES (?, NULL, ?, ?, ?, ?, ?, 'programado', ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
            foreach ($slots as $s) {
                $exists->execute([(int) $s['id'], (int) $absence['replacement_user_id']]);
                if ($exists->fetchColumn()) continue;
                $ins->execute([Uuid::v4(), (int) $absence['replacement_user_id'], (int) $s['site_id'], $s['start_at'], $s['end_at'], (int) $s['grace_minutes'], (int) $s['id']]);
            }
        } elseif ($slots && \App\Models\NotificationMarks::claim("shift_uncovered:{$absence['id']}")) {
            Notifier::dispatch('guard.shift_uncovered', self::subject($absence) + ['slots' => count($slots),
                'first_start' => $slots[0]['start_at'], 'site_name' => self::siteName((int) $slots[0]['site_id'])]);
        }
    }

    /** Ausencia aprobada de un guardia que cubre ese día local. */
    public static function approvedFor(int $userId, string $day): ?array
    {
        $q = DB::tenant()->prepare("SELECT * FROM guard_absences WHERE user_id = ? AND status = 'aprobada' AND date_from <= ? AND date_to >= ? LIMIT 1");
        $q->execute([$userId, $day, $day]);
        return $q->fetch() ?: null;
    }

    /** ¿Todos esos guardias están ausentes (aprobado) en ese momento UTC? (rondas programadas sin alarmas) */
    public static function allAbsentAt(array $userIds, string $utc): bool
    {
        if (!$userIds) return false;
        $day = ShiftPlanner::localDay($utc);
        foreach ($userIds as $id) {
            if (self::approvedFor((int) $id, $day) === null) return false;
        }
        return true;
    }

    public static function find(string $uuid): ?array
    {
        $q = DB::tenant()->prepare(self::SELECT . ' WHERE a.uuid = ?');
        $q->execute([$uuid]);
        return $q->fetch() ?: null;
    }

    public static function findById(int $id): ?array
    {
        $q = DB::tenant()->prepare(self::SELECT . ' WHERE a.id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    /** @param ?string $status null = todas; 'vigentes' = pendientes + aprobadas que no terminaron */
    public static function list(?string $status = null, ?int $userId = null, int $limit = 200): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($status === 'vigentes') { $where[] = "a.status IN ('solicitada', 'aprobada') AND a.date_to >= ?"; $params[] = ShiftPlanner::localDay(gmdate('Y-m-d H:i:s')); }
        elseif ($status !== null) { $where[] = 'a.status = ?'; $params[] = $status; }
        if ($userId !== null) { $where[] = 'a.user_id = ?'; $params[] = $userId; }
        $q = DB::tenant()->prepare(self::SELECT . ' WHERE ' . implode(' AND ', $where) . " ORDER BY a.status = 'solicitada' DESC, a.date_from DESC LIMIT " . $limit);
        $q->execute($params);
        return $q->fetchAll();
    }

    private const SELECT = 'SELECT a.*, u.name AS user_name, u.uuid AS user_uuid, r.name AS replacement_name, r.uuid AS replacement_uuid, d.name AS decided_by_name
        FROM guard_absences a JOIN users u ON u.id = a.user_id LEFT JOIN users r ON r.id = a.replacement_user_id LEFT JOIN users d ON d.id = a.decided_by';

    private static function insert(array $data, ?string $uuid = null): array
    {
        $uuid ??= Uuid::v4();
        $data += ['decided_by' => null];
        DB::tenant()->prepare('INSERT INTO guard_absences (uuid, user_id, date_from, date_to, type, reason, status, replacement_user_id, requested_by, decided_by, decided_at, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? IS NULL, NULL, UTC_TIMESTAMP()), UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute([$uuid, $data['user_id'], $data['date_from'], $data['date_to'], $data['type'], $data['reason'], $data['status'],
                $data['replacement_user_id'], (int) (UserAuth::user()['id'] ?? 0) ?: null, $data['decided_by'], $data['decided_by']]);
        return self::find($uuid);
    }

    /** Datos para los avisos (el guardia es el "reporter": recibe la respuesta). */
    private static function subject(array $a): array
    {
        $a = self::findById((int) $a['id']) ?? $a;
        return $a + ['reporter_user_id' => (int) $a['user_id'], 'type_label' => self::TYPES[$a['type']] ?? $a['type']];
    }

    private static function siteName(int $siteId): string
    {
        $q = DB::tenant()->prepare('SELECT name FROM sites WHERE id = ?');
        $q->execute([$siteId]);
        return (string) $q->fetchColumn();
    }

    // ── días sin servicio ────────────────────────────────────────

    public static function closuresList(): array
    {
        return DB::tenant()->query('SELECT c.*, s.name AS site_name FROM guard_closures c LEFT JOIN sites s ON s.id = c.site_id
            WHERE c.day >= CURDATE() - INTERVAL 30 DAY ORDER BY c.day')->fetchAll();
    }

    public static function addClosure(?int $siteId, string $day, string $reason): void
    {
        if (!self::canManage()) throw new UserError('No tenés permiso.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) throw new UserError('Fecha inválida.');
        $reason = mb_substr(trim($reason), 0, 191);
        if ($reason === '') throw new UserError('Indicá el motivo (ej. feriado, planta cerrada).');
        $uuid = Uuid::v4();
        DB::tenant()->prepare('INSERT INTO guard_closures (uuid, site_id, day, reason, created_by, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$uuid, $siteId, $day, $reason, (int) UserAuth::user()['id']]);
        [$from, $to] = ShiftPlanner::dayRange($day);
        DB::tenant()->prepare("UPDATE guard_shift_slots SET status = 'anulado', updated_at = UTC_TIMESTAMP() WHERE status IN ('programado', 'justificado')
            AND start_at >= ? AND start_at < ?" . ($siteId ? ' AND site_id = ' . $siteId : ''))->execute([$from, $to]);
        Audit::tenant('guard_closure.create', 'guard_closure', $uuid, null, ['dia' => $day, 'motivo' => $reason, 'site_id' => $siteId]);
    }

    public static function removeClosure(string $uuid): void
    {
        if (!self::canManage()) throw new UserError('No tenés permiso.');
        $q = DB::tenant()->prepare('SELECT * FROM guard_closures WHERE uuid = ?');
        $q->execute([$uuid]);
        $c = $q->fetch();
        if (!$c) return;
        DB::tenant()->prepare('DELETE FROM guard_closures WHERE id = ?')->execute([(int) $c['id']]);
        Audit::tenant('guard_closure.delete', 'guard_closure', $uuid, ['dia' => $c['day'], 'motivo' => $c['reason']], null);
        ShiftPlanner::generate();
    }
}
