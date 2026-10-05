<?php
declare(strict_types=1);

namespace App\Models;

/** Puestos de trabajo. */
final class Positions extends Repository
{
    protected const TABLE = 'positions';
    protected const SEARCH = ['name', 'description'];
    protected const ORDER = 't.name';
}
