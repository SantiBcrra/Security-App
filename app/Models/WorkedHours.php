<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/** Horas-hombre trabajadas y dotación por planta y mes (base de los índices). */
final class WorkedHours
{
    /** @return array<int, array<string, array{hours: float, headcount: ?int}>> site_id => mes => datos */
    public static function forYear(int $year): array
    {
        $stmt = DB::tenant()->prepare('SELECT site_id, month, hours, headcount FROM worked_hours WHERE month LIKE ?');
        $stmt->execute([$year . '-%']);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['site_id']][$r['month']] = ['hours' => (float) $r['hours'], 'headcount' => $r['headcount'] !== null ? (int) $r['headcount'] : null];
        }
        return $out;
    }

    public static function save(int $siteId, string $month, ?float $hours, ?int $headcount, ?int $userId): void
    {
        if ($hours === null && $headcount === null) {
            DB::tenant()->prepare('DELETE FROM worked_hours WHERE site_id = ? AND month = ?')->execute([$siteId, $month]);
            return;
        }
        DB::tenant()->prepare('INSERT INTO worked_hours (site_id, month, hours, headcount, updated_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE hours = VALUES(hours), headcount = VALUES(headcount), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()')
            ->execute([$siteId, $month, $hours ?? 0, $headcount, $userId]);
    }
}
