<?php
declare(strict_types=1);

namespace App\Resources;

use App\Core\Request;
use App\Models\CatalogItems;
use App\Models\Equipment;
use App\Models\Sectors;
use App\Models\Sites;

final class EquipmentResource extends Resource
{
    public function key(): string { return 'equipos'; }
    public function model(): string { return Equipment::class; }
    public function singular(): string { return 'Equipo'; }
    public function plural(): string { return 'Equipos'; }
    public function entity(): string { return 'equipment'; }
    public function sideView(): ?string { return 'panel/master/side_equipment'; }

    public function title(array $row): string
    {
        return $row['code'] . ' · ' . $row['name'];
    }

    public function fields(?array $current): array
    {
        return [
            ['name' => 'code', 'label' => 'Código interno', 'type' => 'text', 'required' => true, 'max' => 40, 'unique' => true, 'col' => 4,
                'help' => 'Es el que va impreso en la etiqueta QR.'],
            ['name' => 'name', 'label' => 'Nombre', 'type' => 'text', 'required' => true, 'max' => 120, 'col' => 8],
            ['name' => 'type_id', 'label' => 'Tipo', 'type' => 'ref', 'model' => CatalogItems::class, 'col' => 4,
                'options' => fn (?int $id) => CatalogItems::optionsFor('tipo_equipo', $id)],
            self::ref('site_id', 'Planta', Sites::class, false, ['col' => 4]),
            self::ref('sector_id', 'Sector', Sectors::class, false, ['col' => 4]),
            ['name' => 'brand', 'label' => 'Marca', 'type' => 'text', 'max' => 80, 'col' => 4],
            ['name' => 'model', 'label' => 'Modelo', 'type' => 'text', 'max' => 80, 'col' => 4],
            ['name' => 'serial_number', 'label' => 'N° de serie', 'type' => 'text', 'max' => 80, 'col' => 4],
            ['name' => 'year', 'label' => 'Año', 'type' => 'number', 'min' => 1900, 'max' => 2100, 'col' => 4],
            ['name' => 'status', 'label' => 'Estado', 'type' => 'select', 'options' => Equipment::STATUSES, 'required' => true, 'col' => 4, 'default' => 'operativo'],
            ['name' => 'notes', 'label' => 'Observaciones', 'type' => 'textarea', 'max' => 2000, 'col' => 12],
        ];
    }

    public function columns(): array
    {
        $statusClass = ['operativo' => 'success', 'fuera_servicio' => 'warning', 'baja' => 'secondary'];
        return [
            ['label' => 'Equipo', 'value' => fn ($r) => '<code>' . e($r['code']) . '</code> ' . e($r['name'])],
            ['label' => 'Tipo', 'value' => fn ($r) => e($r['type_name'])],
            ['label' => 'Ubicación', 'value' => fn ($r) => e(implode(' › ', array_filter([$r['site_name'], $r['sector_name']])))],
            ['label' => 'Estado', 'value' => fn ($r) => self::badge(Equipment::STATUSES[$r['status']] ?? $r['status'], $statusClass[$r['status']] ?? 'secondary')],
        ];
    }

    public function filterControls(): array
    {
        return [
            'tipo'   => ['Tipo', CatalogItems::optionsFor('tipo_equipo')],
            'estado' => ['Estado', Equipment::STATUSES],
        ];
    }

    public function rows(Request $request, bool $includeInactive): array
    {
        $filters = [];
        if (($t = CatalogItems::findByUuid((string) $request->input('tipo', ''))) !== null) {
            $filters['type_id'] = (int) $t['id'];
        }
        if (isset(Equipment::STATUSES[(string) $request->input('estado', '')])) {
            $filters['status'] = (string) $request->input('estado');
        }
        return Equipment::list((string) $request->input('q', ''), $includeInactive, $filters, 2000);
    }

    public function sideData(array $row): array
    {
        return ['qrUrl' => absolute_url('/q/' . $row['uuid'])];
    }
}
