<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Tenant;
use App\Core\TenantFiles;
use App\Core\Uuid;
use App\Models\Actions;
use App\Models\ObservationAttachments;
use App\Models\ObservationEvents;
use App\Models\Observations;

/**
 * Fotos desde la app, por partes (~1 MB): nunca choca con upload_max_filesize / post_max_size del
 * hosting y se puede reanudar si se corta. Al completar se verifica tamaño y SHA-256 y se guarda
 * como evidencia (ImageProcessor: original intacto + miniatura). Todo idempotente.
 */
final class Uploads
{
    public const MAX_CHUNK = 1536 * 1024;

    /** @return array estado de la subida o ['error' => mensaje, 'code' => http] */
    public static function init(array $in): array
    {
        $uuid = strtolower((string) ($in['upload_uuid'] ?? ''));
        $obsUuid = strtolower((string) ($in['observation_uuid'] ?? ''));
        $actionUuid = strtolower((string) ($in['action_uuid'] ?? ''));
        $size = (int) ($in['size'] ?? 0);
        $sha = strtolower((string) ($in['sha256'] ?? ''));
        $target = $actionUuid !== '' ? $actionUuid : $obsUuid; // evidencia de una acción o foto de una observación
        if (!Uuid::isValid($uuid) || !Uuid::isValid($target) || !preg_match('/^[a-f0-9]{64}$/', $sha)) {
            return ['error' => 'Datos de la subida inválidos.', 'code' => 422];
        }
        if ($size < 1 || $size > ImageProcessor::MAX_BYTES) {
            return ['error' => 'La foto pesa más de ' . (ImageProcessor::MAX_BYTES / 1048576) . ' MB.', 'code' => 422];
        }
        if (($existing = self::find($uuid)) !== null) {
            return self::state($existing);
        }
        if ($actionUuid !== '') {
            $action = Actions::findByUuid($actionUuid);
            if ($action === null || !ActionService::canAddEvidence($action)) {
                return ['error' => 'La acción no existe, ya no está abierta o no podés agregarle evidencia.', 'code' => 404];
            }
        } else {
            $obs = Observations::findByUuid($obsUuid);
            if ($obs === null || !ObservationService::canView($obs) || !UserAuth::can(ObservationService::MODULE, 'crear')) {
                return ['error' => 'La observación no existe (todavía) o no tenés acceso.', 'code' => 404];
            }
        }
        DB::tenant()->prepare('INSERT INTO uploads (uuid, user_id, observation_uuid, action_uuid, original_name, size_bytes, sha256, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute([$uuid, (int) UserAuth::user()['id'], $actionUuid === '' ? $obsUuid : null, $actionUuid ?: null,
                mb_substr(basename((string) ($in['name'] ?? 'foto.jpg')), 0, 191), $size, $sha]);
        file_put_contents(self::partPath($uuid), '');
        return self::state(self::find($uuid));
    }

    public static function chunk(string $uuid, int $offset, string $bytes): array
    {
        $up = self::find($uuid);
        if ($up === null || (int) $up['user_id'] !== (int) UserAuth::user()['id']) {
            return ['error' => 'Subida inexistente.', 'code' => 404];
        }
        if ($up['status'] !== 'receiving') {
            return self::state($up);
        }
        if ($offset !== (int) $up['received_bytes']) {
            // El cliente reanuda desde lo que el servidor ya tiene.
            return self::state($up) + ['error' => 'La parte no coincide con lo recibido: seguí desde ' . $up['received_bytes'] . '.', 'code' => 409];
        }
        if (strlen($bytes) === 0 || strlen($bytes) > self::MAX_CHUNK || $offset + strlen($bytes) > (int) $up['size_bytes']) {
            return ['error' => 'Parte de tamaño inválido.', 'code' => 422];
        }
        $fp = fopen(self::partPath($uuid), 'c');
        if (!$fp || !flock($fp, LOCK_EX)) {
            return ['error' => 'No se pudo escribir la parte.', 'code' => 500];
        }
        try {
            clearstatcache(true, self::partPath($uuid));
            if (filesize(self::partPath($uuid)) !== $offset) { // otra copia de la misma parte llegó primero
                return self::state(self::find($uuid));
            }
            fseek($fp, $offset);
            fwrite($fp, $bytes);
            fflush($fp);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        DB::tenant()->prepare('UPDATE uploads SET received_bytes = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$offset + strlen($bytes), $up['id']]);
        return self::state(self::find($uuid));
    }

    public static function complete(string $uuid): array
    {
        $up = self::find($uuid);
        if ($up === null || (int) $up['user_id'] !== (int) UserAuth::user()['id']) {
            return ['error' => 'Subida inexistente.', 'code' => 404];
        }
        if ($up['status'] === 'completed') {
            return self::state($up);
        }
        if ((int) $up['received_bytes'] !== (int) $up['size_bytes']) {
            return self::state($up) + ['error' => 'Faltan partes.', 'code' => 409];
        }
        $part = self::partPath($uuid);
        if (hash_file('sha256', $part) !== $up['sha256']) {
            // Archivo corrupto: se descarta para que el celular lo reenvíe completo.
            file_put_contents($part, '');
            DB::tenant()->prepare("UPDATE uploads SET received_bytes = 0, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$up['id']]);
            return ['error' => 'La foto llegó dañada (el hash no coincide): reenviar.', 'code' => 422, 'received_bytes' => 0];
        }
        if ($up['action_uuid'] !== null) {
            return self::completeAction($up, $part);
        }
        $obs = Observations::findByUuid($up['observation_uuid']);
        try {
            $meta = ImageProcessor::store(Tenant::current()['uuid'], $part, $up['original_name'], false, (int) $up['user_id']);
        } catch (\DomainException $e) {
            DB::tenant()->prepare("UPDATE uploads SET status = 'rejected', error = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$e->getMessage(), $up['id']]);
            return ['error' => $e->getMessage(), 'code' => 422];
        }
        $attachment = ObservationAttachments::create((int) $obs['id'], $meta);
        ObservationEvents::add((int) $obs['id'], 'attachment', ['data' => ['fotos' => 1], 'user_id' => (int) $up['user_id'], 'actor_name' => UserAuth::user()['name']]);
        DB::tenant()->prepare("UPDATE uploads SET status = 'completed', attachment_uuid = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$attachment, $up['id']]);
        @unlink($part);
        return self::state(self::find($uuid));
    }

    /** Evidencia de una acción: queda en el ciclo actual (sirve para el cierre que viene después). */
    private static function completeAction(array $up, string $part): array
    {
        $action = Actions::findByUuid($up['action_uuid']);
        if ($action === null) {
            return ['error' => 'La acción ya no existe.', 'code' => 404];
        }
        try {
            $attachment = ActionService::storeFile($action, ['tmp' => $part, 'name' => $up['original_name'], 'upload' => false], 'evidencia');
        } catch (\DomainException $e) {
            DB::tenant()->prepare("UPDATE uploads SET status = 'rejected', error = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$e->getMessage(), $up['id']]);
            return ['error' => $e->getMessage(), 'code' => 422];
        }
        DB::tenant()->prepare("UPDATE uploads SET status = 'completed', attachment_uuid = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$attachment, $up['id']]);
        @unlink($part);
        return self::state(self::find($up['uuid']));
    }

    public static function find(string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM uploads WHERE uuid = ?');
        $stmt->execute([strtolower($uuid)]);
        return $stmt->fetch() ?: null;
    }

    private static function state(array $up): array
    {
        return ['upload_uuid' => $up['uuid'], 'status' => $up['status'], 'size' => (int) $up['size_bytes'],
            'received_bytes' => (int) $up['received_bytes'], 'attachment_uuid' => $up['attachment_uuid']];
    }

    private static function partPath(string $uuid): string
    {
        return TenantFiles::dir(Tenant::current()['uuid'], 'uploads') . '/' . strtolower($uuid) . '.part';
    }
}
