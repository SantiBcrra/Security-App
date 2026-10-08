<?php
declare(strict_types=1);

namespace App\Services\Sync;

use App\Core\DB;
use App\Core\UserError;
use App\Core\Uuid;
use App\Models\Observations;
use App\Models\Patrols;
use App\Services\ObservationInput;
use App\Services\ObservationService;
use App\Services\UserAuth;

/**
 * Push de la sincronización: lote de operaciones generadas offline en el celular.
 * Idempotente por op_id (tabla idempotency_keys): reintentar nunca duplica.
 * Cada operación se procesa sola: si una falla, las demás siguen.
 */
final class Push
{
    public const MAX_OPS = 50;
    public const TYPES = ['observation.create', 'observation.comment', 'observation.transition', 'round.start', 'round.scan', 'round.finish', 'action.start', 'action.close', 'inspection.create', 'incident.create'];

    /** @return list<array{op_id:string, status:'ok'|'error', data?:array, error?:string}> */
    public static function run(array $operations): array
    {
        $results = [];
        foreach (array_slice($operations, 0, self::MAX_OPS) as $op) {
            $opId = strtolower((string) ($op['op_id'] ?? ''));
            if (!Uuid::isValid($opId)) {
                $results[] = ['op_id' => $opId, 'status' => 'error', 'error' => 'op_id inválido (tiene que ser un UUID).'];
                continue;
            }
            if (($previous = self::stored($opId)) !== null) {
                $results[] = $previous; // ya procesada: mismo resultado, nada nuevo
                continue;
            }
            $result = ['op_id' => $opId] + self::apply((string) ($op['type'] ?? ''), (array) ($op['data'] ?? []));
            // Los errores de validación también se guardan: reenviar lo mismo da lo mismo.
            DB::tenant()->prepare('INSERT IGNORE INTO idempotency_keys (op_id, user_id, op_type, response, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())')
                ->execute([$opId, (int) UserAuth::user()['id'], mb_substr((string) ($op['type'] ?? ''), 0, 40), json_encode($result, JSON_UNESCAPED_UNICODE)]);
            $results[] = $result;
        }
        return $results;
    }

