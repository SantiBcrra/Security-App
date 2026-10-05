<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/** Sectores asignados a cada usuario (alcance "Sus sectores"). */
final class UserSectors
{
    /** @return list<int> */
    public static function forUser(int $userId): array
    {
        $stmt = DB::tenant()->prepare('SELECT sector_id FROM user_sectors WHERE user_id = ?');
        $stmt->execute([$userId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @param list<int> $sectorIds */
    public static function replace(int $userId, array $sectorIds): void
    {
        $db = DB::tenant();
        $db->prepare('DELETE FROM user_sectors WHERE user_id = ?')->execute([$userId]);
        $insert = $db->prepare('INSERT INTO user_sectors (user_id, sector_id, created_at) VALUES (?, ?, UTC_TIMESTAMP())');
        foreach (array_unique($sectorIds) as $sectorId) {
            $insert->execute([$userId, $sectorId]);
        }
    }
}
