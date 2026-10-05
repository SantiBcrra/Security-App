<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Resources\Registry;
use App\Resources\Resource;
use App\Services\Audit;
use App\Services\IndustryTemplates;

/**
 * CRUD de datos maestros para todos los recursos de App\Resources (plantas, sectores, puestos,
 * empleados, contratistas, equipos y catálogos). Nada se borra: se desactiva/reactiva.
 */
final class MasterDataController
{
    public function index(Request $request, string $resource): Response
    {
        $res = Registry::get($resource);
        if ($res === null) {
            return self::notFound();
        }
        $inactive = (bool) $request->input('inactivos');
        return Response::html(View::render($res->indexView(), [
            'title'    => $res->plural(),
            'res'      => $res,
            'rows'     => $res->rows($request, $inactive),
            'inactive' => $inactive,
            'q'        => (string) $request->input('q', ''),
            'filters'  => array_map(fn ($k) => (string) $request->input($k, ''), array_combine(array_keys($res->filterControls()), array_keys($res->filterControls()))),
        ], 'layouts/app'));
    }

    public function create(Request $request, string $resource): Response
    {
        $res = Registry::get($resource);
        if ($res === null) {
            return self::notFound();
        }
        [$old, $errors] = Flash::pullInput();
        return $this->form($res, null, $old + $res->formValues(null), $errors);
    }

    public function store(Request $request, string $resource): Response
    {
        $res = Registry::get($resource);
        if ($res === null) {
            return self::notFound();
        }
        $errors = $res->validate($request->post, null);
        if ($errors) {
            return self::back("/panel/datos/{$resource}/nuevo", $request->post, $errors);
        }
        $id = $res->save($res->toRow($request->post, null), null);
        $model = $res->model();
        $row = $model::findById($id);
        Audit::tenant($res->entity() . '.create', $res->entity(), $row['uuid'], null, $res->auditable($row));
        Flash::add('success', 'Guardado: ' . $res->title($row) . '.');
        return Response::redirect($request->input('_next') === 'otro' ? "/panel/datos/{$resource}/nuevo" : "/panel/datos/{$resource}");
    }

    public function edit(Request $request, string $resource, string $uuid): Response
    {
        $res = Registry::get($resource);
        $row = $res ? ($res->model())::findByUuid($uuid) : null;
        if ($row === null || !self::belongs($res, $row)) {
            return self::notFound();
        }
        [$old, $errors] = Flash::pullInput();
        return $this->form($res, $row, $old + $res->formValues($row), $errors);
    }

    public function update(Request $request, string $resource, string $uuid): Response
    {
        $res = Registry::get($resource);
        $row = $res ? ($res->model())::findByUuid($uuid) : null;
        if ($row === null || !self::belongs($res, $row)) {
            return self::notFound();
        }
        $errors = $res->validate($request->post, $row);
        if ($errors) {
            return self::back("/panel/datos/{$resource}/{$uuid}", $request->post, $errors);
        }
        $before = $res->auditable($row);
        $res->save($res->toRow($request->post, $row), $row);
        $after = $res->auditable(($res->model())::findById((int) $row['id']));
        $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));
        if ($changed) {
            Audit::tenant($res->entity() . '.update', $res->entity(), $uuid,
                array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)));
            Flash::add('success', 'Cambios guardados.');
        } else {
            Flash::add('info', 'No había cambios para guardar.');
        }
        return Response::redirect("/panel/datos/{$resource}/{$uuid}");
    }

    public function toggle(Request $request, string $resource, string $uuid): Response
    {
        $res = Registry::get($resource);
        $row = $res ? ($res->model())::findByUuid($uuid) : null;
        if ($row === null || !self::belongs($res, $row)) {
            return self::notFound();
        }
        $activate = (int) $row['is_active'] !== 1;
        if (!$activate && ($reason = $res->canDeactivate($row)) !== null) {
            Flash::add('danger', $reason);
            return Response::redirect("/panel/datos/{$resource}/{$uuid}");
        }
        ($res->model())::setActive((int) $row['id'], $activate);
        Audit::tenant($res->entity() . ($activate ? '.activate' : '.deactivate'), $res->entity(), $uuid,
            ['activo' => (int) $row['is_active']], ['activo' => $activate ? 1 : 0]);
        Flash::add('success', $activate ? 'Reactivado.' : 'Desactivado: ya no aparece para elegir, pero se conserva su historial.');
        return Response::redirect("/panel/datos/{$resource}/{$uuid}");
    }

    /** Cargar plantilla de rubro (catálogos y puestos). Idempotente. */
    public function applyTemplate(Request $request): Response
    {
        try {
            $result = IndustryTemplates::apply((string) $request->input('template', ''));
        } catch (\DomainException $e) {
            Flash::add('danger', $e->getMessage());
            return Response::redirect('/panel/datos/riesgos');
        }
        Flash::add('success', $result['created'] > 0
            ? "Plantilla cargada: {$result['created']} ítem(s) nuevos" . ($result['existing'] ? " ({$result['existing']} ya existían)." : '.')
            : 'La plantilla ya estaba cargada: no había nada nuevo para agregar.');
        return Response::redirect('/panel/datos/riesgos');
    }

    private function form(Resource $res, ?array $row, array $values, array $errors): Response
    {
        return Response::html(View::render('panel/master/form', [
            'title'  => $row ? $res->title($row) : $res->newLabel(),
            'res'    => $res,
            'row'    => $row,
            'fields' => $res->fields($row),
            'values' => $values,
            'errors' => $errors,
            'side'   => $row && $res->sideView() ? ['view' => $res->sideView(), 'data' => $res->sideData($row)] : null,
        ], 'layouts/app'));
    }

    /** Un ítem de catálogo solo se abre desde SU catálogo (riesgos no edita severidades). */
    private static function belongs(Resource $res, array $row): bool
    {
        foreach ($res->fixedValues() as $col => $value) {
            if (($row[$col] ?? null) !== $value) {
                return false;
            }
        }
        return true;
    }

    private static function back(string $path, array $data, array $errors): Response
    {
        unset($data['csrf_token']);
        Flash::withInput($data, $errors);
        return Response::redirect($path);
    }

    private static function notFound(): Response
    {
        return Response::html(View::render('errors/404', [], 'layouts/app'), 404);
    }
}
