<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Sectors;
use App\Models\UserSectors;

/**
 * Alcance por sector del usuario actual para un módulo:
 *  - null  → ve todo (alcance "todo", o modo soporte)
 *  - lista → solo esos sector_id (sus sectores asignados y todos sus descendientes)
 * El alcance "propios" lo resuelve cada módulo (lo creado por el usuario).
 */
final class SectorScope
{
    /** @return ?list<int> */
    public static function sectorIds(string $module): ?array
    {
        $user = UserAuth::user();
        if ($user === null || UserAuth::scope($module) !== 'sectores') {
            return null;
        }
        return Sectors::withDescendants(UserSectors::forUser((int) $user['id']));
    }
}
