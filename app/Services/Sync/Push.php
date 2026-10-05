<?php
declare(strict_types=1);

namespace App\Services\Sync;

use App\Core\DB;
use App\Core\Uuid;
use App\Models\Observations;
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
    public const TYPES = ['observation.create', 'observation.comment', 'observation.transition'];

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
                default                  => self::error('Tipo de operación desconocido: ' . $type),
            };
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

    private static function error(string $message): array
    {
        return ['status' => 'error', 'error' => $message];
    }
}
