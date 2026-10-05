<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Alertas críticas con escalamiento. */
final class Alerts
{
    public static function create(int $observationId, int $minutes): int
    {
        DB::tenant()->prepare('INSERT INTO alerts (uuid, observation_id, level, next_escalation_at, created_at)
            VALUES (?, ?, 0, UTC_TIMESTAMP() + INTERVAL ? MINUTE, UTC_TIMESTAMP())')->execute([Uuid::v4(), $observationId, $minutes]);
        return (int) DB::tenant()->lastInsertId();
    }

    public static function findByUuid(string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM alerts WHERE uuid = ?');
        $stmt->execute([$uuid]);
        return $stmt->fetch() ?: null;
    }

    public static function openForObservation(int $observationId): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM alerts WHERE observation_id = ? AND acked_at IS NULL AND closed_at IS NULL ORDER BY id DESC LIMIT 1');
        $stmt->execute([$observationId]);
        return $stmt->fetch() ?: null;
    }

    public static function latestForObservation(int $observationId): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM alerts WHERE observation_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$observationId]);
        return $stmt->fetch() ?: null;
    }

    /** Sin confirmar y con el escalamiento vencido. */
    public static function dueForEscalation(): array
    {
        return DB::tenant()->query('SELECT * FROM alerts WHERE acked_at IS NULL AND closed_at IS NULL
            AND next_escalation_at IS NOT NULL AND next_escalation_at <= UTC_TIMESTAMP()')->fetchAll();
    }

    public static function update(int $id, array $data): void
    {
        $sets = implode(', ', array_map(fn ($c) => "`{$c}` = ?", array_keys($data)));
        DB::tenant()->prepare("UPDATE alerts SET {$sets} WHERE id = ?")->execute([...array_values($data), $id]);
    }

    public static function escalate(int $id, int $level, ?int $nextMinutes): void
    {
        DB::tenant()->prepare('UPDATE alerts SET level = ?, next_escalation_at = IF(? IS NULL, NULL, UTC_TIMESTAMP() + INTERVAL ? MINUTE) WHERE id = ?')
            ->execute([$level, $nextMinutes, (int) $nextMinutes, $id]);
    }
}
