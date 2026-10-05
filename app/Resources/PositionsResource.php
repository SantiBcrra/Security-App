<?php
declare(strict_types=1);

namespace App\Resources;

use App\Models\Positions;

final class PositionsResource extends Resource
{
    public function key(): string { return 'puestos'; }
    public function model(): string { return Positions::class; }
    public function singular(): string { return 'Puesto'; }
    public function plural(): string { return 'Puestos de trabajo'; }
    public function entity(): string { return 'position'; }

    public function fields(?array $current): array
    {
        return [
            ['name' => 'name', 'label' => 'Nombre', 'type' => 'text', 'required' => true, 'max' => 120, 'unique' => true, 'col' => 12],
            ['name' => 'description', 'label' => 'Descripción', 'type' => 'textarea', 'max' => 255, 'col' => 12],
        ];
    }

    public function columns(): array
    {
        return [
            ['label' => 'Puesto', 'value' => fn ($r) => e($r['name'])],
            ['label' => 'Descripción', 'value' => fn ($r) => e($r['description'])],
        ];
    }
}
