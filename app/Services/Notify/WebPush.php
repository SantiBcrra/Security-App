<?php
declare(strict_types=1);

namespace App\Services\Notify;

use App\Core\Ece;
use App\Core\Http;
use App\Core\Vapid;
use App\Models\PlatformSettings;
use App\Models\PushSubscriptions;

/** Envío Web Push para navegadores autorizados: VAPID + cifrado aes128gcm, sin librerías. */
final class WebPush
{
    /** Clave pública VAPID (base64url) que el navegador necesita para suscribirse. Se genera una vez. */
    public static function publicKey(): string
    {
        if (!PlatformSettings::get('push.vapid_public')) {
            $keys = Vapid::generate();
            PlatformSettings::setSecret('push.vapid_private', $keys['private_pem']);
            PlatformSettings::set('push.vapid_public', Ece::b64u($keys['public']));
        }
        return (string) PlatformSettings::get('push.vapid_public');
    }

    /** @throws \RuntimeException si falla (se reintenta desde la cola) */
    public static function send(array $subscription, array $payload, bool $urgent): void
    {
        $public = Ece::unb64u(self::publicKey());
        $private = (string) PlatformSettings::secret('push.vapid_private');
        $subject = 'mailto:' . (PlatformSettings::get('mail.from_email') ?: 'avisos@securityapp.local');
        $body = Ece::encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            Ece::unb64u($subscription['p256dh']), Ece::unb64u($subscription['auth']));
        $res = Http::post($subscription['endpoint'], $body, [
            'Authorization: ' . Vapid::authorization($subscription['endpoint'], $public, $private, $subject),
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'TTL: ' . ($urgent ? 3600 : 86400),
            'Urgency: ' . ($urgent ? 'high' : 'normal'),
        ], 6);
        if ($res['status'] === 404 || $res['status'] === 410) {
            PushSubscriptions::delete((int) $subscription['id']); // el navegador se desuscribió: no reintentar
            return;
        }
        if ($res['error'] || $res['status'] >= 400) {
            throw new \RuntimeException('Web Push ' . $res['status'] . ': ' . ($res['error'] ?: mb_substr($res['body'], 0, 200)));
        }
        PushSubscriptions::touch((int) $subscription['id']);
    }
}
