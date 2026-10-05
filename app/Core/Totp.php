<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Códigos de un solo uso por tiempo (TOTP, RFC 6238) compatibles con Google Authenticator,
 * Microsoft Authenticator, Authy, etc. PHP puro, sin librerías.
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Secreto nuevo de 160 bits en base32 (lo que se carga en la app de autenticación). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function code(string $secret, ?int $time = null, int $digits = self::DIGITS): string
    {
        return self::codeForStep(self::base32Decode($secret), intdiv($time ?? time(), self::PERIOD), $digits);
    }

    /** Código para un secreto en bytes crudos (sirve para los vectores de la RFC). */
    public static function codeForStep(string $key, int $step, int $digits = self::DIGITS): string
    {
        $hash = hash_hmac('sha1', pack('N2', $step >> 32, $step & 0xFFFFFFFF), $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);
        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Verifica un código aceptando ±1 período de desfase de reloj.
     * Devuelve el paso de tiempo usado (para impedir reutilizar el mismo código) o null.
     */
    public static function verify(string $secret, string $code, ?int $lastUsedStep = null, ?int $time = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{' . self::DIGITS . '}$/', $code)) {
            return null;
        }
        $key = self::base32Decode($secret);
        $current = intdiv($time ?? time(), self::PERIOD);
        foreach ([0, -1, 1] as $drift) {
            $step = $current + $drift;
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue; // ya usado: no se acepta dos veces el mismo código
            }
            if (hash_equals(self::codeForStep($key, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(preg_replace('/[\s=]/', '', $text));
        $bits = '';
        foreach (str_split($text) as $char) {
            $pos = strpos(self::ALPHABET, $char);
            if ($pos === false) {
                throw new \InvalidArgumentException('Secreto base32 inválido.');
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
