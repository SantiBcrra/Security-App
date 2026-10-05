<?php
declare(strict_types=1);

namespace App\Models;

/** Empresas contratistas. */
final class Contractors extends Repository
{
    protected const TABLE = 'contractors';
    protected const SEARCH = ['name', 'cuit', 'contact_name', 'art_insurer'];
    protected const ORDER = 't.name';

    protected static function select(): string
    {
        return 'SELECT t.*, (SELECT COUNT(*) FROM employees e WHERE e.contractor_id = t.id AND e.is_active = 1) AS workers
            FROM contractors t';
    }
}
