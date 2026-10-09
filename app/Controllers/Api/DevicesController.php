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

    /** App Android: con qué proyecto de Firebase conectarse (datos públicos), o null si la plataforma no lo configuró. */
    public function fcmConfig(Request $request): Response
    {
        return Response::json(['fcm' => \App\Services\Notify\Fcm::clientConfig((string) $request->input('package', ''))]);
    }

    /** App Android: el token de Firebase de este dispositivo (vacío = dejar de mandarle). */
    public function fcmToken(Request $request): Response
    {
        $device = ApiAuth::device();
        $token = trim((string) $request->input('token', ''));
        if ($device === null) {
            return Response::jsonError('Dispositivo desconocido.', 422);
        }
        if ($token !== '' && (strlen($token) > 400 || !preg_match('/^[\w:\-.]+$/', $token))) {
            return Response::jsonError('Token inválido.', 422);
        }
        \App\Models\UserDevices::setPushToken((int) $device['id'], $token !== '' ? 'fcm:' . $token : null);
        return Response::json(['registered' => $token !== '']);
    }

    public function unsubscribe(Request $request): Response
    {
        PushSubscriptions::deleteByEndpoint((int) UserAuth::user()['id'], (string) $request->input('endpoint', ''));
        return Response::json(['subscribed' => false]);
    }
}
