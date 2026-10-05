<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Auditoría de UNA empresa (tabla audit_log en la base de esa empresa).
 * Recibe la conexión explícita: normalmente DB::tenant(); en el alta, la base recién creada.
 * Solo se inserta: nunca se edita ni se borra.
 */
final class TenantAudit
{
    public static function insert(PDO $db, array $row): void
    {
        $db->prepare('INSERT INTO audit_log
            (actor_type, actor_id, actor_name, action, entity_type, entity_uuid, before_data, after_data, ip, device, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([
                $row['actor_type'], $row['actor_id'], $row['actor_name'], $row['action'], $row['entity_type'],
                $row['entity_uuid'], $row['before_data'], $row['after_data'], $row['ip'], $row['device'],
            ]);
    }

    public static function latest(PDO $db, int $limit = 20): array
    {
        return $db->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT ' . max(1, $limit))->fetchAll();
    }
}
