<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\CatalogItems;
use App\Models\NotificationQueue;
use App\Models\NotificationRules;
use App\Models\Roles;
use App\Models\Sectors;
use App\Models\Settings;
use App\Models\Users;
use App\Services\Audit;
use App\Services\Notify\Channels;
use App\Services\Notify\Messages;

/** Configuración → Notificaciones: reglas por evento, escalamiento, resúmenes y registro de envíos. */
final class NotificationRulesController
{
    public function index(Request $request): Response
    {
        return Response::html(View::render('panel/notification_rules', [
            'title'    => 'Notificaciones',
            'rules'    => NotificationRules::all(),
            'edit'     => ($u = (string) $request->input('regla', '')) !== '' ? NotificationRules::findByUuid($u) : null,
            'creating' => $request->input('regla') === 'nueva',
            'roles'    => Roles::all(),
            'users'    => Users::all(),
            'sectors'  => Sectors::options(),
            'levels'   => CatalogItems::list(null, false, ['catalog' => 'severidad']),
            'settings' => [
                'escalation' => Settings::get('notif.escalation_minutes') ?? '15',
                'daily'      => Settings::get('notif.digest_daily', '1') === '1',
                'weekly'     => Settings::get('notif.digest_weekly', '1') === '1',
                'soon'       => Settings::get('acciones.aviso_dias', '3'),
                'escalate'   => Settings::get('acciones.escalar_dias', '3'),
                'verify'     => Settings::get('acciones.dias_verificacion', '15'),
                'inv_days'   => Settings::get('incidentes.dias_investigacion', '3'),
                'art_hours'  => Settings::get('incidentes.horas_art', '48'),
            ],
            'queue'    => NotificationQueue::recent(30),
            'stats'    => NotificationQueue::stats(),
        ], 'layouts/app'));
    }

    public function save(Request $request): Response
    {
        $uuid = (string) $request->input('uuid', '');
        $rule = $uuid !== '' ? NotificationRules::findByUuid($uuid) : null;
        $event = (string) $request->input('event', '');
        $name = trim((string) $request->input('name', ''));
        $channels = array_values(array_intersect(array_keys(Channels::LABELS), (array) $request->input('channels', [])));
        $recipients = [];
        foreach ((array) $request->input('roles', []) as $slug) {
            if (Roles::findBySlug((string) $slug)) {
                $recipients[] = ['type' => 'role', 'value' => (string) $slug];
            }
        }
        foreach (['sector_supervisors', 'assignee', 'reporter', 'verifiers'] as $type) {
            if ($request->input('r_' . $type)) {
                $recipients[] = ['type' => $type];
            }
        }
        foreach ((array) $request->input('users', []) as $u) {
            if (Users::findByUuid((string) $u)) {
                $recipients[] = ['type' => 'user', 'value' => (string) $u];
            }
        }
        $errors = [];
        if (!isset(Messages::EVENTS[$event]) || in_array($event, Messages::INTERNAL, true)) {
            $errors[] = 'Elegí el evento.';
        }
        if ($name === '') {
            $errors[] = 'Poné un nombre a la regla.';
        }
        if (!$recipients) {
            $errors[] = 'Elegí al menos un destinatario.';
        }
        if (!$channels) {
            $errors[] = 'Elegí al menos un canal.';
        }
        if ($errors) {
            Flash::add('danger', implode(' ', $errors));
            return Response::redirect('/panel/configuracion/notificaciones' . ($rule ? '?regla=' . $rule['uuid'] : '?regla=nueva'));
        }
        $sector = Sectors::findByUuid((string) $request->input('sector', ''));
        $level = (string) $request->input('min_level', '');
        $data = [
            'name' => mb_substr($name, 0, 120), 'event' => $event,
            'min_severity_level' => ctype_digit($level) ? (int) $level : null,
            'sector_id' => $sector['id'] ?? null, 'recipients' => $recipients, 'channels' => $channels,
            'is_active' => $request->input('is_active') ? 1 : 0,
        ];
        NotificationRules::save($rule ? (int) $rule['id'] : null, $data);
        Audit::tenant($rule ? 'notification_rule.update' : 'notification_rule.create', 'notification_rule', $rule['uuid'] ?? null, null, $data);
        Flash::add('success', 'Regla guardada.');
        return Response::redirect('/panel/configuracion/notificaciones');
    }

    public function saveSettings(Request $request): Response
    {
        $minutes = (int) $request->input('escalation', 15);
        Settings::set('notif.escalation_minutes', (string) max(1, min(240, $minutes)));
        Settings::set('notif.digest_daily', $request->input('daily') ? '1' : '0');
        Settings::set('notif.digest_weekly', $request->input('weekly') ? '1' : '0');
        $days = fn (string $k, int $def, int $max) => (string) max(1, min($max, (int) $request->input($k, $def)));
        Settings::set('acciones.aviso_dias', $days('soon_days', 3, 30));
        Settings::set('acciones.escalar_dias', $days('escalate_days', 3, 60));
        Settings::set('acciones.dias_verificacion', $days('verify_days', 15, 180));
        Settings::set('incidentes.dias_investigacion', $days('inv_days', 3, 60));
        Settings::set('incidentes.horas_art', $days('art_hours', 48, 720));
        Audit::tenant('settings.update', 'settings', null, null, ['escalamiento_min' => $minutes, 'resumen_diario' => (bool) $request->input('daily'), 'resumen_semanal' => (bool) $request->input('weekly'),
            'acciones_aviso_dias' => Settings::get('acciones.aviso_dias'), 'acciones_escalar_dias' => Settings::get('acciones.escalar_dias'),
            'acciones_dias_verificacion' => Settings::get('acciones.dias_verificacion')]);
        Flash::add('success', 'Configuración guardada.');
        return Response::redirect('/panel/configuracion/notificaciones');
    }
}
