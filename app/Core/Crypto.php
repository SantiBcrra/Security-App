<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Cifrado simétrico AES-256-GCM (openssl) con la app.key de config.local.php.
 * Lo usamos para secretos guardados en la base (ej: contraseña de la base de cada empresa).
 * ⚠️ Si se pierde config.local.php (app.key) estos datos NO se pueden recuperar: respaldarlo.
 */
final class Crypto
{
    private const PREFIX = 'v1:';
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key('encryption'), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('No se pudo cifrar.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload): string
    {
        $raw = str_starts_with($payload, self::PREFIX) ? base64_decode(substr($payload, strlen(self::PREFIX)), true) : false;
        if ($raw === false || strlen($raw) < 28) {
            throw new \RuntimeException('Dato cifrado inválido.');
        }
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, self::key('encryption'), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new \RuntimeException('No se pudo descifrar (¿cambió la app.key o el dato fue alterado?).');
        }
        return $plain;
    }

    /** Clave derivada por uso, para no reutilizar la misma clave en cifrado y en firmas. */
    public static function key(string $purpose): string
    {
        $appKey = base64_decode((string) Config::get('app.key', ''), true);
        if ($appKey === false || strlen($appKey) < 32) {
            throw new \RuntimeException('Falta app.key en config.local.php (la genera el instalador).');
        }
        return hash_hmac('sha256', $purpose, $appKey, true);
    }
}
