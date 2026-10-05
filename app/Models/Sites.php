<?php
declare(strict_types=1);

namespace App\Models;

/** Plantas / sitios. */
final class Sites extends Repository
{
    protected const TABLE = 'sites';
    protected const SEARCH = ['name', 'code', 'city', 'address'];
    protected const ORDER = 't.name';
}
