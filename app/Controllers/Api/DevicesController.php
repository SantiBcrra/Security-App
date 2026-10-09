<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Models\PushSubscriptions;
use App\Services\ApiAuth;
use App\Services\Notify\WebPush;
use App\Services\UserAuth;

/** Registro de notificaciones push de dispositivos web autorizados. */
final class DevicesController
{
    public function vapidKey(Request $request): Response
    {
        return Response::json(['public_key' => WebPush::publicKey()]);
    }

    public function subscribe(Request $request): Response
    {
        $sub = (array) $request->input('subscription', []);
        $endpoint = (string) ($sub['endpoint'] ?? '');
        $keys = (array) ($sub['keys'] ?? []);
        if (!preg_match('#^https://#', $endpoint) || strlen($endpoint) > 500 || empty($keys['p256dh']) || empty($keys['auth'])) {
            return Response::jsonError('Suscripción inválida.', 422);
        }
        PushSubscriptions::save((int) UserAuth::user()['id'], ApiAuth::device()['device_uuid'] ?? null, $endpoint,
            (string) $keys['p256dh'], (string) $keys['auth'], $request->header('user-agent'));
        return Response::json(['subscribed' => true]);
    }

    public function unsubscribe(Request $request): Response
    {
        PushSubscriptions::deleteByEndpoint((int) UserAuth::user()['id'], (string) $request->input('endpoint', ''));
        return Response::json(['subscribed' => false]);
    }
}
