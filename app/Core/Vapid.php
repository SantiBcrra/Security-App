<?php
declare(strict_types=1);

namespace App\Core;

/**
 * VAPID (RFC 8292): identifica a nuestro servidor ante los servicios de push (Google, Mozilla,
 * Apple) con un JWT firmado ES256. Las claves se generan una vez y la privada se guarda cifrada.
 */
final class Vapid
{
    /** @return array{public:string, private_pem:string} public = 65 bytes crudos */
    public static function generate(): array
    {
        $pkey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($pkey, $pem);
        $d = openssl_pkey_get_details($pkey)['ec'];
        return ['public' => "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT), 'private_pem' => $pem];
    }

    /** Encabezado Authorization para un endpoint de push. */
    public static function authorization(string $endpoint, string $publicRaw, string $privatePem, string $subject, int $ttl = 43200): string
    {
        $parts = parse_url($endpoint);
        $claims = ['aud' => $parts['scheme'] . '://' . $parts['host'], 'exp' => time() + $ttl, 'sub' => $subject];
        $input = Ece::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . Ece::b64u(json_encode($claims, JSON_UNESCAPED_SLASHES));
        if (!openssl_sign($input, $der, $privatePem, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('VAPID: no se pudo firmar.');
        }
        return 'vapid t=' . $input . '.' . Ece::b64u(self::derToRaw($der)) . ', k=' . Ece::b64u($publicRaw);
    }

    /** Firma ECDSA DER (SEQUENCE{r,s}) → r||s de 64 bytes (formato JWS). */
    public static function derToRaw(string $der): string
    {
        $offset = 2 + (ord($der[1]) > 0x80 ? ord($der[1]) - 0x80 : 0);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$offset + 1]);
            $int = substr($der, $offset + 2, $len);
            $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $len;
        }
        return $out;
    }

    /** r||s → DER (para verificar con openssl_verify en los tests). */
    public static function rawToDer(string $raw): string
    {
        $int = function (string $v): string {
            $v = ltrim($v, "\0");
            if ($v === '' || ord($v[0]) > 0x7f) {
                $v = "\0" . $v;
            }
            return "\x02" . chr(strlen($v)) . $v;
        };
        $seq = $int(substr($raw, 0, 32)) . $int(substr($raw, 32, 32));
        return "\x30" . chr(strlen($seq)) . $seq;
    }
}
