<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\View;
use App\Models\Patrols;
use App\Models\Sectors;
use App\Models\Sites;
use App\Models\Users;
use App\Services\Audit;
use App\Services\UserAuth;

final class RoundsController
{
    public function index(Request $request): Response
    {
        return Response::html(View::render('panel/rounds/index', [
            'title' => 'Rondas de guardias', 'points' => Patrols::points(), 'routes' => Patrols::routes(), 'rounds' => Patrols::rounds(100, $this->onlyUser()),
            'panics' => $this->onlyUser() === null ? \App\Services\GuardSafety::panics(20) : [],
            'devices' => \App\Services\DeviceStatus::recent(24, $this->onlyUser()),
            'canCreate' => UserAuth::can('rondas', 'crear'), 'canEdit' => UserAuth::can('rondas', 'editar'),
        ], 'layouts/app'));
    }

    /** Métricas de rondas (fase 6): período, ruta y guardia. Alcance "propios" = solo las suyas. */
    public function metrics(Request $request): Response
    {
        [$report, $filters] = $this->metricsReport($request);
        return Response::html(View::render('panel/rounds/metrics', ['title' => 'Métricas de rondas', 'r' => $report, 'filters' => $filters,
            'routes' => Patrols::routes(), 'users' => $this->onlyUser() === null ? array_filter(Users::all(), fn ($u) => (int) $u['is_active'] === 1) : [],
            'canExport' => UserAuth::can('rondas', 'exportar')], 'layouts/app'));
    }

