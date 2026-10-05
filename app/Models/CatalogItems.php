<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/** Ítems de los catálogos configurables (tipo_riesgo, categoria, severidad, causa, tipo_equipo). */
final class CatalogItems extends Repository
{
    protected const TABLE = 'catalog_items';
    protected const SEARCH = ['name', 'code', 'description'];
    protected const ORDER = 't.sort_order, t.name';

    public static function optionsFor(string $catalog, ?int $includeId = null): array
    {
        $stmt = DB::tenant()->prepare('SELECT uuid, name FROM catalog_items WHERE catalog = ? AND (is_active = 1 OR id = ?)
            ORDER BY sort_order, name');
        $stmt->execute([$catalog, $includeId ?? 0]);
        return array_column($stmt->fetchAll(), 'name', 'uuid');
    }

    public static function findByName(string $catalog, string $name): ?array
    {
        return self::one('t.catalog = ? AND t.name = ?', [$catalog, trim($name)]);
    }

    public static function nextSort(string $catalog): int
    {
        $stmt = DB::tenant()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM catalog_items WHERE catalog = ?');
        $stmt->execute([$catalog]);
        return (int) $stmt->fetchColumn();
    }

    public static function nameExists(string $catalog, string $name, ?int $exceptId = null): bool
    {
        $stmt = DB::tenant()->prepare('SELECT 1 FROM catalog_items WHERE catalog = ? AND name = ? AND id <> ? LIMIT 1');
        $stmt->execute([$catalog, trim($name), $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }
}
