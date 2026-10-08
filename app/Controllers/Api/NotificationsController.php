<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Models\Alerts;
use App\Models\Notifications;
use App\Models\Observations;
use App\Services\Notify\Escalations;
use App\Services\UserAuth;

/** Avisos para la app y "Recibido" de alertas críticas. */
final class NotificationsController
{
    public function index(Request $request): Response
    {
        $user = UserAuth::user();
        $items = array_map(fn ($n) => [
            'uuid' => $n['uuid'], 'title' => $n['title'], 'body' => $n['body'], 'critical' => (bool) $n['is_critical'],
            'read' => $n['read_at'] !== null, 'at' => str_replace(' ', 'T', $n['created_at']) . 'Z',
            'observation_uuid' => $n['url'] && preg_match('#/observaciones/([0-9a-f\-]{36})#', $n['url'], $m) ? $m[1] : null,
            'action_uuid' => $n['url'] && preg_match('#/acciones/([0-9a-f\-]{36})#', $n['url'], $m) ? $m[1] : null,
            'inspection_uuid' => $n['url'] && preg_match('#/inspecciones/([0-9a-f\-]{36})#', $n['url'], $m) ? $m[1] : null,
            'incident_uuid' => $n['url'] && preg_match('#/incidentes/([0-9a-f\-]{36})#', $n['url'], $m) ? $m[1] : null,
            'permit_uuid' => $n['url'] && preg_match('#/permisos/([0-9a-f\-]{36})#', $n['url'], $m) ? $m[1] : null,
            'schedule_uuid' => $n['url'] && preg_match('#programada=([0-9a-f\-]{36})#', $n['url'], $m) ? $m[1] : null,
            'alert_uuid' => $n['alert_uuid'], 'alert_acked' => $n['alert_acked_at'] !== null, 'alert_acked_by' => $n['alert_acked_name'],
        ], Notifications::forUser((int) $user['id'], 50));
        return Response::json(['unread' => Notifications::unreadCount((int) $user['id']), 'items' => $items]);
    }

    public function readAll(Request $request): Response
    {
        Notifications::markRead((int) UserAuth::user()['id']);
        return Response::json(['read' => true]);
    }

    public function ack(Request $request, string $uuid): Response
    {
        $alert = Alerts::findByUuid($uuid);
        if ($alert === null || Observations::findById((int) $alert['observation_id']) === null) {
            return Response::jsonError('Alerta inexistente.', 404);
        }
        Escalations::ack($alert, UserAuth::user());
        return Response::json(['acked' => true]);
    }
}
