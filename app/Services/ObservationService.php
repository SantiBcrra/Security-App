<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Tenant;
use App\Models\CatalogItems;
use App\Models\Employees;
use App\Models\Equipment;
use App\Models\ObservationAttachments;
use App\Models\ObservationEvents;
use App\Models\Observations;
use App\Models\Sectors;
use App\Models\Users;
use App\Services\Notify\Escalations;
use App\Services\Notify\Notifier;

/**
 * Lógica de observaciones: alta (número correlativo + reporte original congelado con hash),
 * transiciones de estado, comentarios, correcciones (como eventos) y fotos.
 */
final class ObservationService
{
    public const MODULE = 'observaciones';

    /** Alcance del usuario actual para listar/ver. null = todo. */
    public static function scope(): ?array
    {
        $user = UserAuth::user();
        return match (UserAuth::scope(self::MODULE)) {
            'propios'  => ['own' => (int) ($user['id'] ?? 0)],
            'sectores' => ['sector_ids' => SectorScope::sectorIds(self::MODULE) ?? [], 'user_id' => (int) ($user['id'] ?? 0)],
            null       => ['own' => 0], // sin permiso: nada
            default    => null,
        };
    }

    public static function canView(array $obs): bool
    {
        $scope = self::scope();
        if ($scope === null) {
            return true;
        }
        if (isset($scope['own'])) {
            return $scope['own'] > 0 && (int) $obs['reporter_user_id'] === $scope['own'];
        }
        return in_array((int) $obs['sector_id'], $scope['sector_ids'], true) || (int) $obs['reporter_user_id'] === $scope['user_id'];
    }

