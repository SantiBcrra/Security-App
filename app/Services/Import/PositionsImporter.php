<?php
declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Positions;

final class PositionsImporter extends EntityImporter
{
    public function key(): string { return 'puestos'; }
    public function label(): string { return 'Puestos de trabajo'; }

    public function columns(): array
    {
        return [
            'nombre'      => ['label' => 'Puesto', 'required' => true, 'synonyms' => ['nombre', 'cargo', 'funcion']],
            'descripcion' => ['label' => 'Descripción', 'synonyms' => ['detalle', 'tareas']],
        ];
    }

    public function rowKey(array $row): ?string
    {
        $name = mb_strtolower(trim((string) ($row['nombre'] ?? '')));
        return $name !== '' ? $name : null;
    }

    public function example(): array
    {
        return ['Soldador', 'Soldadura MIG/MAG y eléctrica en nave A'];
    }

    public function validate(array $row, ImportContext $ctx): array
    {
        if ($this->rowKey($row) === null) {
            return self::result(['Falta el nombre del puesto.'], 'create');
        }
        if (mb_strlen(trim($row['nombre'])) > 120) {
            return self::result(['Nombre demasiado largo (máx. 120).'], 'create');
        }
        return self::result([], Positions::findBy('name', trim($row['nombre'])) ? 'update' : 'create');
    }

    public function apply(array $row, ImportContext $ctx): string
    {
        $existing = Positions::findBy('name', trim($row['nombre']));
        $data = self::nonEmpty(['name' => trim($row['nombre']), 'description' => trim((string) ($row['descripcion'] ?? ''))]);
        if ($existing) {
            Positions::update((int) $existing['id'], $data + ['is_active' => 1]);
            return 'updated';
        }
        Positions::create($data);
        return 'created';
    }
}
