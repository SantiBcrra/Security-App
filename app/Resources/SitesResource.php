<?php
declare(strict_types=1);

namespace App\Resources;

use App\Models\Sites;

final class SitesResource extends Resource
{
    public function key(): string { return 'plantas'; }
    public function model(): string { return Sites::class; }
    public function singular(): string { return 'Planta'; }
    public function plural(): string { return 'Plantas'; }
    public function entity(): string { return 'site'; }
    public function newLabel(): string { return 'Nueva planta'; }

    public function fields(?array $current): array
    {
        return [
            ['name' => 'name', 'label' => 'Nombre', 'type' => 'text', 'required' => true, 'max' => 120, 'unique' => true, 'col' => 8],
            ['name' => 'code', 'label' => 'Código', 'type' => 'text', 'max' => 30, 'col' => 4],
            ['name' => 'address', 'label' => 'Dirección', 'type' => 'text', 'max' => 191, 'col' => 12],
            ['name' => 'city', 'label' => 'Localidad', 'type' => 'text', 'max' => 100],
            ['name' => 'province', 'label' => 'Provincia', 'type' => 'text', 'max' => 100],
            ['name' => 'lat', 'label' => 'Latitud', 'type' => 'decimal', 'col' => 4, 'help' => 'Opcional: centro de la geocerca'],
            ['name' => 'lng', 'label' => 'Longitud', 'type' => 'decimal', 'col' => 4],
            ['name' => 'geofence_radius_m', 'label' => 'Radio (metros)', 'type' => 'number', 'min' => 10, 'max' => 50000, 'col' => 4],
        ];
    }

    public function columns(): array
    {
        return [
            ['label' => 'Planta', 'value' => fn ($r) => e($r['name']) . ($r['code'] ? ' <span class="text-body-secondary small">' . e($r['code']) . '</span>' : '')],
            ['label' => 'Ubicación', 'value' => fn ($r) => e(implode(', ', array_filter([$r['address'], $r['city'], $r['province']])))],
            ['label' => 'Geocerca', 'value' => fn ($r) => $r['lat'] !== null && $r['geofence_radius_m'] ? e($r['geofence_radius_m'] . ' m') : '—'],
        ];
    }

    protected function extraValidate(array $data, ?array $current, array $errors): array
    {
        $lat = trim((string) ($data['lat'] ?? ''));
        $lng = trim((string) ($data['lng'] ?? ''));
        if (($lat === '') !== ($lng === '')) {
            $errors['lat'] ??= 'Cargá latitud y longitud juntas (o ninguna).';
        } elseif ($lat !== '' && (abs((float) str_replace(',', '.', $lat)) > 90 || abs((float) str_replace(',', '.', $lng)) > 180)) {
            $errors['lat'] ??= 'Coordenadas fuera de rango.';
        }
        return $errors;
    }
}
