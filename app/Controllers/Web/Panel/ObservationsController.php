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
use App\Models\ObservationAttachments;
use App\Models\ObservationEvents;
use App\Models\Observations;
use App\Models\Sectors;
use App\Models\Settings;
use App\Models\Users;
use App\Services\ObservationService;
use App\Services\ObservationWorkflow;
use App\Services\UserAuth;

/** Observaciones de actos y condiciones inseguras. */
final class ObservationsController
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        [$filters, $form] = $this->filters($request);
        $scope = ObservationService::scope();
        $map = $request->input('vista') === 'mapa';
        $page = max(1, (int) $request->input('pagina', 1));
        $rows = $map
            ? Observations::search($filters + ['with_gps' => 1], $scope, 1000)
            : Observations::search($filters, $scope, self::PER_PAGE, ($page - 1) * self::PER_PAGE);

        return Response::html(View::render('panel/observations/index', [
            'title'    => 'Observaciones',
            'rows'     => $rows,
            'total'    => Observations::count($filters, $scope),
            'byStatus' => Observations::countByStatus($filters, $scope),
            'form'     => $form,
            'map'      => $map,
            'page'     => $page,
            'perPage'  => self::PER_PAGE,
            'options'  => $this->options(),
        ], 'layouts/app'));
    }

    public function create(Request $request): Response
    {
        [$old, $errors] = Flash::pullInput();
        $equipment = Equipment::findByUuid((string) $request->input('equipo', ''));
        $defaults = [
            'category' => '', 'risk_type' => '', 'severity' => '', 'equipment' => $equipment['uuid'] ?? '',
            'sector' => $equipment && $equipment['sector_id'] ? (Sectors::findById((int) $equipment['sector_id'])['uuid'] ?? '') : '',
            'location_text' => '', 'description' => '', 'occurred_at' => fecha(gmdate('Y-m-d H:i:s'), 'Y-m-d\TH:i'),
            'lat' => '', 'lng' => '', 'gps_accuracy' => '', 'imminent' => '', 'anonymous' => '', 'people' => [],
        ];
        return Response::html(View::render('panel/observations/form', [
            'title'      => 'Nueva observación',
            'old'        => $old + $defaults,
            'errors'     => $errors,
            'options'    => $this->options(),
            'employees'  => Employees::options(),
            'anonymousEnabled' => Settings::bool('observaciones.anonimo_habilitado'),
        ], 'layouts/app'));
    }

    public function store(Request $request): Response
    {
        $in = $request->post;
        [$data, $people, $errors] = $this->validate($in);
        $photos = $this->uploadedPhotos($request);
        if (count($photos) > 10) {
            $errors['photos'] = 'Máximo 10 fotos por observación.';
        }
        if ($errors) {
            unset($in['csrf_token']);
            Flash::withInput($in, $errors);
            return Response::redirect('/panel/observaciones/nueva');
        }
        $result = ObservationService::create($data, $people, $photos);
        $obs = $result['observation'];
        foreach ($result['photo_errors'] as $err) {
            Flash::add('warning', 'No se guardó una foto: ' . $err);
        }
        Flash::add($obs['imminent_risk'] ? 'danger' : 'success', $obs['imminent_risk']
            ? 'Observación ' . Observations::format((int) $obs['number']) . ' registrada como RIESGO INMINENTE. Si no lo hiciste: avisá en persona o por radio al supervisor y frená la tarea.'
            : 'Observación ' . Observations::format((int) $obs['number']) . ' registrada. ¡Gracias por reportar!');
        // Un reporte anónimo no se puede volver a abrir por su autor (no queda vinculado).
        return Response::redirect($obs['is_anonymous'] && !ObservationService::canView($obs) ? '/panel/observaciones' : '/panel/observaciones/' . $obs['uuid']);
    }

    public function show(Request $request, string $uuid): Response
    {
        $obs = $this->find($uuid);
        if ($obs === null) {
            return self::notFound();
        }
        [$old, $errors] = Flash::pullInput();
        return Response::html(View::render('panel/observations/show', [
            'title'       => Observations::format((int) $obs['number']),
            'obs'         => $obs,
            'original'    => json_decode($obs['original_data'], true) ?: [],
            'hashOk'      => hash('sha256', $obs['original_data']) === $obs['original_hash'],
            'events'      => ObservationEvents::forObservation((int) $obs['id']),
            'photos'      => ObservationAttachments::forObservation((int) $obs['id']),
            'people'      => Observations::people((int) $obs['id']),
            'actions'     => ObservationWorkflow::available($obs['status']),
            'users'       => array_filter(Users::all(), fn ($u) => (int) $u['is_active'] === 1 && $u['password_hash']),
            'options'     => $this->options(),
            'old'         => $old,
            'errors'      => $errors,
        ], 'layouts/app'));
    }

    public function printable(Request $request, string $uuid): Response
    {
        $obs = $this->find($uuid);
        if ($obs === null) {
            return self::notFound();
        }
        return Response::html(View::render('panel/observations/print', [
            'obs'      => $obs,
            'original' => json_decode($obs['original_data'], true) ?: [],
            'events'   => ObservationEvents::forObservation((int) $obs['id']),
            'photos'   => ObservationAttachments::forObservation((int) $obs['id']),
            'people'   => Observations::people((int) $obs['id']),
        ], null));
    }

    public function transition(Request $request, string $uuid, string $action): Response
    {
        $obs = $this->find($uuid);
        if ($obs === null) {
            return self::notFound();
        }
        $error = ObservationService::transition($obs, $action, $request->post);
        if ($error) {
            Flash::add('danger', $error);
            Flash::withInput(['action' => $action] + array_diff_key($request->post, ['csrf_token' => 1]));
        } else {
            Flash::add('success', 'Estado actualizado: ' . ObservationWorkflow::label(ObservationWorkflow::TRANSITIONS[$action]['to']) . '.');
        }
        return Response::redirect('/panel/observaciones/' . $uuid);
    }

    public function comment(Request $request, string $uuid): Response
    {
        $obs = $this->find($uuid);
        if ($obs === null) {
            return self::notFound();
        }
        $error = ObservationService::comment($obs, (string) $request->input('comment', ''));
        Flash::add($error ? 'danger' : 'success', $error ?? 'Comentario agregado.');
        return Response::redirect('/panel/observaciones/' . $uuid . '#linea-de-tiempo');
    }

    public function correct(Request $request, string $uuid): Response
    {
        $obs = $this->find($uuid);
        if ($obs === null) {
            return self::notFound();
        }
        $resolve = fn (string $key, callable $finder) => ($v = (string) $request->input($key, '')) === '' ? null : ($finder($v)['id'] ?? false);
        $new = [
            'category_id'  => $resolve('category', [CatalogItems::class, 'findByUuid']),
            'risk_type_id' => $resolve('risk_type', [CatalogItems::class, 'findByUuid']),
            'severity_id'  => $resolve('severity', [CatalogItems::class, 'findByUuid']),
            'sector_id'    => $resolve('sector', [Sectors::class, 'findByUuid']),
            'equipment_id' => $resolve('equipment', [Equipment::class, 'findByUuid']),
        ];
        if (in_array(false, $new, true) || $new['severity_id'] === null || $new['sector_id'] === null || $new['category_id'] === null) {
            Flash::add('danger', 'Categoría, severidad y sector son obligatorios y tienen que ser valores válidos.');
            return Response::redirect('/panel/observaciones/' . $uuid);
        }
        $new = array_map(fn ($v) => $v === null ? null : (int) $v, $new);
        $error = ObservationService::correct($obs, $new, (string) $request->input('comment', ''));
        Flash::add($error ? 'danger' : 'success', $error ?? 'Clasificación corregida. El reporte original se conserva sin cambios.');
        return Response::redirect('/panel/observaciones/' . $uuid);
    }

    public function addPhotos(Request $request, string $uuid): Response
    {
        $obs = $this->find($uuid);
        if ($obs === null) {
            return self::notFound();
        }
        $photos = $this->uploadedPhotos($request);
        if (!$photos) {
            Flash::add('warning', 'No elegiste ninguna foto.');
        } else {
            $errors = ObservationService::addPhotos($obs, array_slice($photos, 0, 10));
            foreach ($errors as $err) {
                Flash::add('warning', $err);
            }
            if (count($errors) < count($photos)) {
                Flash::add('success', 'Fotos agregadas.');
            }
        }
        return Response::redirect('/panel/observaciones/' . $uuid);
    }

    /** Foto (o miniatura) servida por PHP: valida empresa, permiso y alcance. */
    public function photo(Request $request, string $uuid, string $attachment): Response
    {
        $obs = $this->find($uuid);
        $att = $obs ? ObservationAttachments::find((int) $obs['id'], $attachment) : null;
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
        $obs = Observations::findByUuid($uuid);
        return $obs !== null && ObservationService::canView($obs) ? $obs : null;
    }

    private function options(): array
    {
        return [
            'categories' => CatalogItems::optionsFor('categoria'),
            'risks'      => CatalogItems::optionsFor('tipo_riesgo'),
            'severities' => CatalogItems::list(null, false, ['catalog' => 'severidad']),
            'sectors'    => Sectors::options(),
            'equipment'  => array_map(fn ($e) => $e['code'] . ' · ' . $e['name'], array_column(Equipment::list(null, false, [], 2000), null, 'uuid')),
        ];
    }

    /** @return array{0: array, 1: list<int>, 2: array} [datos, ids de involucrados, errores] */
    private function validate(array $in): array
    {
        $errors = [];
        $cat = fn (string $catalog, string $key) => ($item = CatalogItems::findByUuid((string) ($in[$key] ?? ''))) && $item['catalog'] === $catalog ? $item : null;
        $category = $cat('categoria', 'category');
        $risk = $cat('tipo_riesgo', 'risk_type');
        $severity = $cat('severidad', 'severity');
        $sector = Sectors::findByUuid((string) ($in['sector'] ?? ''));
        $equipment = ($in['equipment'] ?? '') !== '' ? Equipment::findByUuid((string) $in['equipment']) : null;
        if ($category === null) {
            $errors['category'] = 'Elegí si es un acto, una condición o una buena práctica.';
        }
        if ($severity === null) {
            $errors['severity'] = 'Elegí la severidad.';
        }
        if ($sector === null && $equipment && $equipment['sector_id']) {
            $sector = Sectors::findById((int) $equipment['sector_id']);
        }
        if ($sector === null) {
            $errors['sector'] = 'Elegí el sector donde ocurrió.';
        }
        if (($in['equipment'] ?? '') !== '' && $equipment === null) {
            $errors['equipment'] = 'Equipo inválido.';
        }
        $description = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($description) < 10) {
            $errors['description'] = 'Contá qué viste (mínimo 10 caracteres).';
        } elseif (mb_strlen($description) > 5000) {
            $errors['description'] = 'La descripción es demasiado larga (máx. 5000).';
        }
        // Fecha y hora del hecho: en la zona de la empresa → UTC. No futura ni de hace más de un año.
        $occurred = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) ($in['occurred_at'] ?? ''), new \DateTimeZone(Tenant::timezone() ?? 'UTC'));
        if (!$occurred) {
            $errors['occurred_at'] = 'Fecha y hora inválidas.';
        } elseif ($occurred->getTimestamp() > time() + 300) {
            $errors['occurred_at'] = 'La fecha no puede ser futura.';
        } elseif ($occurred->getTimestamp() < time() - 366 * 86400) {
            $errors['occurred_at'] = 'La fecha es de hace más de un año.';
        }
        $lat = trim((string) ($in['lat'] ?? ''));
        $lng = trim((string) ($in['lng'] ?? ''));
        if (($lat !== '' || $lng !== '') && (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180)) {
            $errors['lat'] = 'Ubicación GPS inválida.';
        }
        $people = [];
        foreach ((array) ($in['people'] ?? []) as $uuid) {
            if (($e = Employees::findByUuid((string) $uuid)) !== null) {
                $people[] = (int) $e['id'];
            }
        }
        $anonymous = !empty($in['anonymous']) && Settings::bool('observaciones.anonimo_habilitado');
        $data = [
            'category_id'       => $category['id'] ?? null,
            'risk_type_id'      => $risk['id'] ?? null,
            'severity_id'       => $severity['id'] ?? null,
            'site_id'           => $sector['site_id'] ?? null,
            'sector_id'         => $sector['id'] ?? null,
            'equipment_id'      => $equipment['id'] ?? null,
            'description'       => $description,
            'location_text'     => mb_substr(trim((string) ($in['location_text'] ?? '')), 0, 191) ?: null,
            'lat'               => $lat !== '' ? $lat : null,
            'lng'               => $lng !== '' ? $lng : null,
            'gps_accuracy_m'    => ctype_digit((string) ($in['gps_accuracy'] ?? '')) ? (int) $in['gps_accuracy'] : null,
            'imminent_risk'     => !empty($in['imminent']) ? 1 : 0,
            'is_anonymous'      => $anonymous ? 1 : 0,
            'created_at_device' => $occurred ? $occurred->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null,
        ];
        return [$data, $people, $errors];
    }

    /** $_FILES['photos'] (múltiple) → lista normalizada. */
    private function uploadedPhotos(Request $request): array
    {
        $files = $request->files['photos'] ?? null;
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
        foreach (['estado', 'severidad', 'categoria', 'riesgo', 'sector', 'desde', 'hasta', 'inminente', 'q', 'asignadas'] as $k) {
            $form[$k] = (string) $request->input($k, '');
        }
        $f = [];
        if (isset(ObservationWorkflow::STATES[$form['estado']])) {
            $f['status'] = $form['estado'];
        } elseif ($form['estado'] === 'pendientes') {
            $f['status'] = ['abierta', 'en_analisis', 'accion_asignada'];
        }
        foreach (['severidad' => 'severity_id', 'categoria' => 'category_id', 'riesgo' => 'risk_type_id'] as $k => $col) {
            if ($form[$k] !== '' && ($item = CatalogItems::findByUuid($form[$k])) !== null) {
                $f[$col] = (int) $item['id'];
            }
        }
        if ($form['sector'] !== '' && ($s = Sectors::findByUuid($form['sector'])) !== null) {
            $f['sector_ids'] = Sectors::withDescendants([(int) $s['id']]);
        }
        $tz = new \DateTimeZone(Tenant::timezone() ?? 'UTC');
        foreach (['desde' => 'from', 'hasta' => 'to'] as $k => $col) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $form[$k], $tz);
            if ($d) {
                $f[$col] = ($k === 'hasta' ? $d->modify('+1 day') : $d)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }
        if ($form['inminente'] === '1') {
            $f['imminent'] = 1;
        }
        if ($form['asignadas'] === '1' && UserAuth::user()) {
            $f['assigned_user_id'] = (int) UserAuth::user()['id'];
        }
        $f['q'] = $form['q'];
        return [$f, $form];
    }

    private static function notFound(): Response
    {
        return Response::html(View::render('errors/404', [], 'layouts/app'), 404);
    }
}
