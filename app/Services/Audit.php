<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Logger;
use App\Models\PlatformAudit;
use App\Models\TenantAudit;
use PDO;

/**
 * Punto único para auditar. Guarda quién, qué, sobre qué entidad, antes/después, IP y dispositivo.
 *   Audit::platform('tenant.suspend', 'tenant', $uuid, $antes, $despues);
 *   Audit::tenant('observation.create', 'observation', $uuid, null, $datos);
 */
final class Audit
{
    public static function platform(string $action, string $entityType, ?string $entityUuid, ?array $before = null, ?array $after = null): void
    {
        $admin = AdminAuth::user();
        PlatformAudit::insert([
            'admin_id'    => $admin['id'] ?? null,
            'admin_name'  => $admin['name'] ?? null,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid,
            'before_data' => self::json($before),
            'after_data'  => self::json($after),
            'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent'  => self::device(),
        ]);
    }

    /** Auditoría en la base de la empresa activa (o en $db si se pasa, ej: durante el alta). */
    public static function tenant(string $action, string $entityType, ?string $entityUuid, ?array $before = null, ?array $after = null, ?PDO $db = null): void
    {
        try {
            TenantAudit::insert($db ?? DB::tenant(), self::actor() + [
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_uuid' => $entityUuid,
                'before_data' => self::json($before),
                'after_data'  => self::json($after),
                'ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
                'device'      => self::device(),
            ]);
        } catch (\Throwable $e) {
            // La auditoría no debe tirar abajo la operación, pero sí quedar registrada como error.
            Logger::error('No se pudo auditar en la empresa', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /** Quién actúa. En Etapa 3 se agrega el usuario logueado de la empresa. */
    private static function actor(): array
    {
        $admin = AdminAuth::user();
        if ($admin !== null) {
            return ['actor_type' => 'platform_admin', 'actor_id' => (int) $admin['id'], 'actor_name' => $admin['name']];
        }
        return ['actor_type' => 'system', 'actor_id' => null, 'actor_name' => null];
    }

    private static function json(?array $data): ?string
    {
        return $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function device(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        return $ua === null ? null : mb_substr($ua, 0, 255);
    }
}
