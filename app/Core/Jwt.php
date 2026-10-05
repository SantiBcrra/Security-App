<?php
declare(strict_types=1);

namespace App\Core;

/**
 * JWT HS256 propio (sin librerías). Clave derivada de app.key, comparación en tiempo constante.
 * Solo acepta alg=HS256: nunca "none" ni el algoritmo que diga el token.
 */
final class Jwt
{
    private const LEEWAY = 60; // tolerancia de reloj en segundos

    public static function encode(array $claims, int $ttlSeconds): string
    {
        $now = time();
        $claims += ['iat' => $now, 'exp' => $now + $ttlSeconds];
        $segments = [
            self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])),
            self::b64(json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
        $segments[] = self::b64(self::sign(implode('.', $segments)));
        return implode('.', $segments);
    }

    /** @throws JwtException */
    public static function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new JwtException('Token mal formado.');
        }
        [$h, $p, $s] = $parts;
        $header = json_decode(self::unb64($h), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            throw new JwtException('Algoritmo no permitido.');
        }
        if (!hash_equals(self::sign($h . '.' . $p), self::unb64($s))) {
            throw new JwtException('Firma inválida.');
        }
        $claims = json_decode(self::unb64($p), true);
        if (!is_array($claims) || !isset($claims['exp'], $claims['iat'])) {
            throw new JwtException('Contenido inválido.');
        }
        $now = time();
        if ((int) $claims['exp'] < $now - self::LEEWAY) {
            throw new JwtException('Token vencido.', 'expired');
        }
        if ((int) $claims['iat'] > $now + self::LEEWAY) {
            throw new JwtException('Token emitido en el futuro.');
        }
        return $claims;
    }

    private static function sign(string $data): string
    {
        return hash_hmac('sha256', $data, Crypto::key('jwt'), true);
    }

    public static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function unb64(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}
