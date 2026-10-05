<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Alerts;
use App\Models\Notifications;
use App\Models\Observations;
use App\Services\Notify\Escalations;
use App\Services\ObservationService;
use App\Services\UserAuth;

/** Campanita: avisos en la app y confirmación "Recibido" de alertas críticas. */
final class NotificationsController
{
    public function index(Request $request): Response
    {
        $user = UserAuth::user();
        if ($user === null) {
            return Response::redirect('/panel');
        }
        return Response::html(View::render('panel/notifications', [
            'title' => 'Notificaciones',
            'items' => Notifications::forUser((int) $user['id'], 100),
        ], 'layouts/app'));
    }

    /** AJAX (cada 60 s): contador y últimos avisos para el desplegable. */
    public function recent(Request $request): Response
    {
        $user = UserAuth::user();
        if ($user === null) {
            return Response::json(['unread' => 0, 'items' => []]);
        }
        $items = array_map(fn ($n) => [
            'uuid' => $n['uuid'], 'title' => $n['title'], 'body' => mb_strimwidth((string) $n['body'], 0, 120, '…'),
            'url' => $n['url'] ? url($n['url']) : null, 'critical' => (bool) $n['is_critical'], 'read' => $n['read_at'] !== null,
            'pending_ack' => $n['alert_uuid'] && $n['alert_acked_at'] === null, 'when' => fecha($n['created_at'], 'd/m H:i'),
        ], Notifications::forUser((int) $user['id'], 8));
        return Response::json(['unread' => Notifications::unreadCount((int) $user['id']), 'items' => $items]);
    }

    /** Abrir un aviso: lo marca leído y lleva a su destino. */
    public function open(Request $request, string $uuid): Response
    {
        $user = UserAuth::user();
        $n = $user ? Notifications::findForUser((int) $user['id'], $uuid) : null;
        if ($n === null) {
            return Response::redirect('/panel/notificaciones');
        }
        Notifications::markRead((int) $user['id'], $uuid);
        return Response::redirect($n['url'] ?: '/panel/notificaciones');
    }

    public function readAll(Request $request): Response
    {
        if ($user = UserAuth::user()) {
            Notifications::markRead((int) $user['id']);
        }
        return $request->wantsJson() ? Response::json(['ok' => true]) : Response::redirect('/panel/notificaciones');
    }

    /** "Recibido" de una alerta crítica: corta el escalamiento. */
    public function ack(Request $request, string $alertUuid): Response
    {
        $user = UserAuth::user();
        $alert = Alerts::findByUuid($alertUuid);
        $obs = $alert ? Observations::findById((int) $alert['observation_id']) : null;
        if ($user === null || $obs === null) {
            return Response::redirect('/panel/notificaciones');
        }
        Escalations::ack($alert, $user);
        Notifications::markRead((int) $user['id']);
        Flash::add('success', 'Confirmaste que recibiste la alerta. El escalamiento se detuvo.');
        return Response::redirect(ObservationService::canView($obs) ? '/panel/observaciones/' . $obs['uuid'] : '/panel/notificaciones');
    }
}
