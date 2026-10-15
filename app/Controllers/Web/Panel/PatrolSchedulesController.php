<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Tenant;
use App\Core\View;
use App\Models\Patrols;
use App\Services\PatrolPlanner;
use App\Services\UserAuth;

/** Programación de rondas (automatizaciones fase 3): cumplimiento del día, programaciones y su formulario. */
final class PatrolSchedulesController
{
    public function index(Request $request): Response
    {
        $tz = new \DateTimeZone(Tenant::timezone() ?: 'America/Argentina/Buenos_Aires');
        $today = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('fecha', '')) ? (string) $request->input('fecha') : $today;
        PatrolPlanner::generate(); // por si el cron todavía no corrió hoy
        $only = UserAuth::scope('rondas') === 'propios' ? (int) UserAuth::user()['id'] : null;
        return Response::html(View::render('panel/rounds/schedules', [
            'title' => 'Programación de rondas', 'day' => $day, 'today' => $today,
            'slots' => PatrolPlanner::day($day, $only), 'schedules' => $only === null ? PatrolPlanner::all() : [],
            'canEdit' => UserAuth::can('rondas', 'editar'),
        ], 'layouts/app'));
    }

    public function form(Request $request, ?string $uuid = null): Response
    {
        $schedule = $uuid ? PatrolPlanner::find($uuid) : null;
        if ($uuid && !$schedule) return new Response('', 404);
        $routes = array_values(array_filter(Patrols::routes(), fn ($r) => (int) $r['is_active'] === 1));
        $assignees = [];
        foreach ($routes as $r) $assignees[$r['uuid']] = Patrols::assignments((int) $r['id']);
        $userUuids = [];
        foreach (json_decode((string) ($schedule['user_ids'] ?? ''), true) ?: [] as $id) {
            $u = \App\Models\Users::findById((int) $id);
            if ($u) $userUuids[] = $u['uuid'];
        }
        $old = \App\Core\Session::get('schedule_old'); // lo cargado si la validación falló
        \App\Core\Session::forget('schedule_old');
        return Response::html(View::render('panel/rounds/schedule_form', [
            'title' => $schedule ? 'Editar programación' : 'Nueva programación', 'schedule' => $schedule, 'routes' => $routes, 'assignees' => $assignees,
            'userUuids' => $userUuids, 'defaultGrace' => PatrolPlanner::defaultGrace(),
            'old' => $old,
        ], 'layouts/app'));
    }

    public function store(Request $request, ?string $uuid = null): Response
    {
        $schedule = $uuid ? PatrolPlanner::find($uuid) : null;
        if ($uuid && !$schedule) return new Response('', 404);
        [$data, $errors] = PatrolPlanner::validate($request->post);
        if ($errors) {
            foreach ($errors as $e) Flash::add('danger', $e);
            \App\Core\Session::put('schedule_old', $request->post);
            return Response::redirect('/panel/rondas/programacion/' . ($uuid ?? 'nueva'));
        }
        PatrolPlanner::save($schedule, $data);
        Flash::add('success', $schedule ? 'Programación actualizada: las rondas futuras se recalcularon.' : 'Programación creada.');
        return Response::redirect('/panel/rondas/programacion');
    }

    public function toggle(Request $request, string $uuid): Response
    {
        $schedule = PatrolPlanner::find($uuid);
        if ($schedule) {
            PatrolPlanner::setActive($schedule, (int) $schedule['is_active'] !== 1);
            Flash::add('success', (int) $schedule['is_active'] === 1 ? 'Programación pausada: no se generan más rondas.' : 'Programación activada.');
        }
        return Response::redirect('/panel/rondas/programacion');
    }
}