    /**
     * Alta. $data ya validado (ids internos). @param list<array{tmp:string, name:?string, upload:bool}> $photos
     * @return array{observation: array, photo_errors: list<string>}
     */
    public static function create(array $data, array $employeeIds, array $photos): array
    {
        $user = UserAuth::user();
        $anonymous = !empty($data['is_anonymous']);
        $db = DB::tenant();
        $db->beginTransaction();
        try {
            $number = \App\Models\Sequences::next('observations');
            $original = self::snapshot($data, $employeeIds, $anonymous ? null : $user, $number);
            $json = json_encode($original, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $id = Observations::create($data + [
                'number'           => $number,
                'reporter_user_id' => $anonymous ? null : ($user['id'] ?? null),
                'status'           => 'abierta',
                'original_data'    => $json,
                'original_hash'    => hash('sha256', $json),
            ]);
            Observations::addPeople($id, $employeeIds);
            ObservationEvents::add($id, 'created', ['to' => 'abierta'] + self::actor($anonymous));
            if (!empty($data['imminent_risk'])) {
                ObservationEvents::add($id, 'imminent_alert', ['comment' => 'Reportado como RIESGO INMINENTE.'] + self::actor($anonymous));
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $obs = Observations::findById($id);
        $photoErrors = self::addPhotos($obs, $photos, $anonymous, false);
        // La auditoría de un anónimo no registra quién (ni IP/dispositivo vinculados al autor).
        if (!$anonymous) {
            Audit::tenant('observation.create', 'observation', $obs['uuid'], null, ['numero' => Observations::format($number)]);
        }
        if (!empty($data['imminent_risk'])) {
            ObservationAlerts::imminent($obs);
        } else {
            Notifier::dispatch('observation.created', $obs);
        }
        return ['observation' => Observations::findById($id), 'photo_errors' => $photoErrors];
    }

    /** El reporte tal como se cargó, con nombres legibles (queda congelado). */
    private static function snapshot(array $data, array $employeeIds, ?array $user, int $number): array
    {
        $name = fn (?int $id) => $id ? (CatalogItems::findById($id)['name'] ?? null) : null;
        $sectors = Sectors::labelMap();
        $equipment = !empty($data['equipment_id']) ? Equipment::findById((int) $data['equipment_id']) : null;
        return [
            'numero'        => Observations::format($number),
            'categoria'     => $name($data['category_id'] ?? null),
            'tipo_riesgo'   => $name($data['risk_type_id'] ?? null),
            'severidad'     => $name($data['severity_id'] ?? null),
            'sector'        => isset($data['sector_id']) ? ($sectors[(int) $data['sector_id']]['label'] ?? null) : null,
            'equipo'        => $equipment ? $equipment['code'] . ' · ' . $equipment['name'] : null,
            'descripcion'   => $data['description'],
            'lugar'         => $data['location_text'] ?? null,
            'gps'           => isset($data['lat']) ? ['lat' => $data['lat'], 'lng' => $data['lng'], 'precision_m' => $data['gps_accuracy_m'] ?? null] : null,
            'fecha_hecho'   => $data['created_at_device'],
            'riesgo_inminente' => !empty($data['imminent_risk']),
            'anonimo'       => $user === null,
            'reportado_por' => $user['name'] ?? null,
            'involucrados'  => array_map(fn ($id) => (Employees::findById((int) $id)['name'] ?? null), $employeeIds),
        ];
    }

    /**
     * Cambio de estado. @return ?string mensaje de error (null = ok)
     */
    public static function transition(array $obs, string $action, array $input): ?string
    {
        $t = ObservationWorkflow::TRANSITIONS[$action] ?? null;
        if ($t === null) {
            return 'Acción desconocida.';
        }
        if (!UserAuth::can(self::MODULE, $t['perm'])) {
            return 'No tenés permiso para esta acción.';
        }
        $comment = trim((string) ($input['comment'] ?? ''));
        if ($t['comment'] && mb_strlen($comment) < 5) {
            return 'Escribí un comentario o motivo (mínimo 5 caracteres).';
        }
        $changes = ['status' => $t['to']];
        $data = null;
        $actionData = null;
        if ($action === 'asignar') {
            // "Asignar acción" crea una acción CAPA (Etapa 10) que hereda planta y sector de la observación.
            [$actionData, $errors] = ActionService::validate([
                'title'       => $input['action_text'] ?? '',
                'description' => $input['action_detail'] ?? '',
                'type'        => $input['action_type'] ?? 'correctiva',
                'priority'    => $input['priority'] ?? 'media',
                'responsible' => $input['assigned_user'] ?? '',
                'due_on'      => $input['action_due_on'] ?? '',
            ]);
            if ($errors) {
                return reset($errors);
            }
            $actionData['site_id'] = $obs['site_id'] !== null ? (int) $obs['site_id'] : null;
            $actionData['sector_id'] = $obs['sector_id'] !== null ? (int) $obs['sector_id'] : null;
            $assignee = Users::findById((int) $actionData['responsible_user_id']);
            $data = ['responsable' => $assignee['name'], 'accion' => $actionData['title'], 'fecha_compromiso' => $actionData['due_on']];
        }
        if ($t['to'] === 'cerrada') {
            $changes['closed_at'] = gmdate('Y-m-d H:i:s');
        } elseif ($action === 'reabrir') {
            $changes['closed_at'] = null;
        }

        $db = DB::tenant();
        $db->beginTransaction();
        try {
            $locked = Observations::findById((int) $obs['id'], true);
            if (!in_array($locked['status'], $t['from'], true)) {
                $db->rollBack();
                return 'La observación ya está "' . ObservationWorkflow::label($locked['status']) . '": no se puede "' . mb_strtolower($t['label']) . '". Recargá la página.';
            }
            Observations::update((int) $obs['id'], $changes);
            if ($actionData !== null) {
                $actionId = ActionService::insert($actionData, 'observacion', (int) $obs['id']);
                $data['numero'] = \App\Models\Actions::format((int) \App\Models\Actions::findById($actionId)['number']);
                $data['accion_uuid'] = \App\Models\Actions::findById($actionId)['uuid'];
            }
            ObservationEvents::add((int) $obs['id'], $action === 'asignar' ? 'assignment' : 'status', [
                'from' => $locked['status'], 'to' => $t['to'], 'comment' => $comment ?: null, 'data' => $data,
            ] + self::actor(false));
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        Audit::tenant('observation.' . $action, 'observation', $obs['uuid'], ['estado' => $obs['status']], ['estado' => $t['to']] + ($data ?? []));
        if (isset($actionId)) {
            ActionService::afterCreate(\App\Models\Actions::findById($actionId));
        }
        $fresh = Observations::findById((int) $obs['id']);
        if ($action === 'asignar') {
            // El aviso habla de la acción recién creada (aunque la observación tenga otras abiertas).
            Notifier::dispatch('observation.assigned', $fresh, ['obs_override' => [
                'assigned_user_id' => $actionData['responsible_user_id'], 'assigned_name' => $data['responsable'],
                'action_text' => $actionData['title'], 'action_due_on' => $actionData['due_on'],
            ]]);
        } elseif (in_array($action, ['cerrar', 'descartar'], true)) {
            Escalations::closeFor((int) $obs['id']);
            Notifier::dispatch('observation.closed', $fresh, ['comment' => $comment]);
        }
        return null;
    }

    /**
     * Después de cualquier cambio en sus acciones (Etapa 10):
     * - assigned_user_id / action_text / action_due_on quedan como resumen de la acción abierta que
     *   vence primero (lo usan listados, avisos de vencidas y la app de campo);
     * - si todas sus acciones quedaron verificadas o canceladas, la observación se cierra sola.
     */
    public static function syncActions(int $observationId): void
    {
        $obs = Observations::findById($observationId);
        if ($obs === null) {
            return;
        }
        $actions = \App\Models\Actions::forOrigin('observacion', $observationId);
        $open = array_values(array_filter($actions, fn ($a) => ActionWorkflow::isOpen($a['status'])));
        usort($open, fn ($x, $y) => [$x['due_on'], $x['id']] <=> [$y['due_on'], $y['id']]);
        $summary = [
            'assigned_user_id' => $open ? (int) $open[0]['responsible_user_id'] : null,
            'action_text'      => $open ? $open[0]['title'] : null,
            'action_due_on'    => $open ? $open[0]['due_on'] : null,
        ];
        $current = ['assigned_user_id' => $obs['assigned_user_id'] !== null ? (int) $obs['assigned_user_id'] : null,
            'action_text' => $obs['action_text'], 'action_due_on' => $obs['action_due_on']];
        if ($summary !== $current) {
            Observations::update($observationId, $summary);
        }

        $finished = $actions && !array_filter($actions, fn ($a) => !in_array($a['status'], ['verificada', 'cancelada'], true));
        if (!$finished || $obs['status'] !== 'accion_asignada') {
            return;
        }
        $verified = count(array_filter($actions, fn ($a) => $a['status'] === 'verificada'));
        $comment = $verified ? 'Cierre automático: todas sus acciones fueron verificadas como eficaces.' : 'Cierre automático: todas sus acciones fueron canceladas.';
        $db = DB::tenant();
        $db->beginTransaction();
        try {
            $locked = Observations::findById($observationId, true);
            if ($locked['status'] !== 'accion_asignada') {
                $db->rollBack();
                return;
            }
            Observations::update($observationId, ['status' => 'cerrada', 'closed_at' => gmdate('Y-m-d H:i:s')]);
            ObservationEvents::add($observationId, 'status', ['from' => 'accion_asignada', 'to' => 'cerrada', 'comment' => $comment,
                'user_id' => null, 'actor_name' => 'Sistema']);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        Audit::tenant('observation.autoclose', 'observation', $obs['uuid'], ['estado' => 'accion_asignada'], ['estado' => 'cerrada']);
        Escalations::closeFor($observationId);
        Notifier::dispatch('observation.closed', Observations::findById($observationId), ['comment' => $comment]);
    }

    public static function comment(array $obs, string $comment): ?string
    {
        $comment = trim($comment);
        if (mb_strlen($comment) < 2) {
            return 'El comentario está vacío.';
        }
        ObservationEvents::add((int) $obs['id'], 'comment', ['comment' => mb_substr($comment, 0, 5000)] + self::actor(false));
        Observations::update((int) $obs['id'], []); // updated_at no cambia: el comentario es un evento
        return null;
    }

    /**
     * Corrección de clasificación: cambia el valor vigente y deja el antes/después como evento.
     * El reporte original (original_data) no se toca.
     * @param array $new columna => id (category_id, risk_type_id, severity_id, sector_id, equipment_id)
     */
    public static function correct(array $obs, array $new, string $comment): ?string
    {
        if (mb_strlen(trim($comment)) < 5) {
            return 'Explicá el motivo de la corrección (mínimo 5 caracteres).';
        }
        $labels = ['category_id' => 'Categoría', 'risk_type_id' => 'Tipo de riesgo', 'severity_id' => 'Severidad', 'sector_id' => 'Sector', 'equipment_id' => 'Equipo'];
        $describe = function (string $col, ?int $id): ?string {
            if ($id === null) {
                return null;
            }
            return match ($col) {
                'sector_id'    => Sectors::labelMap()[$id]['label'] ?? null,
                'equipment_id' => ($e = Equipment::findById($id)) ? $e['code'] . ' · ' . $e['name'] : null,
                default        => CatalogItems::findById($id)['name'] ?? null,
            };
        };
        $changes = [];
        $before = [];
        $after = [];
        foreach ($labels as $col => $label) {
            if (!array_key_exists($col, $new)) {
                continue;
            }
            $old = $obs[$col] !== null ? (int) $obs[$col] : null;
            if ($old !== $new[$col]) {
                $changes[$col] = $new[$col];
                $before[$label] = $describe($col, $old);
                $after[$label] = $describe($col, $new[$col]);
            }
        }
        if (!$changes) {
            return 'No hay cambios para corregir.';
        }
        if (isset($changes['sector_id']) && $changes['sector_id'] !== null) {
            $changes['site_id'] = (int) Sectors::findById($changes['sector_id'])['site_id'];
        }
        Observations::update((int) $obs['id'], $changes);
        ObservationEvents::add((int) $obs['id'], 'correction', ['comment' => trim($comment), 'data' => ['antes' => $before, 'despues' => $after]] + self::actor(false));
        Audit::tenant('observation.correct', 'observation', $obs['uuid'], $before, $after);
        return null;
    }

    /** @return list<string> errores por foto (las válidas se guardan igual) */
    public static function addPhotos(array $obs, array $photos, bool $anonymous = false, bool $logEvent = true): array
    {
        $errors = [];
        $saved = 0;
        $tenantUuid = Tenant::current()['uuid'];
        foreach ($photos as $p) {
            try {
                $meta = ImageProcessor::store($tenantUuid, $p['tmp'], $p['name'] ?? null, $p['upload'] ?? true,
                    $anonymous ? null : (UserAuth::user()['id'] ?? null));
                ObservationAttachments::create((int) $obs['id'], $meta);
                $saved++;
            } catch (\DomainException $e) {
                $errors[] = ($p['name'] ?? 'Foto') . ': ' . $e->getMessage();
            }
        }
        if ($saved > 0 && $logEvent) {
            ObservationEvents::add((int) $obs['id'], 'attachment', ['data' => ['fotos' => $saved]] + self::actor($anonymous));
        }
        return $errors;
    }

    private static function actor(bool $anonymous): array
    {
        if ($anonymous) {
            return ['user_id' => null, 'actor_name' => 'Anónimo'];
        }
        $user = UserAuth::user();
        if ($user !== null) {
            return ['user_id' => (int) $user['id'], 'actor_name' => $user['name']];
        }
        $admin = AdminAuth::user();
        return ['user_id' => null, 'actor_name' => $admin ? $admin['name'] . ' (soporte)' : 'Sistema'];
    }
}