    public function metricsExport(Request $request): Response
    {
        [$report, $filters] = $this->metricsReport($request);
        $type = in_array($request->input('tipo'), ['guardias', 'puntos', 'alertas'], true) ? (string) $request->input('tipo') : 'guardias';
        Audit::tenant('rounds.export', 'patrol_round', null, null, ['tipo' => $type, 'desde' => $filters['desde'], 'hasta' => $filters['hasta']]);
        return new Response(\App\Services\RoundMetrics::csv($report, $type), 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"rondas-{$type}-{$filters['desde']}-a-{$filters['hasta']}.csv\""]);
    }

    /** @return array{0: array, 1: array} */
    private function metricsReport(Request $request): array
    {
        $tz = new \DateTimeZone(\App\Core\Tenant::timezone() ?: 'America/Argentina/Buenos_Aires');
        $today = new \DateTimeImmutable('now', $tz);
        $date = fn (string $k, string $def) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input($k, '')) ? (string) $request->input($k) : $def;
        $from = $date('desde', $today->modify('-6 days')->format('Y-m-d'));
        $to = $date('hasta', $today->format('Y-m-d'));
        if ($to < $from) [$from, $to] = [$to, $from];
        if ((new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days > 366) $from = (new \DateTimeImmutable($to))->modify('-366 days')->format('Y-m-d');
        $route = ($request->input('ruta') ?? '') !== '' ? Patrols::route((string) $request->input('ruta')) : null;
        $guard = ($request->input('guardia') ?? '') !== '' ? Users::findByUuid((string) $request->input('guardia')) : null;
        $userId = $this->onlyUser() ?? ($guard ? (int) $guard['id'] : null);
        $report = \App\Services\RoundMetrics::report($from, $to, ['route_id' => $route ? (int) $route['id'] : null, 'user_id' => $userId]);
        return [$report, ['desde' => $from, 'hasta' => $to, 'ruta' => $route['uuid'] ?? '', 'guardia' => $guard['uuid'] ?? '']];
    }

    public function outside(Request $request): Response
    {
        $guard = trim((string) $request->input('guardia', ''));
        $point = trim((string) $request->input('punto', ''));
        $from = trim((string) $request->input('desde', ''));
        $to = trim((string) $request->input('hasta', ''));
        return Response::html(View::render('panel/rounds/outside', [
            'title' => 'Marcas fuera de lugar',
            'scans' => Patrols::outsideScans($guard ?: null, $point ?: null, $from ?: null, $to ?: null),
            'users' => array_filter(Users::all(), fn ($u) => (int) $u['is_active'] === 1),
            'points' => Patrols::points(), 'filters' => ['guardia' => $guard, 'punto' => $point, 'desde' => $from, 'hasta' => $to],
        ], 'layouts/app'));
    }

    public function round(Request $request, string $uuid): Response
    {
        $round = Patrols::roundDetail($uuid);
        $only = $this->onlyUser();
        if (!$round || ($only !== null && (int) $round['user_id'] !== $only)) return new Response('', 404);
        return Response::html(View::render('panel/rounds/round', ['title' => 'Ronda de ' . $round['user_name'], 'round' => $round,
            'tracks' => \App\Services\GuardSafety::tracks((int) $round['id']), 'alerts' => \App\Services\RoundAutomation::alerts((int) $round['id']),
            'device' => $round['status'] === 'en_curso' && ($st = \App\Services\DeviceStatus::latestForUser((int) $round['user_id'])) !== null
                ? ['status' => $st, 'problems' => \App\Services\DeviceStatus::problems($st)] : null], 'layouts/app'));
    }

    /** Alerta de pánico: dónde, cuándo, recorrido de la ronda y "Atendido". Solo con alcance más amplio que "propios". */
    public function panic(Request $request, string $uuid): Response
    {
        $p = \App\Services\GuardSafety::findPanic($uuid);
        if ($p === null || $this->onlyUser() !== null) {
            return new Response('', 404);
        }
        return Response::html(View::render('panel/rounds/panic', ['title' => 'Pánico · ' . $p['user_name'], 'p' => $p,
            'tracks' => $p['round_id'] ? \App\Services\GuardSafety::tracks((int) $p['round_id']) : []], 'layouts/app'));
    }

    public function ackPanic(Request $request, string $uuid): Response
    {
        $p = \App\Services\GuardSafety::findPanic($uuid);
        if ($p === null || $this->onlyUser() !== null) {
            return new Response('', 404);
        }
        $error = \App\Services\GuardSafety::ack($p, (string) $request->input('comment', ''));
        \App\Core\Flash::add($error ? 'danger' : 'success', $error ?? 'Alerta marcada como atendida.');
        return Response::redirect('/panel/rondas/panico/' . $uuid);
    }

    /** Con alcance "propios" el usuario ve solo sus rondas; si no, todas. */
    private function onlyUser(): ?int
    {
        return UserAuth::scope('rondas') === 'propios' ? (int) UserAuth::user()['id'] : null;
    }

    public function pointForm(Request $request): Response
    {
        [$old, $errors] = Flash::pullInput();
        // Doble clic en el mapa de rondas: llega con las coordenadas ya elegidas.
        foreach (['lat', 'lng'] as $k) {
            if (!isset($old[$k]) && is_numeric($request->input($k))) $old[$k] = (string) round((float) $request->input($k), 7);
        }
        return Response::html(View::render('panel/rounds/point', ['title' => 'Nuevo punto de ronda', 'sites' => Sites::options(), 'sectors' => Sectors::options(), 'old' => $old, 'errors' => $errors,
            'points' => Patrols::points()], 'layouts/app'));
    }

    public function pointDelete(Request $request, string $uuid): Response
    {
        $point = Patrols::point($uuid);
        if (!$point || $point['deleted_at'] !== null) return Response::redirect('/panel/rondas');
        $routes = Patrols::deletePoint($point);
        Audit::tenant('patrol_point.delete', 'patrol_point', $uuid, ['code' => $point['code'], 'name' => $point['name'], 'lat' => (float) $point['lat'], 'lng' => (float) $point['lng']], null);
        Flash::add('success', "Punto {$point['code']} eliminado." . ($routes ? " Se sacó de {$routes} ruta(s)." : '') . ' Las rondas anteriores conservan sus marcas.');
        return Response::redirect('/panel/rondas');
    }

    public function routeForm(Request $request): Response
    {
        return Response::html(View::render('panel/rounds/route', ['title' => 'Nueva ruta de ronda', 'points' => Patrols::points(), 'users' => array_filter(Users::all(), fn ($u) => (int) $u['is_active'] === 1)], 'layouts/app'));
    }

    public function routeStore(Request $request): Response
    {
        $p = $request->post;
        $name = trim((string) ($p['name'] ?? ''));
        $points = Patrols::pointIdsByUuid((array) ($p['points'] ?? []));
        if ($name === '' || !$points) { Flash::add('danger', 'La ruta necesita un nombre y al menos un punto.'); return Response::redirect('/panel/rondas/rutas/nueva'); }
        $users = [];
        foreach ((array) ($p['users'] ?? []) as $uuid) {
            $u = Users::findByUuid((string) $uuid);
            if ($u && (int) $u['is_active'] === 1) $users[] = (int) $u['id'];
        }
        $frequency = in_array($p['frequency'] ?? '', ['manual', 'diaria', 'semanal', 'mensual'], true) ? $p['frequency'] : 'manual';
        $data = ['uuid' => Uuid::v4(), 'name' => $name, 'description' => trim((string) ($p['description'] ?? '')), 'frequency' => $frequency,
            'expected_minutes' => max(0, (int) ($p['expected_minutes'] ?? 0)), 'point_ids' => $points, 'user_ids' => $users];
        Patrols::createRoute($data);
        Audit::tenant('patrol_route.create', 'patrol_route', $data['uuid'], null, ['name' => $name, 'frequency' => $frequency,
            'points' => count($points), 'users' => count($users)]);
        Flash::add('success', 'Ruta creada y asignada.');
        return Response::redirect('/panel/rondas');
    }

    public function pointStore(Request $request): Response
    {
        $p = $request->post;
        $name = trim((string) ($p['name'] ?? '')); $code = trim((string) ($p['code'] ?? ''));
        $latRaw = str_replace(',', '.', trim((string) ($p['lat'] ?? '')));
        $lngRaw = str_replace(',', '.', trim((string) ($p['lng'] ?? '')));
        $hasCoords = is_numeric($latRaw) && is_numeric($lngRaw);
        $lat = (float) $latRaw; $lng = (float) $lngRaw;
        if ($name === '' || $code === '' || !$hasCoords || ($lat == 0 && $lng == 0) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            Flash::add('danger', 'Nombre, código y coordenadas válidas son obligatorios.');
            Flash::withInput(array_diff_key($p, ['csrf_token' => 1]));
            return Response::redirect('/panel/rondas/puntos/nuevo');
        }
        try {
            $site = Sites::findByUuid((string) ($p['site'] ?? ''));
            $sector = Sectors::findByUuid((string) ($p['sector'] ?? ''));
            $data = ['uuid' => Uuid::v4(), 'name' => $name, 'code' => $code, 'description' => trim((string) ($p['description'] ?? '')),
                'site_id' => $site ? (int) $site['id'] : null, 'sector_id' => $sector ? (int) $sector['id'] : null,
                'lat' => $lat, 'lng' => $lng, 'radius_m' => max(5, (int) ($p['radius_m'] ?? 50)), 'is_critical' => !empty($p['is_critical'])];
            Patrols::createPoint($data);
            Audit::tenant('patrol_point.create', 'patrol_point', $data['uuid'], null, array_diff_key($data, ['uuid' => 1, 'site_id' => 1, 'sector_id' => 1])
                + ['site' => $site['name'] ?? null, 'sector' => $sector['name'] ?? null]);
            Flash::add('success', 'Punto de ronda creado. Ya podés imprimir su QR.');
        } catch (\Throwable $e) { Flash::add('danger', str_contains($e->getMessage(), 'uq_patrol_points_code') ? 'Ese código ya existe.' : 'No se pudo crear el punto.'); }
        return Response::redirect('/panel/rondas');
    }

    public function qr(Request $request, string $uuid): Response
    {
        $point = Patrols::point($uuid);
        if (!$point) return new Response('', 404);
        return Response::html(View::render('panel/rounds/qr', ['title' => 'QR ' . $point['code'], 'point' => $point, 'qr' => absolute_url('/ronda/punto/' . $point['uuid'])], null));
    }
}
