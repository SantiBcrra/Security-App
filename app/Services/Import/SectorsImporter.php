<?php
declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Sectors;
use App\Models\Sites;

/** Estructura: cada fila es Planta / Nave o área / Sector. Crea lo que falte (plantas incluidas). */
final class SectorsImporter extends EntityImporter
{
    public function key(): string { return 'sectores'; }
    public function label(): string { return 'Plantas y sectores'; }

    public function columns(): array
    {
        return [
            'planta' => ['label' => 'Planta', 'required' => true, 'synonyms' => ['sitio', 'establecimiento']],
            'nave'   => ['label' => 'Nave / área', 'synonyms' => ['nave', 'area', 'galpon', 'edificio']],
            'sector' => ['label' => 'Sector', 'synonyms' => ['seccion', 'subsector', 'puesto de trabajo']],
        ];
    }

    public function rowKey(array $row): ?string
    {
        $parts = array_filter([trim((string) ($row['planta'] ?? '')), trim((string) ($row['nave'] ?? '')), trim((string) ($row['sector'] ?? ''))]);
        return $parts ? mb_strtolower(implode('>', $parts)) : null;
    }

    public function example(): array
    {
        return ['Planta 1', 'Nave A', 'Soldadura'];
    }

    public function validate(array $row, ImportContext $ctx): array
    {
        if (trim((string) ($row['planta'] ?? '')) === '') {
            return self::result(['Falta la planta.'], 'create');
        }
        $label = implode(' > ', array_filter([$row['planta'], $row['nave'] ?? '', $row['sector'] ?? ''], fn ($v) => trim((string) $v) !== ''));
        [$id] = $ctx->sector($label);
        $exists = $id !== null || (($row['nave'] ?? '') === '' && ($row['sector'] ?? '') === '' && $ctx->siteId($row['planta']) !== null);
        return self::result([], $exists ? 'unchanged' : 'create');
    }

    public function apply(array $row, ImportContext $ctx): string
    {
        $planta = trim((string) $row['planta']);
        $siteId = $ctx->siteId($planta);
        $created = false;
        if ($siteId === null) {
            $siteId = Sites::create(['name' => $planta]);
            $created = true;
            $ctx->rememberSite($planta, $siteId);
        }
        $parentId = null;
        $path = $planta;
        foreach ([trim((string) ($row['nave'] ?? '')), trim((string) ($row['sector'] ?? ''))] as $name) {
            if ($name === '') {
                continue;
            }
            $path .= ' > ' . $name;
            $ctx->resetSectors();
            [$id] = Sectors::resolve($path);
            if ($id === null) {
                $id = Sectors::create(['site_id' => $siteId, 'parent_id' => $parentId, 'name' => $name]);
                $created = true;
            }
            $parentId = $id;
        }
        $ctx->resetSectors();
        return $created ? 'created' : 'unchanged';
    }
}
