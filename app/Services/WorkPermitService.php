<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Tenant;
use App\Core\UserError;
use App\Core\Uuid;
use App\Models\Contractors;
use App\Models\Employees;
use App\Models\Equipment;
use App\Models\InspectionTemplates;
use App\Models\Sectors;
use App\Models\Sequences;
use App\Models\Settings;
use App\Models\WorkPermits;
use App\Services\Notify\Notifier;

/**
 * Permisos de trabajo: solicitar (checklists por tipo + firma) → autorizar (otra persona, con permiso "aprobar",
 * firma) → iniciar (firma de cada ejecutor) → cerrar (firma). Vencen solos por el cron. Lo autorizado queda
 * congelado (original_data + hash) y las firmas son PNG inmutables con SHA-256.
 */
final class WorkPermitService
{
    public const MODULE = 'permisos_trabajo';

    public const TYPES = [
        'altura'            => ['label' => 'Trabajo en altura', 'short' => 'Altura', 'class' => 'primary'],
        'caliente'          => ['label' => 'Trabajo en caliente', 'short' => 'Caliente', 'class' => 'danger'],
        'espacio_confinado' => ['label' => 'Espacio confinado', 'short' => 'Confinado', 'class' => 'dark'],
        'loto'              => ['label' => 'Bloqueo y etiquetado (LOTO)', 'short' => 'LOTO', 'class' => 'warning'],
        'electrico'         => ['label' => 'Trabajo eléctrico', 'short' => 'Eléctrico', 'class' => 'info'],
    ];

    public const STATES = [
        'solicitado'   => ['label' => 'Para autorizar', 'class' => 'warning'],
        'aprobado'     => ['label' => 'Autorizado', 'class' => 'primary'],
        'rechazado'    => ['label' => 'Rechazado', 'class' => 'secondary'],
        'en_ejecucion' => ['label' => 'En ejecución', 'class' => 'success'],
        'suspendido'   => ['label' => 'Suspendido', 'class' => 'danger'],
        'cerrado'      => ['label' => 'Cerrado', 'class' => 'dark'],
        'vencido'      => ['label' => 'Vencido', 'class' => 'danger'],
        'cancelado'    => ['label' => 'Cancelado', 'class' => 'secondary'],
    ];

    public const SIGNATURE_ROLES = ['solicitante' => 'Solicitante', 'autorizante' => 'Autorizante', 'ejecutor' => 'Ejecutor', 'vigia' => 'Vigía',
        'cierre' => 'Cierre', 'recepcion' => 'Recepción del área', 'extension' => 'Extensión'];

    /** Margen para iniciar antes de la hora de inicio (llegar, preparar). */
    private const START_EARLY_MINUTES = 30;

    public static function maxHours(): int
    {
        return max(1, min(24, (int) Settings::get('permisos.max_horas', '12')));
    }

    public static function scope(): ?array
    {
        $user = UserAuth::user();
        return match (UserAuth::scope(self::MODULE)) {
            'propios'  => ['own' => (int) ($user['id'] ?? 0)],
            'sectores' => ['sector_ids' => SectorScope::sectorIds(self::MODULE) ?? [], 'user_id' => (int) ($user['id'] ?? 0)],
            null       => ['own' => 0],
            default    => null,
        };
    }

    public static function canView(array $p): bool
    {
        $scope = self::scope();
        if ($scope === null || self::isRequester($p) || (int) ($p['approved_by'] ?? 0) === self::me()) {
            return true;
        }
        return isset($scope['sector_ids']) && in_array((int) $p['sector_id'], $scope['sector_ids'], true);
    }

    public static function isRequester(array $p): bool
    {
        return self::me() > 0 && (int) $p['requested_by'] === self::me();
    }

    /** Autoriza quien tiene "aprobar" y no es el solicitante. */
    public static function canApprove(array $p): bool
    {
        return UserAuth::can(self::MODULE, 'aprobar') && !self::isRequester($p) && self::canView($p);
    }

