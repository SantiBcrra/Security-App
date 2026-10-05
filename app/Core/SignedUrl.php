<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Links firmados temporales: permiten entregar un archivo sin sesión (ej: <img>, PDF por mail)
 * durante un tiempo acotado. Firma HMAC-SHA256 de ruta + parámetros + vencimiento.
 */
final class SignedUrl
{
    public static function make(string $path, int $ttlSeconds = 600, array $params = []): string
    {
        $path = Request::normalize($path);
        $params['expires'] = (string) (time() + $ttlSeconds);
        ksort($params);
        $params['sig'] = self::sign($path, $params);
        return url($path) . '?' . http_build_query($params);
    }

    public static function verify(Request $request): bool
    {
        $params = $request->query;
        unset($params['r']); // fallback sin mod_rewrite
        $sig = $params['sig'] ?? '';
        unset($params['sig']);
        if (!is_string($sig) || !ctype_digit((string) ($params['expires'] ?? '')) || (int) $params['expires'] < time()) {
            return false;
        }
        ksort($params);
        return hash_equals(self::sign($request->path, $params), $sig);
    }

    private static function sign(string $path, array $params): string
    {
        return hash_hmac('sha256', $path . '?' . http_build_query($params), Crypto::key('signed-url'));
    }
}
