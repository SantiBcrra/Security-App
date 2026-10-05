<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\CatalogItems;
use App\Models\Positions;

/**
 * Plantillas por rubro (database/seeds/templates/{clave}.php): precargan catálogos y puestos.
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
        Audit::tenant('template.apply', 'template', null, null, ['plantilla' => $template['name'], 'creados' => $created]);
        return ['created' => $created, 'existing' => $existing];
    }
}
