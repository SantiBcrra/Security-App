<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/** Auditoría de la plataforma (base maestra). Solo se inserta: nunca se edita ni se borra. */
final class PlatformAudit
{
    public static function insert(array $row): void
    {
        DB::master()->prepare('INSERT INTO platform_audit_log
            (admin_id, admin_name, action, entity_type, entity_uuid, before_data, after_data, ip, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([
                $row['admin_id'], $row['admin_name'], $row['action'], $row['entity_type'], $row['entity_uuid'],
                $row['before_data'], $row['after_data'], $row['ip'], $row['user_agent'],
            ]);
    }

    public static function forEntity(string $entityType, string $uuid, int $limit = 50): array
    {
        $stmt = DB::master()->prepare('SELECT * FROM platform_audit_log WHERE entity_type = ? AND entity_uuid = ?
            ORDER BY id DESC LIMIT ' . max(1, $limit));
        $stmt->execute([$entityType, $uuid]);
        return $stmt->fetchAll();
    }
}
