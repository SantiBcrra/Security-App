<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Cifrado de mensajes Web Push (RFC 8291 sobre "aes128gcm" de la RFC 8188) con openssl puro.
 * El navegador del celular entrega su clave pública (p256dh) y un secreto (auth); el servidor
 * cifra el contenido para que solo ese navegador lo pueda leer (el servicio de push no lo ve).
 */
final class Ece
{
    private const RECORD_SIZE = 4096;

    /**
     * @param string $uaPublic clave pública del navegador (65 bytes, 0x04||X||Y)
     * @param string $authSecret secreto del navegador (16 bytes)
     * @param ?array $asKey para tests: ['private' => d (32 bytes), 'public' => 65 bytes]
     * @return string cuerpo binario listo para enviar con "Content-Encoding: aes128gcm"
     */
    public static function encrypt(string $plaintext, string $uaPublic, string $authSecret, ?array $asKey = null, ?string $salt = null): string
    {
        if ($asKey === null) {
            $pkey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            $details = openssl_pkey_get_details($pkey);
            $asPublic = "\x04" . str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
            $asPrivate = $pkey;
        } else {
            $asPublic = $asKey['public'];
            $asPrivate = openssl_pkey_get_private(self::privatePem($asKey['private'], $asKey['public']));
        }
        $salt ??= random_bytes(16);
        $shared = openssl_pkey_derive(openssl_pkey_get_public(self::publicPem($uaPublic)), $asPrivate, 32);
        if ($shared === false) {
            throw new \RuntimeException('Web Push: no se pudo derivar la clave (p256dh inválida).');
        }
        [$cek, $nonce] = self::keys($shared, $authSecret, $uaPublic, $asPublic, $salt);
        $tag = '';
        $cipher = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        return $salt . pack('N', self::RECORD_SIZE) . chr(strlen($asPublic)) . $asPublic . $cipher . $tag;
    }

    /** Descifrado (lo usa el navegador; acá sirve para probar el cifrado de punta a punta). */
    public static function decrypt(string $body, string $uaPrivate, string $uaPublic, string $authSecret): string
    {
        $salt = substr($body, 0, 16);
        $idLen = ord($body[20]);
        $asPublic = substr($body, 21, $idLen);
        $payload = substr($body, 21 + $idLen);
        $shared = openssl_pkey_derive(openssl_pkey_get_public(self::publicPem($asPublic)),
            openssl_pkey_get_private(self::privatePem($uaPrivate, $uaPublic)), 32);
        [$cek, $nonce] = self::keys($shared, $authSecret, $uaPublic, $asPublic, $salt);
        $plain = openssl_decrypt(substr($payload, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($payload, -16));
        if ($plain === false) {
            throw new \RuntimeException('Web Push: no se pudo descifrar.');
        }
        return rtrim($plain, "\0") === '' ? '' : substr(rtrim($plain, "\0"), 0, -1); // saca el delimitador 0x02
    }

    private static function keys(string $shared, string $authSecret, string $uaPublic, string $asPublic, string $salt): array
    {
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        return [
            hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt),
            hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt),
        ];
    }

    /** Clave pública P-256 cruda (65 bytes) → PEM (SubjectPublicKeyInfo). */
    public static function publicPem(string $raw): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** Clave privada P-256 cruda (d de 32 bytes + pública) → PEM (ECPrivateKey). */
    public static function privatePem(string $d, string $public): string
    {
        $der = hex2bin('30770201010420') . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $public;
        return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    }

    public static function b64u(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function unb64u(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
