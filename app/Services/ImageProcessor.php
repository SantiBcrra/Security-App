<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\TenantFiles;

/**
 * Fotos de evidencia: guarda el archivo tal cual llegó (nunca se modifica), calcula SHA-256,
 * lee dimensiones y EXIF original (si el servidor tiene la extensión exif) y genera una
 * miniatura APARTE con GD (corrigiendo la orientación solo en la miniatura).
 */
final class ImageProcessor
{
    public const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    public const MAX_BYTES = 15 * 1024 * 1024;
    private const THUMB = 480;
    private const EXIF_KEYS = ['Make', 'Model', 'DateTimeOriginal', 'DateTime', 'Orientation', 'GPSLatitude', 'GPSLatitudeRef',
        'GPSLongitude', 'GPSLongitudeRef', 'GPSAltitude', 'ExifImageWidth', 'ExifImageLength', 'Software'];

    /** @param bool $isUpload true = viene de $_FILES (move_uploaded_file) */
    public static function store(string $tenantUuid, string $source, ?string $originalName, bool $isUpload, ?int $userId): array
    {
        $sub = 'observations/' . gmdate('Y') . '/' . gmdate('m');
        $relative = TenantFiles::storeFile($tenantUuid, $source, $sub, self::TYPES, self::MAX_BYTES, $isUpload);
        $full = TenantFiles::path($tenantUuid, $relative);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($full);
        $size = @getimagesize($full) ?: [null, null];

        return [
            'path'          => $relative,
            'thumb_path'    => self::thumbnail($tenantUuid, $full, $sub, $mime),
            'original_name' => $originalName ? mb_substr(basename($originalName), 0, 191) : null,
            'mime'          => $mime,
            'size_bytes'    => (int) filesize($full),
            'sha256'        => hash_file('sha256', $full),
            'width'         => $size[0],
            'height'        => $size[1],
            'exif'          => self::exif($full, $mime),
            'uploaded_by'   => $userId,
        ];
    }

    private static function exif(string $path, string $mime): ?string
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return null;
        }
        $data = @exif_read_data($path);
        if (!is_array($data)) {
            return null;
        }
        $keep = [];
        foreach (self::EXIF_KEYS as $key) {
            if (isset($data[$key])) {
                $keep[$key] = $data[$key];
            }
        }
        return $keep ? json_encode($keep, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null;
    }

    private static function thumbnail(string $tenantUuid, string $full, string $sub, string $mime): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }
        $src = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($full),
            'image/png'  => @imagecreatefrompng($full),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($full) : false,
            default      => false,
        };
        if (!$src) {
            return null;
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $orientation = (int) (@exif_read_data($full)['Orientation'] ?? 1);
            $src = match ($orientation) {
                3 => imagerotate($src, 180, 0),
                6 => imagerotate($src, -90, 0),
                8 => imagerotate($src, 90, 0),
                default => $src,
            };
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, self::THUMB / max($w, $h));
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));
        $thumb = imagecreatetruecolor($tw, $th);
        imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        $tmp = tempnam(sys_get_temp_dir(), 'th');
        imagejpeg($thumb, $tmp, 80);
        imagedestroy($thumb);
        imagedestroy($src);
        try {
            return TenantFiles::storeFile($tenantUuid, $tmp, $sub, ['image/jpeg' => 'jpg'], self::MAX_BYTES);
        } finally {
            @unlink($tmp);
        }
    }
}
