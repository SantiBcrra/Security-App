<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Storage;
use App\Models\AppReleases;

/**
 * Distribución propia de la app Android (decisión del usuario: sin Google Play). El super-admin sube el APK firmado;
 * los empleados lo bajan de /descargas/android y la app se actualiza sola consultando /api/v1/app/android.
 * Android solo instala la actualización si la firma coincide con la app instalada.
 */
final class AppDistribution
{
    public const MAX_BYTES = 150 * 1024 * 1024;
    private const APK_MIMES = ['application/vnd.android.package-archive', 'application/java-archive', 'application/zip', 'application/octet-stream'];

    /** @return array{release: ?array, errors: array<string,string>} */
    public static function upload(array $in, ?array $file, ?int $adminId): array
    {
        $errors = [];
        $code = (int) ($in['version_code'] ?? 0);
        $name = trim((string) ($in['version_name'] ?? ''));
        $min = ($in['min_version_code'] ?? '') === '' ? 0 : (int) $in['min_version_code'];
        if ($code < 1) {
            $errors['version_code'] = 'Número de versión (versionCode) entero, mayor a 0.';
        } elseif ($code <= AppReleases::maxCode()) {
            $errors['version_code'] = 'Tiene que ser mayor que la última subida (' . AppReleases::maxCode() . ').';
        }
        if (!preg_match('/^\d+(\.\d+){0,3}([-+][\w.]+)?$/', $name)) {
            $errors['version_name'] = 'Nombre de versión tipo 1.0.3.';
        }
        if ($min < 0 || $min > $code) {
            $errors['min_version_code'] = 'La versión mínima obligatoria no puede ser mayor que la que subís.';
        }
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors['apk'] = 'Elegí el archivo .apk (si es grande, revisá upload_max_filesize del hosting).';
        } elseif (!str_ends_with(strtolower((string) ($file['name'] ?? '')), '.apk')) {
            $errors['apk'] = 'El archivo tiene que ser .apk.';
        } elseif (($file['size'] ?? 0) > self::MAX_BYTES) {
            $errors['apk'] = 'El APK pesa más de 150 MB.';
        } else {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
            if (!in_array($mime, self::APK_MIMES, true) || !self::looksLikeApk($file['tmp_name'])) {
                $errors['apk'] = 'No parece un APK válido.';
            }
        }
        if ($errors) {
            return ['release' => null, 'errors' => $errors];
        }
        $dir = Storage::path('apps/android');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear la carpeta de la app.');
        }
        $relative = 'apps/android/securityapp-' . $code . '-' . bin2hex(random_bytes(4)) . '.apk';
        $target = Storage::path($relative);
        $ok = ($file['upload'] ?? true) ? move_uploaded_file($file['tmp_name'], $target) : copy($file['tmp_name'], $target);
        if (!$ok) {
            throw new \RuntimeException('No se pudo guardar el APK.');
        }
        $uuid = AppReleases::create(['platform' => AppReleases::ANDROID, 'version_code' => $code, 'version_name' => $name, 'min_version_code' => $min,
            'notes' => trim((string) ($in['notes'] ?? '')) ?: null, 'path' => $relative, 'sha256' => hash_file('sha256', $target),
            'size_bytes' => (int) filesize($target), 'uploaded_by' => $adminId]);
        $release = AppReleases::findByUuid($uuid);
        Audit::platform('app.release', 'app_release', $uuid, null, ['version' => $name, 'codigo' => $code, 'minima' => $min]);
        return ['release' => $release, 'errors' => []];
    }

    /** Un APK es un ZIP con AndroidManifest.xml adentro. */
    private static function looksLikeApk(string $path): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }
        $ok = $zip->locateName('AndroidManifest.xml') !== false;
        $zip->close();
        return $ok;
    }

    /** Lo que consulta la app para saber si hay actualización. */
    public static function manifest(): ?array
    {
        $r = AppReleases::latest();
        if ($r === null) {
            return null;
        }
        $min = 0;
        foreach (AppReleases::all() as $row) {
            if ((int) $row['is_active']) {
                $min = max($min, (int) $row['min_version_code']);
            }
        }
        return ['version_code' => (int) $r['version_code'], 'version_name' => $r['version_name'], 'min_version_code' => $min,
            'sha256' => $r['sha256'], 'size_bytes' => (int) $r['size_bytes'], 'notes' => $r['notes'],
            'url' => absolute_url('/descargas/android/' . $r['version_code'] . '.apk'), 'published_at' => str_replace(' ', 'T', $r['created_at']) . 'Z'];
    }

    public static function filePath(array $r): ?string
    {
        $path = Storage::path($r['path']);
        return is_file($path) ? $path : null;
    }
}
