<?php
declare(strict_types=1);

namespace App\Core;

/** CUIT/CUIL argentino: normalización, dígito verificador y formato XX-XXXXXXXX-X. */
final class Cuit
{
    private const WEIGHTS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];

    public static function normalize(?string $cuit): string
    {
        return preg_replace('/\D/', '', (string) $cuit);
    }

    public static function isValid(?string $cuit): bool
    {
        $digits = self::normalize($cuit);
        if (strlen($digits) !== 11) {
            return false;
        }
        $sum = 0;
        foreach (self::WEIGHTS as $i => $weight) {
            $sum += (int) $digits[$i] * $weight;
        }
        $check = 11 - ($sum % 11);
        $check = $check === 11 ? 0 : $check;
        return $check !== 10 && $check === (int) $digits[10];
    }

    public static function format(?string $cuit): string
    {
        $d = self::normalize($cuit);
        return strlen($d) === 11 ? substr($d, 0, 2) . '-' . substr($d, 2, 8) . '-' . $d[10] : (string) $cuit;
    }
}
