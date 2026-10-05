<?php
declare(strict_types=1);

namespace App\Resources;

use App\Core\Request;
use App\Models\Sectors;
use App\Models\Sites;

/** Sectores jerárquicos: planta > nave > sector. Se muestran como árbol. */
final class SectorsResource extends Resource
{
    public function key(): string { return 'sectores'; }
    public function model(): string { return Sectors::class; }
    public function singular(): string { return 'Sector'; }
    public function plural(): string { return 'Sectores'; }
    public function entity(): string { return 'sector'; }
    public function indexView(): string { return 'panel/master/sectors'; }

    public function fields(?array $current): array
    {
        return [
            self::ref('site_id', 'Planta', Sites::class, true, ['col' => 6]),
            ['name' => 'parent_id', 'label' => 'Depende de', 'type' => 'ref', 'model' => Sectors::class, 'col' => 6,
                'options' => fn (?int $id) => self::parentOptions($current),
                'help' => 'Vacío = sector principal de la planta (por ejemplo una nave).'],
            ['name' => 'name', 'label' => 'Nombre', 'type' => 'text', 'required' => true, 'max' => 120, 'col' => 8],
            ['name' => 'code', 'label' => 'Código', 'type' => 'text', 'max' => 30, 'col' => 4],
        ];
    }

    /** Posibles padres: sectores activos que no sean el propio ni sus descendientes ni del último nivel. */
    private static function parentOptions(?array $current): array
    {
        $options = [];
        foreach (Sectors::labelMap(false) as $id => $s) {
            if ($s['depth'] >= Sectors::MAX_DEPTH) {
                continue;
            }
            if ($current && str_starts_with($s['path'], $current['path'])) {
                continue;
            }
            $options[$s['uuid']] = $s['label'];
        }
        if ($current && $current['parent_id']) {
            $parent = Sectors::findById((int) $current['parent_id']);
            $options[$parent['uuid']] ??= $parent['name'];
        }
        return $options;
    }

    public function columns(): array
    {
        return [['label' => 'Sector', 'value' => fn ($r) => e($r['name'])]];
    }

    public function title(array $row): string
    {
        return Sectors::labelMap()[(int) $row['id']]['label'] ?? $row['name'];
    }

    public function rows(Request $request, bool $includeInactive): array
    {
        return Sectors::list((string) $request->input('q', ''), $includeInactive, [], 5000);
    }

    protected function extraValidate(array $data, ?array $current, array $errors): array
    {
        if ($errors) {
            return $errors;
        }
        $site = Sites::findByUuid((string) $data['site_id']);
        $parent = !empty($data['parent_id']) ? Sectors::findByUuid((string) $data['parent_id']) : null;
        if ($parent && (int) $parent['site_id'] !== (int) $site['id']) {
            $errors['parent_id'] = 'Depende de: tiene que ser un sector de la misma planta.';
        } elseif ($parent && $current && str_starts_with($parent['path'], $current['path'])) {
            $errors['parent_id'] = 'Depende de: un sector no puede depender de sí mismo ni de un sector que está debajo de él.';
        } else {
            $height = $current ? Sectors::subtreeHeight($current) : 0;
            $depth = ($parent ? (int) $parent['depth'] + 1 : 1) + $height;
            if ($depth > Sectors::MAX_DEPTH) {
                $errors['parent_id'] = 'Depende de: se permiten hasta ' . Sectors::MAX_DEPTH . ' niveles (planta > nave > sector).';
            }
        }
        // Nombre único entre hermanos
        $siblings = Sectors::list(null, true, ['site_id' => (int) $site['id'], 'parent_id' => $parent ? (int) $parent['id'] : null]);
        foreach ($siblings as $s) {
            if (mb_strtolower($s['name']) === mb_strtolower(trim((string) $data['name'])) && (!$current || (int) $s['id'] !== (int) $current['id'])) {
                $errors['name'] = 'Nombre: ya hay un sector con ese nombre en el mismo lugar.';
            }
        }
        return $errors;
    }

    public function save(array $row, ?array $current): int
    {
        if ($current === null) {
            return Sectors::create($row);
        }
        $siteId = (int) $row['site_id'];
        $parentId = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        if ($siteId !== (int) $current['site_id'] || $parentId !== ($current['parent_id'] !== null ? (int) $current['parent_id'] : null)) {
            Sectors::move((int) $current['id'], $siteId, $parentId);
        }
        Sectors::update((int) $current['id'], ['name' => $row['name'], 'code' => $row['code']]);
        return (int) $current['id'];
    }

    public function canDeactivate(array $row): ?string
    {
        $children = Sectors::activeChildrenCount((int) $row['id']);
        return $children > 0 ? "Tiene {$children} sector(es) activo(s) debajo: desactivalos primero." : null;
    }
}
