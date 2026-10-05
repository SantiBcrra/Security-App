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
        if ($action === 'asignar') {
            $assignee = Users::findByUuid((string) ($input['assigned_user'] ?? ''));
            $actionText = trim((string) ($input['action_text'] ?? ''));
            $due = \App\Resources\Resource::parseDate((string) ($input['action_due_on'] ?? ''));
            if ($assignee === null || (int) $assignee['is_active'] !== 1) {
                return 'Elegí el responsable de la acción.';
            }
            if (mb_strlen($actionText) < 5) {
                return 'Describí la acción a realizar.';
            }
            if ($due === null) {
                return 'Indicá la fecha compromiso.';
            }
            $changes += ['assigned_user_id' => (int) $assignee['id'], 'action_text' => $actionText, 'action_due_on' => $due];
            $data = ['responsable' => $assignee['name'], 'accion' => $actionText, 'fecha_compromiso' => $due];
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
            ObservationEvents::add((int) $obs['id'], $action === 'asignar' ? 'assignment' : 'status', [
                'from' => $locked['status'], 'to' => $t['to'], 'comment' => $comment ?: null, 'data' => $data,
            ] + self::actor(false));
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        Audit::tenant('observation.' . $action, 'observation', $obs['uuid'], ['estado' => $obs['status']], ['estado' => $t['to']] + ($data ?? []));
        return null;
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
