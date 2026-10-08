<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\CatalogItems;
use App\Models\Positions;

/**
 * Plantillas por rubro (database/seeds/templates/{clave}.php): precargan catálogos, puestos y checklists.
 * Aplicarlas es idempotente: agrega lo que falta por nombre y no modifica ni duplica nada.
 * Para sumar un rubro nuevo alcanza con agregar un archivo.
 */
final class IndustryTemplates
{
    private const DIR = '/database/seeds/templates';

    /** @return array<string, string> clave => nombre */
    public static function available(): array
    {
        $list = [];
        foreach (glob(BASE_PATH . self::DIR . '/*.php') ?: [] as $file) {
            $data = require $file;
            $list[basename($file, '.php')] = $data['name'];
        }
        return $list;
    }

    /** Solo los checklists de un rubro (desde Inspecciones → Plantillas). @return array{created:int, existing:int} */
    public static function applyInspections(string $key): array
    {
        if (!isset(self::available()[$key])) {
            throw new \DomainException('Plantilla inexistente.');
        }
        $template = require BASE_PATH . self::DIR . '/' . $key . '.php';
        $out = ['created' => 0, 'existing' => 0];
        foreach ($template['inspections'] ?? [] as $slug => $def) {
            InspectionTemplateService::importPreset($key . '/' . $slug, $def) ? $out['created']++ : $out['existing']++;
        }
        return $out;
    }

    /** Solo el EPP de un rubro (desde EPP → Catálogo). @return array{created:int, existing:int} */
    public static function applyPpe(string $key): array
    {
        if (!isset(self::available()[$key])) {
            throw new \DomainException('Plantilla inexistente.');
        }
        return self::importPpe($key, require BASE_PATH . self::DIR . '/' . $key . '.php');
    }

    /**
     * EPP precargado: elementos por preset_key y matriz para los puestos que existan. Solo agrega: no toca lo que la
     * empresa ya ajustó ni repite celdas existentes (aunque estén dadas de baja).
     */
    private static function importPpe(string $key, array $template): array
    {
        $out = ['created' => 0, 'existing' => 0];
        $ids = [];
        foreach ($template['epp']['items'] ?? [] as $slug => $def) {
            $preset = $key . '/' . $slug;
            $row = \App\Models\PpeItems::findByPreset($preset);
            if ($row !== null) {
                $ids[$slug] = (int) $row['id'];
                $out['existing']++;
                continue;
            }
            $ids[$slug] = \App\Models\PpeItems::create(['name' => $def['name'], 'category' => $def['category'], 'life_days' => $def['life_days'] ?? null,
                'size_type' => $def['size_type'] ?? null, 'certified' => isset($def['certification']) ? 1 : 0, 'certification' => $def['certification'] ?? null,
                'preset_key' => $preset]);
            $out['created']++;
        }
        $db = \App\Core\DB::tenant();
        $exists = $db->prepare('SELECT COUNT(*) FROM ppe_matrix WHERE position_id = ? AND item_id = ?');
        foreach ($template['epp']['matrix'] ?? [] as $positionName => $cells) {
            $position = Positions::findBy('name', $positionName);
            if ($position === null) {
                continue;
            }
            foreach ($cells as $cell) {
                $mandatory = !str_starts_with($cell, '?');
                [$slug, $qty] = array_pad(explode(':', ltrim($cell, '?')), 2, '1');
                if (!isset($ids[$slug])) {
                    continue;
                }
                $exists->execute([(int) $position['id'], $ids[$slug]]);
                if ((int) $exists->fetchColumn() > 0) {
                    $out['existing']++;
                    continue;
                }
                \App\Models\Ppe::setMatrix((int) $position['id'], $ids[$slug], ['quantity' => (int) $qty, 'life_days' => null, 'mandatory' => $mandatory, 'notes' => null]);
                $out['created']++;
            }
        }
        return $out;
    }

    /** @return array{created:int, existing:int} */
    public static function apply(string $key): array
    {
        if (!isset(self::available()[$key])) {
            throw new \DomainException('Plantilla inexistente.');
        }
        $template = require BASE_PATH . self::DIR . '/' . $key . '.php';
        $created = 0;
        $existing = 0;
        foreach ($template['catalogs'] as $catalog => $items) {
            foreach ($items as $i => $item) {
                $item = is_array($item) ? $item : ['name' => $item];
                if (CatalogItems::nameExists($catalog, $item['name'])) {
                    $existing++;
                    continue;
                }
                CatalogItems::create([
                    'catalog' => $catalog, 'name' => $item['name'], 'code' => $item['code'] ?? null,
                    'description' => $item['description'] ?? null, 'color' => $item['color'] ?? null,
                    'level' => $item['level'] ?? null, 'sort_order' => ($i + 1) * 10,
                ]);
                $created++;
            }
        }
        foreach ($template['positions'] ?? [] as $name) {
            if (Positions::exists('name', $name)) {
                $existing++;
                continue;
            }
            Positions::create(['name' => $name]);
            $created++;
        }
        foreach ($template['inspections'] ?? [] as $slug => $def) {
            InspectionTemplateService::importPreset($key . '/' . $slug, $def) ? $created++ : $existing++;
        }
        $ppe = self::importPpe($key, $template);
        $created += $ppe['created'];
        $existing += $ppe['existing'];
        Audit::tenant('template.apply', 'template', null, null, ['plantilla' => $template['name'], 'creados' => $created]);
        return ['created' => $created, 'existing' => $existing];
    }
}
