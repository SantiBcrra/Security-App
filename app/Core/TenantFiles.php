<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Archivos de cada empresa en storage/tenants/{uuid}/... Nunca se sirven directo: se entregan
 * por un controlador que valida permisos, o por un link firmado (SignedUrl).
 * Se guardan con nombre generado (UUID) y validando el tipo REAL del contenido (finfo).
 */
final class TenantFiles
{
    public const IMAGE_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    public static function dir(string $tenantUuid, string $sub = ''): string
    {
        if (!Uuid::isValid($tenantUuid)) {
            throw new \InvalidArgumentException('UUID de empresa inválido.');
        }
        $dir = Storage::path('tenants/' . $tenantUuid . ($sub !== '' ? '/' . trim($sub, '/') : ''));
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("No se pudo crear la carpeta {$dir}");
        }
        return $dir;
    }

    /**
     * Guarda un archivo subido ($_FILES['x']) validando tamaño y tipo real.
     * @param array<string,string> $allowed mime => extensión
     * @return string ruta relativa a la carpeta de la empresa (ej: "logo/uuid.png")
     */
    public static function storeUpload(string $tenantUuid, array $file, string $sub, array $allowed, int $maxBytes): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new \DomainException(self::uploadError((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }
        return self::storeFile($tenantUuid, $file['tmp_name'], $sub, $allowed, $maxBytes, true);
    }

    /** Igual que storeUpload pero desde un archivo local (tests, importaciones). */
    public static function storeFile(string $tenantUuid, string $source, string $sub, array $allowed, int $maxBytes, bool $isUpload = false): string
    {
        $size = filesize($source);
        if ($size === false || $size === 0 || $size > $maxBytes) {
            throw new \DomainException('El archivo pesa más de ' . round($maxBytes / 1024 / 1024, 1) . ' MB o está vacío.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        if (!isset($allowed[$mime])) {
            throw new \DomainException('Tipo de archivo no permitido (' . $mime . ').');
        }
        $name = Uuid::v4() . '.' . $allowed[$mime];
        $target = self::dir($tenantUuid, $sub) . '/' . $name;
        $ok = $isUpload ? move_uploaded_file($source, $target) : copy($source, $target);
        if (!$ok) {
            throw new \RuntimeException('No se pudo guardar el archivo.');
        }
        return trim($sub, '/') . '/' . $name;
    }

    /** Ruta absoluta de un archivo de la empresa, sin permitir salir de su carpeta. */
    public static function path(string $tenantUuid, string $relative): ?string
    {
        if (!preg_match('#^[a-z0-9_\-]+(/[a-z0-9_\-]+)*/[a-f0-9\-]{36}\.[a-z0-9]{2,5}$#', $relative)) {
            return null;
        }
        $full = self::dir($tenantUuid) . '/' . $relative;
        return is_file($full) ? $full : null;
    }

    public static function response(string $fullPath, int $maxAge = 300): Response
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($fullPath) ?: 'application/octet-stream';
        return new Response((string) file_get_contents($fullPath), 200, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'inline',
            'Cache-Control'       => 'private, max-age=' . $maxAge,
        ]);
    }

    private static function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño permitido por el servidor.',
            UPLOAD_ERR_PARTIAL => 'El archivo se subió incompleto. Reintentá.',
            UPLOAD_ERR_NO_FILE => 'No se eligió ningún archivo.',
            default => 'No se pudo subir el archivo (código ' . $code . ').',
        };
    }
}
