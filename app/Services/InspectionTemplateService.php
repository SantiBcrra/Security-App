<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Models\CatalogItems;
use App\Models\InspectionTemplates;

/**
 * Plantillas de checklist. Guardar una plantilla cuya versión vigente ya se usó crea una versión
 * nueva (las inspecciones viejas quedan atadas a la suya); si todavía no se usó, se corrige en el lugar.
 */
final class InspectionTemplateService
{
    public const SCOPES = ['equipo' => 'Un tipo de equipo', 'sector' => 'Un sector / área', 'general' => 'General'];

    /**
     * @param array $in name, description, scope, equipment_type (uuid), structure (array o JSON)
     * @return array{0: ?array, 1: list<string>} [plantilla, errores]
     */
    public static function save(?array $template, array $in): array
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 3) {
            $errors[] = 'Poné un nombre a la plantilla.';
        }
        $scope = isset(self::SCOPES[$in['scope'] ?? '']) ? $in['scope'] : 'general';
        $typeId = null;
        if ($scope === 'equipo') {
            $type = ($in['equipment_type'] ?? '') !== '' ? CatalogItems::findByUuid((string) $in['equipment_type']) : null;
            if ($type === null || $type['catalog'] !== 'tipo_equipo') {
                $errors[] = 'Elegí el tipo de equipo.';
            } else {
                $typeId = (int) $type['id'];
            }
        }
        $raw = $in['structure'] ?? [];
        if (is_string($raw)) {
            $raw = json_decode($raw, true) ?: [];
        }
        [$structure, $count, $structErrors] = InspectionStructure::normalize($raw);
        $errors = array_merge($errors, $structErrors);
        if ($errors) {
            return [null, $errors];
        }
        $meta = ['name' => mb_substr($name, 0, 160), 'description' => trim((string) ($in['description'] ?? '')) ?: null,
            'scope' => $scope, 'equipment_type_id' => $typeId];
        $me = UserAuth::user()['id'] ?? null;
        $db = DB::tenant();
        $db->beginTransaction();
        try {
            if ($template === null) {
                $id = InspectionTemplates::create($meta + ['preset_key' => $in['preset_key'] ?? null]);
                InspectionTemplates::update($id, ['current_version_id' => InspectionTemplates::addVersion($id, $structure, $count, $me)]);
                $action = 'inspection_template.create';
            } else {
                $id = (int) $template['id'];
                $current = InspectionTemplates::version((int) $template['current_version_id']);
                $changes = $meta;
                if ($current === null || json_encode($current['structure']) !== json_encode($structure)) {
                    if ($current !== null && !InspectionTemplates::versionUsed((int) $current['id'])) {
                        InspectionTemplates::replaceVersionStructure((int) $current['id'], $structure, $count);
                    } else {
                        $changes['current_version_id'] = InspectionTemplates::addVersion($id, $structure, $count, $me);
                    }
                }
                InspectionTemplates::update($id, $changes);
                $action = 'inspection_template.update';
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        $fresh = InspectionTemplates::findById($id);
        Audit::tenant($action, 'inspection_template', $fresh['uuid'], null, ['nombre' => $fresh['name'], 'version' => (int) $fresh['current_version'], 'items' => $count]);
        return [$fresh, []];
    }

    public static function setActive(array $template, bool $active): void
    {
        InspectionTemplates::update((int) $template['id'], ['is_active' => $active ? 1 : 0]);
        Audit::tenant($active ? 'inspection_template.activate' : 'inspection_template.deactivate', 'inspection_template', $template['uuid']);
    }

    /**
     * Carga una plantilla precargada de rubro (database/seeds/templates/{rubro}.php → 'inspections').
     * Idempotente por preset_key: si ya está, no la toca (la empresa pudo haberla ajustado).
     * @return bool true si la creó
     */
    public static function importPreset(string $presetKey, array $def): bool
    {
        if (InspectionTemplates::findByPreset($presetKey) !== null) {
            return false;
        }
        $type = isset($def['equipment_type']) ? CatalogItems::findByName('tipo_equipo', $def['equipment_type']) : null;
        $sections = [];
        foreach ($def['sections'] as $title => $items) {
            $sections[] = ['title' => $title, 'items' => array_map(function ($it) {
                $it = is_array($it) ? $it : ['text' => $it];
                $text = $it['text'] ?? $it[0];
                $critical = str_starts_with($text, '*');
                return ['text' => ltrim($text, '* '), 'critical' => $critical || !empty($it['critical']), 'type' => $it['type'] ?? 'si_no_na',
                    'ok_when' => $it['ok_when'] ?? 'si', 'photo' => $it['photo'] ?? ($critical ? 'si_no_cumple' : 'nunca'),
                    'min' => $it['min'] ?? null, 'max' => $it['max'] ?? null, 'unit' => $it['unit'] ?? null, 'help' => $it['help'] ?? null];
            }, $items)];
        }
        [, $errors] = self::save(null, ['name' => $def['name'], 'description' => $def['description'] ?? null,
            'scope' => $type ? 'equipo' : ($def['scope'] ?? 'general'), 'equipment_type' => $type['uuid'] ?? '',
            'structure' => ['sections' => $sections], 'preset_key' => $presetKey]);
        if ($errors) {
            throw new \DomainException('Plantilla precargada inválida (' . $presetKey . '): ' . implode(' ', $errors));
        }
        return true;
    }
}
