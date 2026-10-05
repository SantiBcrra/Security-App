<?php
declare(strict_types=1);

namespace App\Resources;

use App\Services\Catalogs;

/** Recursos de datos maestros disponibles en /panel/datos/{key}. */
final class Registry
{
    /** @return array<string, Resource> */
    public static function all(): array
    {
        $resources = [new SitesResource(), new SectorsResource(), new PositionsResource(), new EmployeesResource(),
            new ContractorsResource(), new EquipmentResource()];
        foreach (array_keys(Catalogs::DEFINITIONS) as $catalog) {
            $resources[] = new CatalogResource($catalog);
        }
        $byKey = [];
        foreach ($resources as $r) {
            $byKey[$r->key()] = $r;
        }
        return $byKey;
    }

    public static function get(string $key): ?Resource
    {
        return self::all()[$key] ?? null;
    }

    /** Recursos de catálogo (para las pestañas). @return array<string, CatalogResource> */
    public static function catalogs(): array
    {
        return array_filter(self::all(), fn ($r) => $r instanceof CatalogResource);
    }
}
