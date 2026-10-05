<?php
declare(strict_types=1);

namespace App\Models;

/** Equipos con QR (autoelevadores, puentes grúa, prensas...). */
final class Equipment extends Repository
{
    public const STATUSES = ['operativo' => 'Operativo', 'fuera_servicio' => 'Fuera de servicio', 'baja' => 'Dado de baja'];

    protected const TABLE = 'equipment';
    protected const SEARCH = ['code', 'name', 'brand', 'model', 'serial_number'];
    protected const ORDER = 't.code';

    protected static function select(): string
    {
        return 'SELECT t.*, ty.name AS type_name, si.name AS site_name, se.name AS sector_name
            FROM equipment t
            LEFT JOIN catalog_items ty ON ty.id = t.type_id
            LEFT JOIN sites si ON si.id = t.site_id
            LEFT JOIN sectors se ON se.id = t.sector_id';
    }
}