    /** Iniciar, cerrar o cancelar: el solicitante o quien tenga "cerrar" en su alcance. */
    public static function canOperate(array $p): bool
    {
        return self::canView($p) && (self::isRequester($p) || UserAuth::can(self::MODULE, 'cerrar'));
    }

    /** Checklist previo vigente de cada tipo: tipo => plantilla (con su estructura). */
    public static function checklistTemplates(): array
    {
        $out = [];
        foreach (array_keys(self::TYPES) as $type) {
            $t = InspectionTemplates::forPermitType($type);
            if ($t !== null) {
                $out[$type] = $t + ['version' => InspectionTemplates::version((int) $t['current_version_id'])];
            }
        }
        return $out;
    }

    // ── solicitar ───────────────────────────────────────────────────

    /**
     * @param array $in types[], sector, equipment, location_text, task, contractor, valid_from, valid_until (locales o ISO),
     *   workers [{employee | external_name + external_dni, role ejecutor|vigia}], checklists {tipo: {item_key: {value, comment}}},
     *   signature (data URL), lat, lng
     * @param array $meta ip, user_agent
     * @return array{permit: ?array, errors: array<string,string>}
     */
    public static function request(array $in, array $meta = []): array
    {
        if (!UserAuth::can(self::MODULE, 'crear')) {
            return ['permit' => null, 'errors' => ['_' => 'Tu rol no puede solicitar permisos de trabajo.']];
        }
        $errors = [];
        $types = array_values(array_intersect(array_keys(self::TYPES), (array) ($in['types'] ?? [])));
        if (!$types) {
            $errors['types'] = 'Elegí al menos un tipo de trabajo.';
        }
        $sector = ($in['sector'] ?? '') !== '' ? Sectors::findByUuid((string) $in['sector']) : null;
        $equipment = ($in['equipment'] ?? '') !== '' ? Equipment::findByUuid((string) $in['equipment']) : null;
        if ($sector === null && $equipment && $equipment['sector_id']) {
            $sector = Sectors::findById((int) $equipment['sector_id']);
        }
        if ($sector === null) {
            $errors['sector'] = 'Indicá el sector donde se trabaja.';
        }
        $task = trim((string) ($in['task'] ?? ''));
        if (mb_strlen($task) < 10) {
            $errors['task'] = 'Describí la tarea (mínimo 10 caracteres).';
        }
        $contractor = ($in['contractor'] ?? '') !== '' ? Contractors::findByUuid((string) $in['contractor']) : null;
        $from = self::parseTime($in['valid_from'] ?? null);
        $until = self::parseTime($in['valid_until'] ?? null);
        if ($from === null || $until === null) {
            $errors['valid'] = 'Indicá el inicio y el fin del trabajo.';
        } elseif ($until <= $from) {
            $errors['valid'] = 'El fin tiene que ser posterior al inicio.';
        } elseif (strtotime($until) - strtotime($from) > self::maxHours() * 3600) {
            $errors['valid'] = 'Un permiso dura como máximo ' . self::maxHours() . ' horas (después se puede extender una vez).';
        } elseif (strtotime($until) < time()) {
            $errors['valid'] = 'La ventana del permiso ya terminó.';
        } elseif (strtotime($from) < time() - 3600) {
            $errors['valid'] = 'El inicio no puede ser de hace más de una hora.';
        }
        $workers = [];
        foreach ((array) ($in['workers'] ?? []) as $w) {
            $emp = ($w['employee'] ?? '') !== '' ? Employees::findByUuid((string) $w['employee']) : null;
            $name = trim((string) ($w['external_name'] ?? ''));
            if ($emp === null && $name === '') {
                continue;
            }
            $key = $emp ? 'e' . $emp['id'] : 'x' . mb_strtolower($name);
            $workers[$key] = ['role' => ($w['role'] ?? '') === 'vigia' ? 'vigia' : 'ejecutor', 'employee_id' => $emp ? (int) $emp['id'] : null,
                'external_name' => $emp ? null : mb_substr($name, 0, 160), 'external_dni' => $emp ? null : (preg_replace('/\D/', '', (string) ($w['external_dni'] ?? '')) ?: null),
                '_label' => $emp ? $emp['last_name'] . ', ' . $emp['first_name'] : $name];
        }
        if (!$workers) {
            $errors['workers'] = 'Indicá quiénes van a hacer el trabajo.';
        }
        // Checklist previo de cada tipo (evaluado acá, nunca en el cliente)
        $templates = self::checklistTemplates();
        $checklists = [];
        $criticalFails = 0;
        foreach ($types as $type) {
            if (!isset($templates[$type])) {
                $errors['checklist_' . $type] = 'No hay checklist cargado para "' . self::TYPES[$type]['label'] . '": cargá los precargados en Inspecciones → Plantillas.';
                continue;
            }
            $eval = InspectionStructure::evaluate($templates[$type]['version']['structure'], (array) ($in['checklists'][$type] ?? []));
            foreach ($eval['errors'] as $key => $msg) {
                $errors[$type . ':' . $key] = self::TYPES[$type]['short'] . ': ' . $msg;
            }
            $checklists[$type] = [$templates[$type]['version'], $eval];
            $criticalFails += $eval['critical_fail'];
        }
        if ($errors) {
            return ['permit' => null, 'errors' => $errors];
        }
        try {
            $signature = Signatures::store((string) ($in['signature'] ?? ''), 'permits');
        } catch (UserError $e) {
            return ['permit' => null, 'errors' => ['signature' => $e->getMessage()]];
        }
        $user = UserAuth::user();
        $db = DB::tenant();
        $db->beginTransaction();
        try {
            $number = Sequences::next('work_permits');
            $original = [
                'numero' => WorkPermits::format($number), 'tipos' => array_map(fn ($t) => self::TYPES[$t]['label'], $types),
                'sector' => Sectors::labelMap()[(int) $sector['id']]['label'] ?? $sector['name'],
                'equipo' => $equipment ? $equipment['code'] . ' · ' . $equipment['name'] : null,
                'lugar' => trim((string) ($in['location_text'] ?? '')) ?: null, 'tarea' => $task, 'contratista' => $contractor['name'] ?? null,
                'desde' => $from, 'hasta' => $until, 'solicitante' => $user['name'] ?? null,
                'ejecutores' => array_values(array_map(fn ($w) => $w['_label'] . ' (' . $w['role'] . ')', $workers)),
                'checklists' => array_map(fn ($c) => array_map(fn ($r) => ['item' => $r['text'], 'respuesta' => $r['value'], 'cumple' => $r['ok'],
                    'critico' => $r['critical'], 'comentario' => $r['comment']], $c[1]['rows']), $checklists),
            ];
            $json = json_encode($original, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $id = WorkPermits::create(array_filter([
                'number' => $number, 'types' => json_encode($types), 'status' => 'solicitado',
                'site_id' => (int) $sector['site_id'], 'sector_id' => (int) $sector['id'], 'equipment_id' => $equipment ? (int) $equipment['id'] : null,
                'location_text' => $original['lugar'] ? mb_substr($original['lugar'], 0, 191) : null,
                'lat' => is_numeric($in['lat'] ?? null) ? (float) $in['lat'] : null, 'lng' => is_numeric($in['lng'] ?? null) ? (float) $in['lng'] : null,
                'task' => $task, 'contractor_id' => $contractor ? (int) $contractor['id'] : null, 'valid_from' => $from, 'valid_until' => $until,
                'requested_by' => $user['id'] ?? null, 'critical_fails' => $criticalFails, 'original_data' => $json, 'original_hash' => hash('sha256', $json),
            ], fn ($v) => $v !== null));
            foreach ($workers as $w) {
                unset($w['_label']);
                WorkPermits::addWorker($id, $w);
            }
            foreach ($checklists as $type => [$version, $eval]) {
                WorkPermits::addChecklist($id, $type, (int) $version['id'], $eval['rows'], $eval['fail'], $eval['critical_fail']);
            }
            WorkPermits::addSignature($id, $signature + self::signer('solicitante', $meta, $in));
            WorkPermits::addEvent($id, 'created', ['to' => 'solicitado', 'data' => ['criticos_sin_cumplir' => $criticalFails]] + self::actor());
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        $permit = WorkPermits::findById($id);
        Audit::tenant('permit.request', 'work_permit', $permit['uuid'], null, ['numero' => WorkPermits::format($number), 'tipos' => $types]);
        Notifier::dispatch('permit.requested', $permit);
        return ['permit' => $permit, 'errors' => []];
    }

    // ── autorizar, rechazar, iniciar, cerrar, cancelar ──────────────

    public static function approve(array $p, string $signature, array $meta = [], string $comment = ''): ?string
    {
        if (!UserAuth::can(self::MODULE, 'aprobar')) {
            return 'No tenés permiso para autorizar permisos de trabajo.';
        }
        if (self::isRequester($p)) {
            return 'No podés autorizar un permiso que solicitaste vos.';
        }
        if ($p['status'] !== 'solicitado') {
            return 'El permiso ya no está para autorizar.';
        }
        if ((int) $p['critical_fails'] > 0) {
            return 'Hay ' . $p['critical_fails'] . ' ítem(s) crítico(s) del checklist sin cumplir: no se puede autorizar. Rechazalo para que se corrija.';
        }
        if (strtotime($p['ends_at']) < time()) {
            return 'La ventana del permiso ya terminó.';
        }
        return self::change($p, 'aprobado', ['approved_by' => self::me(), 'approved_at' => gmdate('Y-m-d H:i:s')], $comment ?: null, 'autorizante', $signature, $meta,
            fn ($fresh) => Notifier::dispatch('permit.decided', $fresh, ['decision' => 'aprobado']));
    }

    public static function reject(array $p, string $reason): ?string
    {
        if (!UserAuth::can(self::MODULE, 'aprobar') || self::isRequester($p)) {
            return 'No tenés permiso para rechazar este permiso.';
        }
        if ($p['status'] !== 'solicitado') {
            return 'El permiso ya no está para autorizar.';
        }
        if (mb_strlen(trim($reason)) < 5) {
            return 'Escribí qué hay que corregir (mínimo 5 caracteres).';
        }
        return self::change($p, 'rechazado', ['status_reason' => trim($reason)], trim($reason), null, null, [],
            fn ($fresh) => Notifier::dispatch('permit.decided', $fresh, ['decision' => 'rechazado', 'comment' => trim($reason)]));
    }

    /**
     * Iniciar en el lugar: cada ejecutor y vigía tiene que firmar (ya firmado o firma que llega ahora).
     * @param array<string, string> $workerSignatures uuid del ejecutor => data URL
     */
    public static function start(array $p, array $workerSignatures, array $meta = []): ?string
    {
        if (!self::canOperate($p)) {
            return 'No tenés permiso para iniciar este permiso.';
        }
        if ($p['status'] !== 'aprobado') {
            return 'Solo se inicia un permiso autorizado.';
        }
        if (time() < strtotime($p['valid_from']) - self::START_EARLY_MINUTES * 60) {
            return 'Todavía no es la hora de inicio del permiso (' . fecha($p['valid_from'], 'd/m H:i') . ').';
        }
        if (time() > strtotime($p['ends_at'])) {
            return 'El permiso ya venció.';
        }
        $workers = WorkPermits::workers((int) $p['id']);
        $pending = [];
        $stored = [];
        foreach ($workers as $w) {
            if ($w['signature_uuid'] !== null) {
                continue;
            }
            $name = $w['employee_id'] ? $w['last_name'] . ', ' . $w['first_name'] : $w['external_name'];
            if (empty($workerSignatures[$w['uuid']])) {
                $pending[] = $name;
                continue;
            }
            try {
                $stored[] = [$w, Signatures::store($workerSignatures[$w['uuid']], 'permits')];
            } catch (UserError $e) {
                return $name . ': ' . $e->getMessage();
            }
        }
        if ($pending) {
            return 'Falta la firma de: ' . implode(', ', $pending) . '.';
        }
        foreach ($stored as [$w, $sig]) {
            WorkPermits::addSignature((int) $p['id'], $sig + ['role' => $w['role'], 'worker_id' => (int) $w['id'],
                'signer_name' => $w['employee_id'] ? $w['last_name'] . ', ' . $w['first_name'] : $w['external_name'],
                'signer_dni' => $w['employee_dni'] ?? $w['external_dni'], 'ip' => $meta['ip'] ?? null, 'user_agent' => $meta['user_agent'] ?? null]);
        }
        return self::change($p, 'en_ejecucion', ['started_at' => gmdate('Y-m-d H:i:s')], null, null, null, []);
    }

    /** Cerrar: el responsable confirma que el área quedó segura y firma. */
    public static function close(array $p, string $signature, string $comment, array $meta = []): ?string
    {
        if (!self::canOperate($p)) {
            return 'No tenés permiso para cerrar este permiso.';
        }
        if (!in_array($p['status'], ['en_ejecucion', 'suspendido'], true)) {
            return 'Solo se cierra un permiso en ejecución.';
        }
        if (mb_strlen(trim($comment)) < 5) {
            return 'Contá cómo quedó el área (mínimo 5 caracteres).';
        }
        return self::change($p, 'cerrado', ['closed_at' => gmdate('Y-m-d H:i:s'), 'closed_by' => self::me(), 'status_reason' => trim($comment)],
            trim($comment), 'cierre', $signature, $meta);
    }

    /** Recepción del área por el autorizante (firma), después del cierre. No cambia el estado. */
    public static function receive(array $p, string $signature, array $meta = []): ?string
    {
        if (!UserAuth::can(self::MODULE, 'aprobar') || !self::canView($p)) {
            return 'La recepción la firma quien autoriza permisos.';
        }
        if ($p['status'] !== 'cerrado') {
            return 'El área se recibe después del cierre.';
        }
        if (array_filter(WorkPermits::signatures((int) $p['id']), fn ($s) => $s['role'] === 'recepcion')) {
            return 'El área ya se recibió.';
        }
        try {
            $sig = Signatures::store($signature, 'permits');
        } catch (UserError $e) {
            return $e->getMessage();
        }
        WorkPermits::addSignature((int) $p['id'], $sig + self::signer('recepcion', $meta, []));
        WorkPermits::addEvent((int) $p['id'], 'received', self::actor());
        WorkPermits::update((int) $p['id'], []);
        return null;
    }

    public static function cancel(array $p, string $reason): ?string
    {
        if (!self::canOperate($p)) {
            return 'No tenés permiso para cancelar este permiso.';
        }
        if (!in_array($p['status'], ['solicitado', 'aprobado'], true)) {
            return 'Solo se cancela un permiso que todavía no empezó.';
        }
        if (mb_strlen(trim($reason)) < 5) {
            return 'Escribí el motivo (mínimo 5 caracteres).';
        }
        return self::change($p, 'cancelado', ['status_reason' => trim($reason)], trim($reason), null, null, []);
    }

    /** El cron: avisa 30 min antes y marca vencidos los que pasaron su fin. @return array{expiring:int, expired:int} */
    public static function expire(?int $now = null): array
    {
        $now ??= time();
        $out = ['expiring' => 0, 'expired' => 0];
        $nowSql = gmdate('Y-m-d H:i:s', $now);
        foreach (WorkPermits::search(['status' => WorkPermits::ACTIVE, 'ends_before' => $nowSql], null, 500) as $p) {
            $locked = WorkPermits::findById((int) $p['id'], false);
            WorkPermits::update((int) $p['id'], ['status' => 'vencido', 'status_reason' => 'Venció la ventana del permiso.']);
            WorkPermits::addEvent((int) $p['id'], 'status', ['from' => $locked['status'], 'to' => 'vencido', 'comment' => 'Vencimiento automático.', 'actor_name' => 'Sistema']);
            Notifier::dispatch('permit.expired', WorkPermits::findById((int) $p['id']));
            $out['expired']++;
        }
        $soon = gmdate('Y-m-d H:i:s', $now + 30 * 60);
        foreach (WorkPermits::search(['status' => ['aprobado', 'en_ejecucion'], 'ends_after' => $nowSql, 'ends_before' => $soon], null, 500) as $p) {
            if (\App\Models\NotificationMarks::claim("permit_soon:{$p['id']}:{$p['ends_at']}")) {
                Notifier::dispatch('permit.expiring', $p);
                $out['expiring']++;
            }
        }
        return $out;
    }

    // ── helpers ─────────────────────────────────────────────────────

    /**
     * Cambio de estado con bloqueo de la fila, firma opcional, evento, auditoría y aviso.
     */
    private static function change(array $p, string $to, array $data, ?string $comment, ?string $signRole, ?string $signature, array $meta, ?callable $notify = null): ?string
    {
        $sig = null;
        if ($signRole !== null) {
            try {
                $sig = Signatures::store((string) $signature, 'permits');
            } catch (UserError $e) {
                return $e->getMessage();
            }
        }
        $db = DB::tenant();
        $db->beginTransaction();
        try {
            $locked = WorkPermits::findById((int) $p['id'], true);
            if ($locked['status'] !== $p['status']) {
                $db->rollBack();
                return 'El permiso cambió mientras tanto ("' . self::STATES[$locked['status']]['label'] . '"). Recargá la página.';
            }
            WorkPermits::update((int) $p['id'], ['status' => $to] + $data);
            if ($sig !== null) {
                WorkPermits::addSignature((int) $p['id'], $sig + self::signer($signRole, $meta, []));
            }
            WorkPermits::addEvent((int) $p['id'], 'status', ['from' => $p['status'], 'to' => $to, 'comment' => $comment] + self::actor());
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        Audit::tenant('permit.' . $to, 'work_permit', $p['uuid'], ['estado' => $p['status']], ['estado' => $to]);
        if ($notify !== null) {
            $notify(WorkPermits::findById((int) $p['id']));
        }
        return null;
    }

    private static function signer(string $role, array $meta, array $in): array
    {
        $user = UserAuth::user();
        $emp = $user ? Employees::findBy('user_id', (int) $user['id']) : null;
        return ['role' => $role, 'user_id' => $user['id'] ?? null, 'signer_name' => $user['name'] ?? 'Sistema', 'signer_dni' => $emp['dni'] ?? ($user['dni'] ?? null),
            'ip' => $meta['ip'] ?? null, 'user_agent' => $meta['user_agent'] ?? null,
            'lat' => is_numeric($in['lat'] ?? null) ? (float) $in['lat'] : null, 'lng' => is_numeric($in['lng'] ?? null) ? (float) $in['lng'] : null];
    }

    private static function parseTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $v = (string) $value;
            $t = preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $v) ? new \DateTimeImmutable($v) : new \DateTimeImmutable($v, new \DateTimeZone(Tenant::timezone() ?? 'UTC'));
        } catch (\Exception) {
            return null;
        }
        return gmdate('Y-m-d H:i:s', $t->getTimestamp());
    }

    private static function me(): int
    {
        return (int) (UserAuth::user()['id'] ?? 0);
    }

    private static function actor(): array
    {
        $user = UserAuth::user();
        return $user ? ['user_id' => (int) $user['id'], 'actor_name' => $user['name']] : ['user_id' => null, 'actor_name' => 'Sistema'];
    }
}
