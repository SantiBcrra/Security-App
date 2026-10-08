<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Línea de tiempo de las acciones (solo inserción). */
final class ActionEvents
{
    public static function add(int $actionId, string $type, array $fields = []): void
    {
        DB::tenant()->prepare('INSERT INTO action_events (uuid, action_id, type, from_status, to_status, comment, data,
            user_id, actor_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([
                Uuid::v4(), $actionId, $type, $fields['from'] ?? null, $fields['to'] ?? null, $fields['comment'] ?? null,
                isset($fields['data']) ? json_encode($fields['data'], JSON_UNESCAPED_UNICODE) : null,
                $fields['user_id'] ?? null, $fields['actor_name'] ?? null,
            ]);
    }

    public static function forAction(int $actionId): array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM action_events WHERE action_id = ? ORDER BY id');
        $stmt->execute([$actionId]);
        return $stmt->fetchAll();
    }
}
