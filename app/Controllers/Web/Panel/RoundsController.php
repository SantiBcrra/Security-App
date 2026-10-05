<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Patrols;
use App\Models\Sectors;
use App\Models\Sites;

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

    public function pointStore(Request $request): Response
    {
        $p = $request->post;
        $name = trim((string) ($p['name'] ?? '')); $code = trim((string) ($p['code'] ?? ''));
        $lat = (float) ($p['lat'] ?? 0); $lng = (float) ($p['lng'] ?? 0);
        if ($name === '' || $code === '' || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            Flash::add('danger', 'Nombre, código y coordenadas válidas son obligatorios.');
            return Response::redirect('/panel/rondas/puntos/nuevo');
        }
        try {
            Patrols::createPoint(['name' => $name, 'code' => $code, 'description' => $p['description'] ?? '', 'site_id' => $this->idByUuid('sites', $p['site'] ?? ''), 'sector_id' => $this->idByUuid('sectors', $p['sector'] ?? ''), 'lat' => $lat, 'lng' => $lng, 'radius_m' => max(5, (int) ($p['radius_m'] ?? 50)), 'is_critical' => !empty($p['is_critical'])]);
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

    private function idByUuid(string $table, string $uuid): ?int
    {
        if ($uuid === '' || !in_array($table, ['sites', 'sectors'], true)) return null;
        $s = \App\Core\DB::tenant()->prepare("SELECT id FROM {$table} WHERE uuid=? LIMIT 1"); $s->execute([$uuid]);
        $id = $s->fetchColumn(); return $id === false ? null : (int) $id;
    }
}
