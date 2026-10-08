<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Tenant;
use App\Core\TenantFiles;
use App\Core\View;
use App\Models\CatalogItems;
use App\Models\Employees;
use App\Models\Equipment;
use App\Models\Incidents;
use App\Models\Sectors;
use App\Models\Settings;
use App\Services\Audit;
use App\Services\IncidentService;
use App\Services\UserAuth;

/** Incidentes y accidentes (Etapa 12). Los datos de salud se muestran solo con incidentes.datos_salud. */
final class IncidentsController
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        [$filters, $form] = $this->filters($request);
        $scope = IncidentService::scope();
        $page = max(1, (int) $request->input('pagina', 1));
        $tz = Tenant::timezone() ?? 'UTC';
        return Response::html(View::render('panel/incidents/index', [
            'title'    => 'Incidentes y accidentes',
            'rows'     => Incidents::search($filters, $scope, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total'    => Incidents::count($filters, $scope),
            'byType'   => Incidents::countByType($filters, $scope),
            'form'     => $form,
            'page'     => $page,
            'perPage'  => self::PER_PAGE,
            'sectors'  => Sectors::options(),
            'noLost'   => Incidents::daysWithoutLostTime(IncidentService::today(), $tz),
        ], 'layouts/app'));
    }

    public function create(Request $request): Response
    {
        [$old, $errors] = Flash::pullInput();
        $equipment = Equipment::findByUuid((string) $request->input('equipo', ''));
        return Response::html(View::render('panel/incidents/form', [
            'title'      => 'Reportar incidente / accidente',
            'old'        => $old + ['type' => '', 'occurred_at' => fecha(gmdate('Y-m-d H:i:s'), 'Y-m-d\TH:i'), 'sector' => '', 'equipment' => $equipment['uuid'] ?? '',
                'location_text' => '', 'description' => '', 'immediate_actions' => '', 'potential_severity' => '', 'people' => [['role' => 'lesionado']]],
            'errors'     => $errors,
            'sectors'    => Sectors::options(),
            'equipment'  => array_map(fn ($e) => $e['code'] . ' · ' . $e['name'], array_column(Equipment::list(null, false, [], 3000), null, 'uuid')),
            'employees'  => Employees::options(),
            'severities' => CatalogItems::list(null, false, ['catalog' => 'severidad']),
        ], 'layouts/app'));
    }

    public function store(Request $request): Response
    {
        $in = $request->post;
        $in['people'] = array_values((array) ($in['people'] ?? []));
        $result = IncidentService::create($in, $this->uploadedFiles($request));
        if ($result['incident'] === null) {
            unset($in['csrf_token']);
            Flash::add('danger', 'Revisá el reporte: ' . implode(' ', $result['errors']));
            Flash::withInput($in, $result['errors']);
            return Response::redirect('/panel/incidentes/nuevo');
        }
        $i = $result['incident'];
        foreach ($result['file_errors'] ?? [] as $err) {
            Flash::add('warning', 'No se guardó un archivo: ' . $err);
        }
        Flash::add(IncidentService::TYPES[$i['type']]['serious'] ? 'danger' : 'success', Incidents::format((int) $i['number']) . ' registrado. '
            . (IncidentService::TYPES[$i['type']]['serious'] ? 'Se avisó de inmediato a los supervisores del sector, a Seguridad e Higiene y a la dirección.' : 'Se avisó a Seguridad e Higiene.'));
        return Response::redirect('/panel/incidentes/' . $i['uuid']);
    }

    public function show(Request $request, string $uuid): Response
    {
        $i = $this->find($uuid);
        if ($i === null) {
            return self::notFound();
        }
        [$old, $errors] = Flash::pullInput();
        return Response::html(View::render('panel/incidents/show', $this->detail($i, 'detalle') + [
            'title'      => Incidents::format((int) $i['number']),
            'old'        => $old,
            'sectors'    => Sectors::options(),
            'equipment'  => array_map(fn ($e) => $e['code'] . ' · ' . $e['name'], array_column(Equipment::list(null, false, [], 3000), null, 'uuid')),
            'employees'  => Employees::options(),
            'severities' => CatalogItems::list(null, false, ['catalog' => 'severidad']),
            'catalogs'   => [
                'lesion' => CatalogItems::optionsFor('lesion'), 'parte_cuerpo' => CatalogItems::optionsFor('parte_cuerpo'),
                'forma_accidente' => CatalogItems::optionsFor('forma_accidente'),
            ],
        ], 'layouts/app'));
    }

    public function printable(Request $request, string $uuid): Response
    {
        $i = $this->find($uuid);
        return $i === null ? self::notFound() : Response::html(View::render('panel/incidents/print', $this->detail($i, 'informe'), null));
    }

    /** Datos para la denuncia ante la ART: tiene datos de salud, solo con el permiso. */
    public function art(Request $request, string $uuid, string $person): Response
    {
        $i = $this->find($uuid);
        $p = $i ? Incidents::person((int) $i['id'], $person) : null;
        if ($p === null || !IncidentService::canSeeHealth()) {
            return self::notFound();
        }
        Audit::tenant('incident.art_print', 'incident', $i['uuid'], null, ['persona' => $p['employee_id'] ? $p['last_name'] . ', ' . $p['first_name'] : $p['external_name']]);
        $company = array_map(fn ($k) => Settings::get($k) ?? '', SettingsController::COMPANY);
        return Response::html(View::render('panel/incidents/art', [
            'i' => $i, 'p' => $p, 'tenant' => Tenant::current(), 'company' => $company,
            'witnesses' => array_values(array_filter(Incidents::people((int) $i['id']), fn ($x) => $x['role'] === 'testigo')),
            'lost' => IncidentService::lostDays($p),
        ], null));
    }

    public function transition(Request $request, string $uuid, string $key): Response
    {
        $i = $this->find($uuid);
        if ($i === null) {
            return self::notFound();
        }
        $error = IncidentService::transition($i, $key, $request->post);
        Flash::add($error ? 'danger' : 'success', $error ?? 'Incidente actualizado: ' . IncidentService::STATES[IncidentService::TRANSITIONS[$key]['to']]['label'] . '.');
        return Response::redirect('/panel/incidentes/' . $uuid);
    }

    public function correct(Request $request, string $uuid): Response
    {
        $i = $this->find($uuid);
        if ($i === null) {
            return self::notFound();
        }
        $error = IncidentService::correct($i, $request->post);
        Flash::add($error ? 'danger' : 'success', $error ?? 'Clasificación corregida. El reporte original se conserva sin cambios.');
        return Response::redirect('/panel/incidentes/' . $uuid);
    }

    public function addPerson(Request $request, string $uuid): Response
    {
        $i = $this->find($uuid);
        if ($i === null) {
            return self::notFound();
        }
        $error = IncidentService::addPerson($i, $request->post);
        Flash::add($error ? 'danger' : 'success', $error ?? 'Persona agregada.');
        return Response::redirect('/panel/incidentes/' . $uuid . '#personas');
    }

    public function updatePerson(Request $request, string $uuid, string $person): Response
    {
        $i = $this->find($uuid);
        $p = $i ? Incidents::person((int) $i['id'], $person) : null;
        if ($p === null) {
            return self::notFound();
        }
        $error = IncidentService::updatePerson($i, $p, $request->post);
        if ($error) {
            Flash::add('danger', $error);
            Flash::withInput(['person' => $person] + array_diff_key($request->post, ['csrf_token' => 1]));
        } else {
            Flash::add('success', 'Datos de la persona actualizados.');
        }
        return Response::redirect('/panel/incidentes/' . $uuid . '#personas');
    }

    public function comment(Request $request, string $uuid): Response
    {
        $i = $this->find($uuid);
        if ($i === null) {
            return self::notFound();
        }
        $error = IncidentService::comment($i, (string) $request->input('comment', ''));
        Flash::add($error ? 'danger' : 'success', $error ?? 'Comentario agregado.');
        return Response::redirect('/panel/incidentes/' . $uuid . '#linea-de-tiempo');
    }

    public function files(Request $request, string $uuid): Response
    {
        $i = $this->find($uuid);
        if ($i === null) {
            return self::notFound();
        }
        $files = $this->uploadedFiles($request);
        if (!$files) {
            Flash::add('warning', 'No elegiste ningún archivo.');
        } else {
            $errors = IncidentService::storeFiles($i, $files, $request->input('health') === '1');
            foreach ($errors as $err) {
                Flash::add('warning', $err);
            }
            if (count($errors) < count($files)) {
                Flash::add('success', 'Archivos agregados.');
            }
        }
        return Response::redirect('/panel/incidentes/' . $uuid);
    }

    /** Archivo servido por PHP: empresa, permiso, alcance y, si es médico, datos de salud. */
    public function file(Request $request, string $uuid, string $attachment): Response
    {
        $i = $this->find($uuid);
        $att = $i ? Incidents::attachment((int) $i['id'], $attachment) : null;
        if ($att === null || ((int) $att['health'] === 1 && !IncidentService::canSeeHealth())) {
            return new Response('', 404);
        }
        $path = $request->input('t') === '1' && $att['thumb_path'] ? $att['thumb_path'] : $att['path'];
        $file = TenantFiles::path(Tenant::current()['uuid'], $path);
        return $file ? TenantFiles::response($file, 3600) : new Response('', 404);
    }

    // ── helpers ───────────────────────────────────────────────────────

    /** Todo lo que muestran el detalle y el informe; con datos de salud solo si tiene el permiso (y queda auditado). */
    private function detail(array $i, string $where): array
    {
        $health = IncidentService::canSeeHealth();
        $people = Incidents::people((int) $i['id']);
        if ($health && array_filter($people, fn ($p) => $p['role'] === 'lesionado')) {
            Audit::tenant('incident.health_view', 'incident', $i['uuid'], null, ['vista' => $where]);
        }
        return [
            'i'        => $i,
            'health'   => $health,
            'people'   => $people,
            'original' => json_decode($i['original_data'], true) ?: [],
            'hashOk'   => hash('sha256', $i['original_data']) === $i['original_hash'],
            'events'   => Incidents::events((int) $i['id'], $health),
            'files'    => Incidents::attachments((int) $i['id'], $health),
            'today'    => IncidentService::today(),
        ];
    }

    private function find(string $uuid): ?array
    {
        $i = Incidents::findByUuid($uuid);
        return $i !== null && IncidentService::canView($i) ? $i : null;
    }

    private function uploadedFiles(Request $request): array
    {
        $files = $request->files['files'] ?? null;
        if (!is_array($files) || !isset($files['tmp_name'])) {
            return [];
        }
        $list = [];
        foreach ((array) $files['tmp_name'] as $n => $tmp) {
            if (($files['error'][$n] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($tmp)) {
                $list[] = ['tmp' => $tmp, 'name' => $files['name'][$n] ?? null, 'upload' => true];
            }
        }
        return $list;
    }

    private function filters(Request $request): array
    {
        $form = [];
        foreach (['q', 'tipo', 'estado', 'sector', 'desde', 'hasta', 'bajas'] as $k) {
            $form[$k] = (string) $request->input($k, '');
        }
        $f = ['q' => $form['q']];
        if (isset(IncidentService::TYPES[$form['tipo']])) {
            $f['type'] = $form['tipo'];
        } elseif ($form['tipo'] === 'accidentes') {
            $f['type'] = array_keys(array_filter(IncidentService::TYPES, fn ($t) => $t['accident']));
        }
        if (isset(IncidentService::STATES[$form['estado']])) {
            $f['status'] = $form['estado'];
        } elseif ($form['estado'] === 'abiertos') {
            $f['status'] = ['reportado', 'en_investigacion', 'investigado'];
        } elseif ($form['estado'] === '') {
            $f['status'] = ['reportado', 'en_investigacion', 'investigado', 'cerrado']; // sin anulados
        }
        if ($form['sector'] !== '' && ($s = Sectors::findByUuid($form['sector'])) !== null) {
            $f['sector_ids'] = Sectors::withDescendants([(int) $s['id']]);
        }
        if ($form['bajas'] === '1') {
            $f['open_leave'] = true;
        }
        $tz = new \DateTimeZone(Tenant::timezone() ?? 'UTC');
        foreach (['desde' => 'from', 'hasta' => 'to'] as $k => $col) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $form[$k], $tz);
            if ($d) {
                $f[$col] = ($k === 'hasta' ? $d->modify('+1 day') : $d)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }
        return [$f, $form];
    }

    private static function notFound(): Response
    {
        return Response::html(View::render('errors/404', [], 'layouts/app'), 404);
    }
}
