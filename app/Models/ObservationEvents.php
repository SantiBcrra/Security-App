<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Línea de tiempo de las observaciones (solo inserción). */
final class ObservationEvents
{
    public static function add(int $observationId, string $type, array $fields = []): void
    {
        DB::tenant()->prepare('INSERT INTO observation_events (uuid, observation_id, type, from_status, to_status, comment, data,
            user_id, actor_name, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([
                Uuid::v4(), $observationId, $type, $fields['from'] ?? null, $fields['to'] ?? null, $fields['comment'] ?? null,
                isset($fields['data']) ? json_encode($fields['data'], JSON_UNESCAPED_UNICODE) : null,
                $fields['user_id'] ?? null, $fields['actor_name'] ?? null,
            ]);
    }

    public static function forObservation(int $observationId): array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM observation_events WHERE observation_id = ? ORDER BY id');
        $stmt->execute([$observationId]);
        return $stmt->fetchAll();
    }
}
