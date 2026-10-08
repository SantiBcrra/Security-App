<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Tenant;
use App\Core\TenantFiles;
use App\Core\UserError;

/**
 * Firma dibujada en pantalla (canvas → "data:image/png;base64,…"): se valida que sea un PNG real y que tenga
 * trazos, y se guarda como archivo inmutable con su SHA-256. La reutilizan permisos de trabajo, EPP y capacitaciones.
 */
final class Signatures
{
    public const MAX_BYTES = 600 * 1024;
    private const MIN_INK_PIXELS = 150; // menos que esto = firma en blanco o un punto

    /**
     * @return array{path: string, sha256: string}
     * @throws UserError firma vacía o inválida
     */
    public static function store(string $dataUrl, string $folder): array
    {
        if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', trim($dataUrl), $m)) {
            throw new UserError('Falta la firma.');
        }
        $bytes = base64_decode($m[1], true);
        if ($bytes === false || strlen($bytes) < 100 || strlen($bytes) > self::MAX_BYTES) {
            throw new UserError('La firma no es válida.');
        }
        if ((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) !== 'image/png') {
            throw new UserError('La firma no es una imagen válida.');
        }
        if (self::inkPixels($bytes) < self::MIN_INK_PIXELS) {
            throw new UserError('La firma está vacía: firmá en el recuadro.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'sig');
        file_put_contents($tmp, $bytes);
        try {
            $path = TenantFiles::storeFile(Tenant::current()['uuid'], $tmp, $folder . '/' . gmdate('Y') . '/' . gmdate('m'), ['image/png' => 'png'], self::MAX_BYTES);
        } finally {
            @unlink($tmp);
        }
        return ['path' => $path, 'sha256' => hash('sha256', $bytes)];
    }

    /** Píxeles con trazo (no transparentes y no blancos). Sin GD no se puede medir: se acepta. */
    private static function inkPixels(string $png): int
    {
        if (!function_exists('imagecreatefromstring')) {
            return PHP_INT_MAX;
        }
        $img = @imagecreatefromstring($png);
        if (!$img) {
            return 0;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $step = max(1, (int) floor(max($w, $h) / 300)); // muestreo: no hace falta recorrer cada píxel
        $ink = 0;
        for ($y = 0; $y < $h; $y += $step) {
            for ($x = 0; $x < $w; $x += $step) {
                $c = imagecolorat($img, $x, $y);
                $alpha = ($c >> 24) & 0x7F;
                $lum = (($c >> 16) & 0xFF) + (($c >> 8) & 0xFF) + ($c & 0xFF);
                if ($alpha < 100 && $lum < 600) {
                    $ink += $step * $step;
                }
            }
        }
        imagedestroy($img);
        return $ink;
    }
}