    private static function stored(string $opId): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT response FROM idempotency_keys WHERE op_id = ?');
        $stmt->execute([$opId]);
        $json = $stmt->fetchColumn();
        return $json === false ? null : json_decode((string) $json, true);
    }

    private static function apply(string $type, array $data): array
    {
        try {
            return match ($type) {
                'observation.create'     => self::create($data),
                'observation.comment'    => self::comment($data),
                'observation.transition' => self::transition($data),
                'round.start'            => self::roundStart($data),
                'round.scan'             => self::roundScan($data),
                'round.finish'           => self::roundFinish($data),
                'action.start'           => self::actionStep($data, 'tomar'),
                'action.close'           => self::actionStep($data, 'cerrar'),
                'inspection.create'      => self::inspectionCreate($data),
                'incident.create'        => self::incidentCreate($data),
                default                  => self::error('Tipo de operación desconocido: ' . $type),
            };
        } catch (UserError $e) {
            return self::error($e->getMessage()); // validación: el mensaje es para el usuario
        } catch (\Throwable $e) {
            \App\Core\Logger::error('Sync push: falló una operación', ['type' => $type, 'error' => $e->getMessage()]);
            return self::error('Error del servidor al procesar la operación.');
        }
    }

    private static function create(array $data): array
    {
        if (!UserAuth::can(ObservationService::MODULE, 'crear')) {
            return self::error('Tu rol no puede reportar observaciones.');
        }
        if (empty($data['uuid']) || empty($data['created_at_device'])) {
            return self::error('Faltan el uuid o la hora del hecho.');
        }
        // Mismo uuid ya recibido (por otra vía o sin op_id previo): no se duplica.
        if (($existing = Observations::findByUuid(strtolower((string) $data['uuid']))) !== null) {
            return ['status' => 'ok', 'data' => ['uuid' => $existing['uuid'], 'number' => (int) $existing['number'], 'status' => $existing['status'], 'duplicate' => true]];
        }
        [$fields, $people, $errors] = ObservationInput::validate($data);
        if ($errors) {
            return self::error(implode(' ', $errors));
        }
        try {
            $obs = ObservationService::create($fields, $people, [])['observation'];
        } catch (\PDOException $e) {
            if (($existing = Observations::findByUuid($fields['uuid'])) !== null) { // carrera: llegó dos veces a la vez
                return ['status' => 'ok', 'data' => ['uuid' => $existing['uuid'], 'number' => (int) $existing['number'], 'status' => $existing['status'], 'duplicate' => true]];
            }
            throw $e;
        }
        return ['status' => 'ok', 'data' => ['uuid' => $obs['uuid'], 'number' => (int) $obs['number'], 'status' => $obs['status']]];
    }

    private static function comment(array $data): array
    {
        $obs = Observations::findByUuid((string) ($data['uuid'] ?? ''));
        if ($obs === null || !ObservationService::canView($obs) || !UserAuth::can(ObservationService::MODULE, 'crear')) {
            return self::error('La observación no existe o no tenés acceso.');
        }
        $error = ObservationService::comment($obs, (string) ($data['comment'] ?? ''));
        return $error ? self::error($error) : ['status' => 'ok', 'data' => ['uuid' => $obs['uuid']]];
    }

    private static function transition(array $data): array
    {
        $obs = Observations::findByUuid((string) ($data['uuid'] ?? ''));
        if ($obs === null || !ObservationService::canView($obs)) {
            return self::error('La observación no existe o no tenés acceso.');
        }
        $error = ObservationService::transition($obs, (string) ($data['action'] ?? ''), (array) ($data['input'] ?? []));
        if ($error) {
            return self::error($error);
        }
        $fresh = Observations::findById((int) $obs['id']);
        return ['status' => 'ok', 'data' => ['uuid' => $fresh['uuid'], 'status' => $fresh['status']]];
    }

    private static function roundStart(array $data): array
    {
        if (!UserAuth::can('rondas', 'crear')) return self::error('Tu rol no puede iniciar rondas.');
        $r = Patrols::start(!empty($data['route_uuid']) ? (string) $data['route_uuid'] : null, isset($data['lat']) ? (float) $data['lat'] : null, isset($data['lng']) ? (float) $data['lng'] : null, (string) ($data['uuid'] ?? ''));
        return ['status' => 'ok', 'data' => ['uuid' => $r['uuid'], 'status' => $r['status']]];
    }

    private static function roundScan(array $data): array
    {
        if (!UserAuth::can('rondas', 'crear')) return self::error('Tu rol no puede registrar rondas.');
        return ['status' => 'ok', 'data' => Patrols::scan($data)];
    }

    private static function roundFinish(array $data): array
    {
        if (!UserAuth::can('rondas', 'cerrar')) return self::error('Tu rol no puede cerrar rondas.');
        $r = Patrols::finish((string) ($data['round_uuid'] ?? ''));
        return ['status' => 'ok', 'data' => ['uuid' => $r['uuid'], 'status' => $r['status']]];
    }

    /**
     * Tomar o cerrar una acción desde el celular. La evidencia ya subió por partes (la app manda el
     * cierre después de las fotos). Si ya estaba hecho por el mismo usuario, se responde ok (reenvío).
     */
    private static function actionStep(array $data, string $key): array
    {
        $a = \App\Models\Actions::findByUuid((string) ($data['uuid'] ?? ''));
        if ($a === null || !\App\Services\ActionService::canView($a)) {
            return self::error('La acción no existe o ya no tenés acceso.');
        }
        $me = (int) UserAuth::user()['id'];
        $done = $key === 'tomar' ? in_array($a['status'], ['en_curso', 'cerrada', 'verificada'], true)
            : ($a['status'] === 'cerrada' && (int) $a['closed_by'] === $me) || $a['status'] === 'verificada';
        if (!$done) {
            $error = \App\Services\ActionService::transition($a, $key, ['closure_text' => $data['closure_text'] ?? '']);
            if ($error !== null) {
                return self::error($error);
            }
        }
        $fresh = \App\Models\Actions::findByUuid($a['uuid']);
        return ['status' => 'ok', 'data' => ['uuid' => $fresh['uuid'], 'status' => $fresh['status'],
            'status_label' => \App\Services\ActionWorkflow::label($fresh['status']), 'duplicate' => $done]];
    }

    /**
     * Inspección hecha sin señal (uuid del celular: reenviarla no duplica). Las fotos se suben después por
     * partes (uploads con inspection_uuid + item_key); photo_counts dice cuántas trae cada ítem.
     */
    private static function inspectionCreate(array $data): array
    {
        if (!Uuid::isValid((string) ($data['uuid'] ?? ''))) {
            return self::error('Falta el identificador de la inspección.');
        }
        $r = \App\Services\InspectionService::create($data, [], (array) ($data['photo_counts'] ?? []));
        if ($r['inspection'] === null) {
            return self::error(reset($r['errors']) ?: 'Inspección inválida.');
        }
        $i = $r['inspection'];
        return ['status' => 'ok', 'data' => ['uuid' => $i['uuid'], 'code' => \App\Models\Inspections::format((int) $i['number']),
            'result' => $i['result'], 'result_label' => \App\Services\InspectionService::RESULTS[$i['result']]['label'], 'score' => $i['score'] !== null ? (int) $i['score'] : null,
            'actions' => (int) $i['items_fail'], 'duplicate' => !empty($r['duplicate'])]];
    }

    /** Incidente reportado sin señal (uuid del celular: reenviarlo no duplica). Las fotos van después, por partes. */
    private static function incidentCreate(array $data): array
    {
        if (!Uuid::isValid((string) ($data['uuid'] ?? ''))) {
            return self::error('Falta el identificador del incidente.');
        }
        $r = \App\Services\IncidentService::create($data);
        if ($r['incident'] === null) {
            return self::error(implode(' ', $r['errors']) ?: 'Reporte inválido.');
        }
        $i = $r['incident'];
        return ['status' => 'ok', 'data' => ['uuid' => $i['uuid'], 'code' => \App\Models\Incidents::format((int) $i['number']), 'status' => $i['status'],
            'status_label' => \App\Services\IncidentService::STATES[$i['status']]['label'], 'duplicate' => !empty($r['duplicate'])]];
    }

    private static function error(string $message): array
    {
        return ['status' => 'error', 'error' => $message];
    }
}
