<?php
declare(strict_types=1);

namespace App\Models;

/** Empleados: personal propio (contractor_id NULL) y personal de contratistas. */
final class Employees extends Repository
{
    protected const TABLE = 'employees';
    protected const SEARCH = ['last_name', 'first_name', 'dni', 'file_number'];
    protected const ORDER = 't.last_name, t.first_name';

    protected static function select(): string
    {
        return 'SELECT t.*, CONCAT(t.last_name, \', \', t.first_name) AS name, p.name AS position_name,
                s.name AS sector_name, c.name AS contractor_name
            FROM employees t
            LEFT JOIN positions p ON p.id = t.position_id
            LEFT JOIN sectors s ON s.id = t.sector_id
            LEFT JOIN contractors c ON c.id = t.contractor_id';
    }

    /** Opciones uuid => "Apellido, Nombre (DNI)". */
    public static function options(?int $includeId = null): array
    {
        $options = [];
        foreach (self::list(null, false, [], 5000) as $row) {
            $options[$row['uuid']] = $row['name'] . ' (' . $row['dni'] . ')';
        }
        return $options;
    }
}
