<?php
declare(strict_types=1);

namespace App\Resources;

use App\Core\Request;
use App\Models\CatalogItems;
use App\Services\Catalogs;

/** Un catálogo configurable (tipos de riesgo, severidades...). Todos comparten la tabla catalog_items. */
final class CatalogResource extends Resource
{
    public function __construct(private readonly string $catalog)
    {
    }

    private function def(): array { return Catalogs::DEFINITIONS[$this->catalog]; }

    public function key(): string { return $this->def()['slug']; }
    public function model(): string { return CatalogItems::class; }
    public function singular(): string { return $this->def()['singular']; }
    public function plural(): string { return $this->def()['plural']; }
    public function entity(): string { return 'catalog_item'; }
    public function newLabel(): string { return $this->def()['new']; }
    public function indexView(): string { return 'panel/master/catalogs'; }
    public function catalog(): string { return $this->catalog; }
    public function fixedValues(): array { return ['catalog' => $this->catalog]; }

    public function fields(?array $current): array
    {
        $fields = [
            ['name' => 'name', 'label' => 'Nombre', 'type' => 'text', 'required' => true, 'max' => 120, 'unique' => true, 'col' => 8],
            ['name' => 'code', 'label' => 'Código', 'type' => 'text', 'max' => 30, 'col' => 4],
            ['name' => 'description', 'label' => 'Descripción', 'type' => 'text', 'max' => 255, 'col' => 12],
        ];
        if (!empty($this->def()['levels'])) {
            $fields[] = ['name' => 'level', 'label' => 'Nivel (1 = menor)', 'type' => 'number', 'min' => 1, 'max' => 5, 'required' => true, 'col' => 4];
            $fields[] = ['name' => 'color', 'label' => 'Color', 'type' => 'color', 'col' => 4, 'default' => '#6c757d'];
        }
        $fields[] = ['name' => 'sort_order', 'label' => 'Orden', 'type' => 'number', 'min' => 0, 'max' => 100000, 'col' => 4,
            'help' => 'Menor = aparece primero'];
        return $fields;
    }

    public function columns(): array
    {
        $cols = [['label' => $this->singular(), 'value' => fn ($r) => !empty($r['color'])
            ? '<span class="badge" style="background:' . e($r['color']) . '">' . e($r['name']) . '</span>' : e($r['name'])]];
        if (!empty($this->def()['levels'])) {
            $cols[] = ['label' => 'Nivel', 'value' => fn ($r) => e($r['level'])];
        }
        $cols[] = ['label' => 'Descripción', 'value' => fn ($r) => e($r['description'])];
        return $cols;
    }

    protected function isDuplicate(string $column, mixed $value, ?array $current): bool
    {
        return $column === 'name' && CatalogItems::nameExists($this->catalog, (string) $value, $current ? (int) $current['id'] : null);
    }

    public function toRow(array $data, ?array $current): array
    {
        $row = parent::toRow($data, $current);
        $row['sort_order'] ??= $current['sort_order'] ?? CatalogItems::nextSort($this->catalog);
        return $row;
    }

    public function rows(Request $request, bool $includeInactive): array
    {
        return CatalogItems::list((string) $request->input('q', ''), $includeInactive, ['catalog' => $this->catalog]);
    }
}
