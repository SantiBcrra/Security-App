<?php
declare(strict_types=1);

namespace App\Services\Import;

use App\Models\CatalogItems;
use App\Models\Contractors;
use App\Models\Positions;
use App\Models\Sectors;
use App\Models\Sites;

/** Cachés de búsqueda durante una importación (evita miles de consultas repetidas). */
final class ImportContext
{
    private ?array $sectorMap = null;
    private array $cache = [];

    public function sectorMap(): array
    {
        return $this->sectorMap ??= Sectors::labelMap(false);
    }

    public function resetSectors(): void
    {
        $this->sectorMap = null;
    }

    /** @return array{0:?int, 1:?string} [id, error] */
    public function sector(string $text): array
    {
        return Sectors::resolve($text, $this->sectorMap());
    }

    public function siteId(string $name): ?int
    {
        return $this->remember('site:' . mb_strtolower($name), fn () => Sites::findBy('name', trim($name))['id'] ?? null);
    }

    /** Registra una planta recién creada para que las filas siguientes la encuentren. */
    public function rememberSite(string $name, int $id): void
    {
        $this->cache['site:' . mb_strtolower($name)] = $id;
    }

    /** Busca un puesto por nombre; si $create, lo crea cuando no existe. */
    public function positionId(string $name, bool $create): ?int
    {
        $key = 'position:' . mb_strtolower(trim($name));
        $id = $this->remember($key, fn () => Positions::findBy('name', trim($name))['id'] ?? null);
        if ($id === null && $create) {
            $id = $this->cache[$key] = Positions::create(['name' => trim($name)]);
        }
        return $id;
    }

    public function contractorId(string $name, bool $create): ?int
    {
        $key = 'contractor:' . mb_strtolower(trim($name));
        $id = $this->remember($key, fn () => Contractors::findBy('name', trim($name))['id'] ?? null);
        if ($id === null && $create) {
            $id = $this->cache[$key] = Contractors::create(['name' => trim($name)]);
        }
        return $id;
    }

    public function catalogId(string $catalog, string $name, bool $create): ?int
    {
        $key = 'cat:' . $catalog . ':' . mb_strtolower(trim($name));
        $id = $this->remember($key, fn () => CatalogItems::findByName($catalog, $name)['id'] ?? null);
        if ($id === null && $create) {
            $id = $this->cache[$key] = CatalogItems::create(['catalog' => $catalog, 'name' => trim($name), 'sort_order' => CatalogItems::nextSort($catalog)]);
        }
        return $id;
    }

    private function remember(string $key, callable $fn): mixed
    {
        if (!array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $fn();
        }
        return $this->cache[$key];
    }
}
