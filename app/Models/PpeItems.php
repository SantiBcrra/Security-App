<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/** Catálogo de EPP de la empresa. */
final class PpeItems extends Repository
{
    protected const TABLE = 'ppe_items';
    protected const SEARCH = ['name', 'brand', 'model', 'certification'];
    protected const ORDER = 't.category, t.name';

    public static function findByPreset(string $key): ?array
    {
        return self::findBy('preset_key', $key);
    }

    /** @return array<int, array> id => fila (incluye inactivos: sirven para mostrar historial) */
    public static function byId(): array
    {
        return array_column(DB::tenant()->query('SELECT * FROM ppe_items ORDER BY category, name')->fetchAll(), null, 'id');
    }
}
