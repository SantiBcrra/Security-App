<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Tenant;
use App\Core\TenantFiles;
use App\Core\View;
use App\Models\ActionAttachments;
use App\Models\ActionEvents;
use App\Models\Actions;
use App\Models\Observations;
use App\Models\Sectors;
use App\Models\Users;
use App\Services\ActionService;
use App\Services\ActionWorkflow;
use App\Services\ObservationService;
use App\Services\UserAuth;

/** Acciones correctivas y preventivas (CAPA). */
final class ActionsController
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        [$filters, $form] = $this->filters($request);
        $scope = ActionService::scope();
        $page = max(1, (int) $request->input('pagina', 1));
        return Response::html(View::render('panel/actions/index', [
            'title'    => 'Acciones',
            'rows'     => Actions::search($filters, $scope, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total'    => Actions::count($filters, $scope),
            'byStatus' => Actions::countByStatus($filters, $scope, ActionService::today()),
            'form'     => $form,
            'page'     => $page,
            'perPage'  => self::PER_PAGE,
            'today'    => ActionService::today(),
            'users'    => ActionService::assignableUsers(),
            'sectors'  => Sectors::options(),
        ], 'layouts/app'));
    }

    /** Tablero: vencidas por sector y por responsable, prioridades, verificación y tiempos de cierre. */
    public function board(Request $request): Response
    {
        $today = ActionService::today();
        return Response::html(View::render('panel/actions/board', [
            'title'   => 'Tablero de acciones',
            'b'       => Actions::board(ActionService::scope(), $today),
            'sectors' => Sectors::labelMap(),
            'today'   => $today,
        ], 'layouts/app'));
    }

    /** CSV con los mismos filtros que la lista (máx. 5000 filas). */
    public function export(Request $request): Response
    {
        [$filters] = $this->filters($request);
        $rows = Actions::search($filters, ActionService::scope(), 5000);
        \App\Services\Audit::tenant('action.export', 'action', null, null, ['filas' => count($rows)]);
        return new Response(ActionService::csv($rows, ActionService::today()), 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="acciones-' . fecha(gmdate('Y-m-d H:i:s'), 'Y-m-d') . '.csv"',
        ]);
    }

    public function printable(Request $request, string $uuid): Response
    {
        $a = $this->find($uuid);
        if ($a === null) {
            return self::notFound();
        }
        return Response::html(View::render('panel/actions/print', [
            'a'      => $a,
            'origin' => $this->origin($a),
            'events' => ActionEvents::forAction((int) $a['id']),
            'files'  => ActionAttachments::forAction((int) $a['id']),
            'today'  => ActionService::today(),
        ], null));
    }

    public function create(Request $request): Response
    {
        [$old, $errors] = Flash::pullInput();
        return Response::html(View::render('panel/actions/form', [
            'title'   => 'Nueva acción',
            'old'     => $old + ['title' => '', 'description' => '', 'type' => 'correctiva', 'priority' => 'media', 'responsible' => '',
                'due_on' => '', 'sector' => '', 'origin' => 'manual'],
            'errors'  => $errors,
            'users'   => ActionService::assignableUsers(),
            'sectors' => Sectors::options(),
        ], 'layouts/app'));
    }

    public function store(Request $request): Response
    {
        $in = $request->post;
        [$data, $errors] = ActionService::validate($in);
        $origin = in_array($in['origin'] ?? '', ['manual', 'auditoria'], true) ? $in['origin'] : 'manual';
        $files = $this->uploadedFiles($request);
        if (count($files) > ActionService::MAX_FILES) {
            $errors['files'] = 'Máximo ' . ActionService::MAX_FILES . ' archivos.';
        }
        if ($errors) {
            unset($in['csrf_token']);
            Flash::withInput($in, $errors);
            return Response::redirect('/panel/acciones/nueva');
        }
        $result = ActionService::create($data, $origin, null, $files);
        foreach ($result['file_errors'] as $err) {
            Flash::add('warning', 'No se guardó un archivo: ' . $err);
        }
        Flash::add('success', 'Acción ' . Actions::format((int) $result['action']['number']) . ' creada.');
        return Response::redirect('/panel/acciones/' . $result['action']['uuid']);
    }

    public function show(Request $request, string $uuid): Response
    {
        $a = $this->find($uuid);
        if ($a === null) {
            return self::notFound();
        }
        [$old, $errors] = Flash::pullInput();
        return Response::html(View::render('panel/actions/show', [
            'title'       => Actions::format((int) $a['number']),
            'a'           => $a,
            'origin'      => $this->origin($a),
            'events'      => ActionEvents::forAction((int) $a['id']),
            'files'       => ActionAttachments::forAction((int) $a['id']),
            'transitions' => ActionWorkflow::available($a),
            'canEdit'     => ActionService::canEdit($a),
            'canEvidence' => ActionService::canAddEvidence($a),
            'users'       => ActionService::assignableUsers(),
            'today'       => ActionService::today(),
            'old'         => $old,
            'errors'      => $errors,
        ], 'layouts/app'));
    }

    public function transition(Request $request, string $uuid, string $key): Response
    {
        $a = $this->find($uuid);
        if ($a === null) {
            return self::notFound();
        }
        $error = ActionService::transition($a, $key, $request->post, $this->uploadedFiles($request));
        if ($error) {
            Flash::add('danger', $error);
            Flash::withInput(['transition' => $key] + array_diff_key($request->post, ['csrf_token' => 1]));
        } else {
            Flash::add('success', 'Acción actualizada: ' . ActionWorkflow::label(ActionWorkflow::TRANSITIONS[$key]['to']) . '.');
        }
        return Response::redirect('/panel/acciones/' . $uuid);
    }

    public function update(Request $request, string $uuid): Response
    {
        $a = $this->find($uuid);
        if ($a === null) {
            return self::notFound();
        }
        $error = ActionService::update($a, $request->post);
        if ($error) {
            Flash::add('danger', $error);
            Flash::withInput(['transition' => 'editar'] + array_diff_key($request->post, ['csrf_token' => 1]));
        } else {
            Flash::add('success', 'Acción actualizada.');
        }
        return Response::redirect('/panel/acciones/' . $uuid);
    }

    public function comment(Request $request, string $uuid): Response
    {
        $a = $this->find($uuid);
        if ($a === null) {
            return self::notFound();
        }
        $error = ActionService::comment($a, (string) $request->input('comment', ''));
        Flash::add($error ? 'danger' : 'success', $error ?? 'Comentario agregado.');
        return Response::redirect('/panel/acciones/' . $uuid . '#linea-de-tiempo');
    }

    public function evidence(Request $request, string $uuid): Response
    {
        $a = $this->find($uuid);
        if ($a === null) {
            return self::notFound();
        }
        $files = $this->uploadedFiles($request);
        if (!$files) {
            Flash::add('warning', 'No elegiste ningún archivo.');
        } else {
            $errors = ActionService::addEvidence($a, $files);
            foreach ($errors as $err) {
                Flash::add('warning', $err);
            }
            if (count($errors) < count($files)) {
                Flash::add('success', 'Evidencia agregada.');
            }
        }
        return Response::redirect('/panel/acciones/' . $uuid);
    }

    /** Archivo (o miniatura) servido por PHP: valida empresa, permiso y alcance. */
    public function file(Request $request, string $uuid, string $attachment): Response
    {
        $a = $this->find($uuid);
        $att = $a ? ActionAttachments::find((int) $a['id'], $attachment) : null;
        if ($att === null) {
            return new Response('', 404);
        }
        $path = $request->input('t') === '1' && $att['thumb_path'] ? $att['thumb_path'] : $att['path'];
        $file = TenantFiles::path(Tenant::current()['uuid'], $path);
        return $file ? TenantFiles::response($file, 3600) : new Response('', 404);
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function find(string $uuid): ?array
    {
        $a = Actions::findByUuid($uuid);
        return $a !== null && ActionService::canView($a) ? $a : null;
    }

    /** Origen de la acción, con link si el usuario lo puede ver. */
    private function origin(array $a): array
    {
        $label = ActionWorkflow::ORIGINS[$a['origin_type']] ?? $a['origin_type'];
        if ($a['origin_type'] === 'observacion' && $a['origin_id'] !== null && ($obs = Observations::findById((int) $a['origin_id']))) {
            return ['label' => $label . ' ' . Observations::format((int) $obs['number']),
                'url' => ObservationService::canView($obs) ? '/panel/observaciones/' . $obs['uuid'] : null];
        }
        if ($a['origin_type'] === 'incidente' && $a['origin_id'] !== null && ($inc = \App\Models\Incidents::findById((int) $a['origin_id']))) {
            return ['label' => $label . ' ' . \App\Models\Incidents::format((int) $inc['number']) . ' · ' . \App\Services\IncidentService::typeLabel($inc['type']),
                'url' => \App\Services\IncidentService::canView($inc) ? '/panel/incidentes/' . $inc['uuid'] : null];
        }
        if ($a['origin_type'] === 'inspeccion' && $a['origin_id'] !== null && ($ins = \App\Models\Inspections::findById((int) $a['origin_id']))) {
            return ['label' => $label . ' ' . \App\Models\Inspections::format((int) $ins['number']) . ' · ' . $ins['template_name'],
                'url' => \App\Services\InspectionService::canView($ins) ? '/panel/inspecciones/' . $ins['uuid'] : null];
        }
        return ['label' => $label, 'url' => null];
    }

    /** $_FILES['files'] (múltiple) → lista normalizada. */
    private function uploadedFiles(Request $request): array
    {
        $files = $request->files['files'] ?? null;
        if (!is_array($files) || !isset($files['tmp_name'])) {
            return [];
        }
        $list = [];
        foreach ((array) $files['tmp_name'] as $i => $tmp) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($tmp)) {
                $list[] = ['tmp' => $tmp, 'name' => $files['name'][$i] ?? null, 'upload' => true];
            }
        }
        return $list;
    }

    /** Filtros del listado (form = valores para repintar el formulario). */
    private function filters(Request $request): array
    {
        $form = [];
        foreach (['estado', 'mias', 'responsable', 'sector', 'origen', 'prioridad', 'q'] as $k) {
            $form[$k] = (string) $request->input($k, '');
        }
        if (!array_key_exists('estado', $request->query)) { // por defecto: pendientes
            $form['estado'] = 'pendientes';
        }
        $f = ['q' => $form['q']];
        if (isset(ActionWorkflow::STATES[$form['estado']])) {
            $f['status'] = $form['estado'];
        } elseif ($form['estado'] === 'pendientes') {
            $f['status'] = Actions::OPEN;
        } elseif ($form['estado'] === 'vencidas') {
            $f['overdue'] = ActionService::today();
        }
        if ($form['mias'] === '1' && UserAuth::user()) {
            $f['responsible_user_id'] = (int) UserAuth::user()['id'];
        } elseif ($form['responsable'] !== '' && ($u = Users::findByUuid($form['responsable'])) !== null) {
            $f['responsible_user_id'] = (int) $u['id'];
        }
        if ($form['sector'] !== '' && ($s = Sectors::findByUuid($form['sector'])) !== null) {
            $f['sector_ids'] = Sectors::withDescendants([(int) $s['id']]);
        }
        if (isset(ActionWorkflow::ORIGINS[$form['origen']])) {
            $f['origin_type'] = $form['origen'];
        }
        if (isset(ActionWorkflow::PRIORITIES[$form['prioridad']])) {
            $f['priority'] = $form['prioridad'];
        }
        return [$f, $form];
    }

    private static function notFound(): Response
    {
        return Response::html(View::render('errors/404', [], 'layouts/app'), 404);
    }
}
