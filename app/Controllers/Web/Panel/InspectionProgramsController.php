<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Equipment;
use App\Models\InspectionPrograms;
use App\Models\InspectionTemplates;
use App\Models\Roles;
use App\Models\Sectors;
use App\Services\ActionService;
use App\Services\InspectionPlanner;

/** Programas de inspección: qué, sobre qué, cada cuánto y quién. */
final class InspectionProgramsController
{
    public function index(Request $request): Response
    {
        return Response::html(View::render('panel/inspections/programs', [
            'title'    => 'Programas de inspección',
            'programs' => InspectionPrograms::all(),
        ], 'layouts/app'));
    }

    public function create(Request $request): Response
    {
        return $this->editor(null);
    }

    public function edit(Request $request, string $uuid): Response
    {
        $p = InspectionPrograms::findByUuid($uuid);
        return $p === null ? Response::html(View::render('errors/404', [], 'layouts/app'), 404) : $this->editor($p);
    }

    public function save(Request $request): Response
    {
        $uuid = (string) $request->input('uuid', '');
        $program = $uuid !== '' ? InspectionPrograms::findByUuid($uuid) : null;
        [$saved, $errors] = InspectionPlanner::save($program, $request->post);
        if ($errors) {
            Flash::add('danger', implode(' ', $errors));
            Flash::withInput(array_diff_key($request->post, ['csrf_token' => 1]));
            return Response::redirect($program ? '/panel/inspecciones/programas/' . $program['uuid'] : '/panel/inspecciones/programas/nuevo');
        }
        Flash::add('success', 'Programa guardado. Las inspecciones de los próximos días ya están en "Programadas".');
        return Response::redirect('/panel/inspecciones/programas');
    }

    public function toggle(Request $request, string $uuid): Response
    {
        $p = InspectionPrograms::findByUuid($uuid);
        if ($p !== null) {
            InspectionPlanner::setActive($p, !$p['is_active']);
            Flash::add('success', $p['is_active'] ? 'Programa pausado: no se generan nuevas inspecciones.' : 'Programa activado.');
        }
        return Response::redirect('/panel/inspecciones/programas');
    }

    private function editor(?array $p): Response
    {
        [$old] = Flash::pullInput();
        $v = fn (string $k, $default) => $old[$k] ?? $default;
        $preview = [];
        if ($p !== null && $p['frequency'] !== 'manual') {
            $today = ActionService::today();
            $preview = array_slice(InspectionPlanner::periods($p, $today, (new \DateTimeImmutable($today))->modify('+62 days')->format('Y-m-d')), 0, 5);
        }
        return Response::html(View::render('panel/inspections/program_edit', [
            'title'     => $p ? $p['name'] : 'Nuevo programa',
            'p'         => $p,
            'form'      => [
                'name' => $v('name', $p['name'] ?? ''), 'template' => $v('template', $p['template_uuid'] ?? ''),
                'frequency' => $v('frequency', $p['frequency'] ?? 'diaria'), 'weekday' => (int) $v('weekday', $p['weekday'] ?? 1),
                'monthday' => (int) $v('monthday', $p['monthday'] ?? 1), 'target_type' => $v('target_type', $p['target_type'] ?? 'tipo'),
                'equipment' => $v('equipment', $p['equipment_uuid'] ?? ''), 'sector' => $v('sector', $p['sector_uuid'] ?? ''),
                'assignee_type' => $v('assignee_type', $p['assignee_type'] ?? 'sector_supervisors'), 'assignee_user' => $v('assignee_user', $p['assignee_uuid'] ?? ''),
                'assignee_role' => $v('assignee_role', $p['assignee_role'] ?? ''), 'action_responsible' => $v('action_responsible', $p['action_responsible_uuid'] ?? ''),
            ],
            'templates' => InspectionTemplates::all(true),
            'equipment' => Equipment::list(null, false, [], 3000),
            'sectors'   => Sectors::options(),
            'users'     => ActionService::assignableUsers(),
            'roles'     => Roles::all(),
            'targets'   => $p ? InspectionPlanner::targets($p) : [],
            'preview'   => $preview,
        ], 'layouts/app'));
    }
}
