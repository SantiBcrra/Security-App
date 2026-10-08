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

final class RoundsController
{
    public function index(Request $request): Response
    {
        return Response::html(View::render('panel/rounds/index', [
            'title' => 'Rondas de guardias', 'points' => Patrols::points(), 'routes' => Patrols::routes(), 'rounds' => Patrols::rounds(),
        ], 'layouts/app'));
    }

    public function pointForm(Request $request): Response
    {
        return Response::html(View::render('panel/rounds/point', ['title' => 'Nuevo punto de ronda', 'sites' => Sites::options(), 'sectors' => Sectors::options()], 'layouts/app'));
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
        $hasCoords = is_numeric($p['lat'] ?? null) && is_numeric($p['lng'] ?? null);
        $lat = (float) ($p['lat'] ?? 0); $lng = (float) ($p['lng'] ?? 0);
        if ($name === '' || $code === '' || !$hasCoords || ($lat == 0 && $lng == 0) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            Flash::add('danger', 'Nombre, código y coordenadas válidas son obligatorios.');
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
