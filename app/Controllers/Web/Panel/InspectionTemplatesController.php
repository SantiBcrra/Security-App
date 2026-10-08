<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\CatalogItems;
use App\Models\InspectionTemplates;
use App\Services\IndustryTemplates;
use App\Services\InspectionTemplateService;

/** Plantillas de checklist: constructor, versiones y precargadas por rubro. */
final class InspectionTemplatesController
{
    public function index(Request $request): Response
    {
        return Response::html(View::render('panel/inspections/templates', [
            'title'      => 'Plantillas de checklist',
            'templates'  => InspectionTemplates::all(),
            'industries' => IndustryTemplates::available(),
        ], 'layouts/app'));
    }

    public function create(Request $request): Response
    {
        return $this->editor(null);
    }

    public function edit(Request $request, string $uuid): Response
    {
        $t = InspectionTemplates::findByUuid($uuid);
        return $t === null ? Response::html(View::render('errors/404', [], 'layouts/app'), 404) : $this->editor($t);
    }

    public function save(Request $request): Response
    {
        $uuid = (string) $request->input('uuid', '');
        $template = $uuid !== '' ? InspectionTemplates::findByUuid($uuid) : null;
        $previousVersion = $template['current_version'] ?? null;
        [$saved, $errors] = InspectionTemplateService::save($template, $request->post);
        if ($errors) {
            Flash::add('danger', implode(' ', $errors));
            Flash::withInput(array_diff_key($request->post, ['csrf_token' => 1]));
            return Response::redirect($template ? '/panel/inspecciones/plantillas/' . $template['uuid'] : '/panel/inspecciones/plantillas/nueva');
        }
        $newVersion = $previousVersion !== null && (int) $saved['current_version'] !== (int) $previousVersion;
        Flash::add('success', 'Plantilla guardada' . ($newVersion ? ' como versión ' . $saved['current_version'] . ' (las inspecciones anteriores conservan su versión).' : '.'));
        return Response::redirect('/panel/inspecciones/plantillas/' . $saved['uuid']);
    }

    public function toggle(Request $request, string $uuid): Response
    {
        $t = InspectionTemplates::findByUuid($uuid);
        if ($t !== null) {
            InspectionTemplateService::setActive($t, !$t['is_active']);
            Flash::add('success', $t['is_active'] ? 'Plantilla desactivada.' : 'Plantilla activada.');
        }
        return Response::redirect('/panel/inspecciones/plantillas');
    }

    public function presets(Request $request): Response
    {
        try {
            $r = IndustryTemplates::applyInspections((string) $request->input('industry', ''));
            Flash::add('success', "Plantillas cargadas: {$r['created']} nuevas" . ($r['existing'] ? ", {$r['existing']} ya estaban." : '.'));
        } catch (\DomainException $e) {
            Flash::add('danger', $e->getMessage());
        }
        return Response::redirect('/panel/inspecciones/plantillas');
    }

    private function editor(?array $t): Response
    {
        [$old] = Flash::pullInput();
        $version = $t ? InspectionTemplates::version((int) $t['current_version_id']) : null;
        $structure = isset($old['structure']) ? (json_decode((string) $old['structure'], true) ?: null) : null;
        return Response::html(View::render('panel/inspections/template_edit', [
            'title'     => $t ? $t['name'] : 'Nueva plantilla',
            't'         => $t,
            'form'      => [
                'name' => $old['name'] ?? $t['name'] ?? '', 'description' => $old['description'] ?? $t['description'] ?? '',
                'scope' => $old['scope'] ?? $t['scope'] ?? 'equipo', 'equipment_type' => $old['equipment_type'] ?? $t['equipment_type_uuid'] ?? '',
            ],
            'structure' => $structure ?? ($version['structure'] ?? ['sections' => [['title' => 'Control', 'items' => []]]]),
            'versions'  => $t ? InspectionTemplates::versions((int) $t['id']) : [],
            'types'     => CatalogItems::optionsFor('tipo_equipo'),
        ], 'layouts/app'));
    }
}
