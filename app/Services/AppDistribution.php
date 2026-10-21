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
        // La versión sale del APK: lo que se escribió a mano solo se usa si no se puede leer.
        $real = $file !== null && ($file['error'] ?? 1) === UPLOAD_ERR_OK && is_file((string) ($file['tmp_name'] ?? '')) ? self::apkVersion($file['tmp_name']) : null;
        $code = $real['code'] ?? (int) ($in['version_code'] ?? 0);
        $name = ($real['name'] ?? '') !== '' ? $real['name'] : trim((string) ($in['version_name'] ?? ''));
        $min = ($in['min_version_code'] ?? '') === '' ? 0 : (int) $in['min_version_code'];
        if ($code < 1) {
            $errors['version_code'] = 'Número de versión (versionCode) entero, mayor a 0.';
        } elseif ($code <= AppReleases::maxCode()) {
            $errors['version_code'] = 'El APK es la versión ' . $name . ' (código ' . $code . ') y ya hay una igual o más nueva publicada (código ' . AppReleases::maxCode() . '): '
                . 'subí el número de versión en la app (versionCode) y compilala de nuevo.';
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
        $ok = ($file['upload'] ?? true) ? move_uploaded_file($file['tmp_name'], $target)
            : (!empty($file['move']) || !empty($file['cleanup']) ? rename($file['tmp_name'], $target) : copy($file['tmp_name'], $target));
        if ($ok && !empty($file['cleanup'])) self::chunkCleanup((string) $file['cleanup']);
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

    // ── subida por partes (hostings con post_max_size chico o internet lento) ──────────
    // El navegador corta el APK en partes de 1 MB; si se corta, retoma desde el tamaño que ya llegó. Al final se arma el
    // archivo, se verifica su SHA-256 y se publica con upload() (como si se hubiera subido entero).

    public const CHUNK_BYTES = 1024 * 1024;
    /** Carpeta para subir el APK por FTP / administrador de archivos (alternativa manual). */
    public const INBOX = 'apps/android/subir';

    private static function partPath(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) throw new \App\Core\UserError('Subida inválida.');
        return Storage::path('apps/android/partes/' . $id . '.part');
    }

    /** @return array{id: string, received: int} */
    public static function chunkStart(string $name, int $size, string $sha256): array
    {
        if (!str_ends_with(strtolower($name), '.apk')) throw new \App\Core\UserError('El archivo tiene que ser .apk.');
        if ($size < 1 || $size > self::MAX_BYTES) throw new \App\Core\UserError('El APK pesa más de 150 MB.');
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) throw new \App\Core\UserError('Falta la huella del archivo.');
        $dir = Storage::path('apps/android/partes');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new \RuntimeException('No se pudo crear la carpeta temporal.');
        foreach (glob($dir . '/*') ?: [] as $old) { // restos de subidas abandonadas hace más de 2 días
            if (filemtime($old) < time() - 2 * 86400) @unlink($old);
        }
        // Mismo archivo (misma huella y tamaño): se retoma la subida anterior.
        $id = substr(hash('sha256', $sha256 . ':' . $size), 0, 32);
        file_put_contents(Storage::path('apps/android/partes/' . $id . '.json'), json_encode(['name' => $name, 'size' => $size, 'sha256' => $sha256]));
        $part = self::partPath($id);
        return ['id' => $id, 'received' => is_file($part) ? (int) filesize($part) : 0];
    }

    /** Agrega una parte en el lugar `offset` (si no coincide con lo recibido, responde lo recibido para retomar). */
    public static function chunkAppend(string $id, int $offset, string $bytes): int
    {
        $part = self::partPath($id);
        $meta = json_decode((string) @file_get_contents(Storage::path('apps/android/partes/' . $id . '.json')), true);
        if (!$meta) throw new \App\Core\UserError('La subida no existe o venció: empezá de nuevo.');
        clearstatcache(true, $part);
        $have = is_file($part) ? (int) filesize($part) : 0;
        if ($offset !== $have || $bytes === '') return $have;
        if ($have + strlen($bytes) > (int) $meta['size'] || strlen($bytes) > self::CHUNK_BYTES * 2) throw new \App\Core\UserError('La parte no corresponde al archivo.');
        $fh = fopen($part, 'ab');
        if ($fh === false || !flock($fh, LOCK_EX)) throw new \RuntimeException('No se pudo escribir la parte.');
        clearstatcache(true, $part);
        if ((int) filesize($part) === $offset) fwrite($fh, $bytes);
        flock($fh, LOCK_UN);
        fclose($fh);
        clearstatcache(true, $part);
        return (int) filesize($part);
    }

    /** Archivo armado listo para upload() (verifica tamaño y SHA-256). */
    public static function chunkFile(string $id): array
    {
        $part = self::partPath($id);
        $meta = json_decode((string) @file_get_contents(Storage::path('apps/android/partes/' . $id . '.json')), true);
        clearstatcache(true, $part);
        if (!$meta || !is_file($part) || (int) filesize($part) !== (int) $meta['size']) {
            return ['error' => UPLOAD_ERR_PARTIAL, 'name' => (string) ($meta['name'] ?? ''), 'tmp_name' => '', 'size' => 0];
        }
        if (!hash_equals((string) $meta['sha256'], hash_file('sha256', $part))) {
            @unlink($part);
            throw new \App\Core\UserError('El archivo llegó dañado (la huella no coincide): volvé a subirlo.');
        }
        return ['error' => UPLOAD_ERR_OK, 'name' => $meta['name'], 'tmp_name' => $part, 'size' => (int) $meta['size'], 'upload' => false, 'cleanup' => $id];
    }

    public static function chunkCleanup(string $id): void
    {
        @unlink(self::partPath($id));
        @unlink(Storage::path('apps/android/partes/' . $id . '.json'));
    }

    /** APKs subidos a mano a storage/apps/android/subir (FTP o administrador de archivos del hosting). @return list<array{name: string, size: int}> */
    public static function inbox(): array
    {
        $dir = Storage::path(self::INBOX);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $out = [];
        foreach (glob($dir . '/*.apk') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'at' => (int) filemtime($f)];
        }
        return $out;
    }

    public static function inboxFile(string $name): ?array
    {
        $name = basename($name);
        $path = Storage::path(self::INBOX . '/' . $name);
        if (!str_ends_with(strtolower($name), '.apk') || !is_file($path)) return null;
        return ['error' => UPLOAD_ERR_OK, 'name' => $name, 'tmp_name' => $path, 'size' => (int) filesize($path), 'upload' => false, 'move' => true];
    }

    /**
     * versionCode y versionName que trae el APK (AndroidManifest.xml en XML binario de Android). Así el código publicado es
     * siempre el real: si se publicara con otro número, los celulares no verían la actualización.
     * @return array{code: int, name: string}|null
     */
    public static function apkVersion(string $path): ?array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) return null;
        $xml = $zip->getFromName('AndroidManifest.xml');
        $zip->close();
        if (!is_string($xml) || strlen($xml) < 8 || unpack('v', $xml, 0)[1] !== 0x0003) return null;
        $u16 = fn (int $o) => unpack('v', $xml, $o)[1];
        $u32 = fn (int $o) => unpack('V', $xml, $o)[1];
        $strings = [];
        $resIds = [];
        $pos = $u16(2); // después del encabezado del documento
        $len = strlen($xml);
        while ($pos + 8 <= $len) {
            [$type, $hsize, $size] = [$u16($pos), $u16($pos + 2), $u32($pos + 4)];
            if ($size < 8) break;
            if ($type === 0x0001) { // tabla de textos
                $count = $u32($pos + 8);
                $utf8 = ($u32($pos + 16) & 0x100) !== 0;
                $start = $pos + $u32($pos + 20);
                for ($i = 0; $i < $count; $i++) {
                    $o = $start + $u32($pos + $hsize + $i * 4);
                    if ($utf8) {
                        $o += (ord($xml[$o]) & 0x80) ? 2 : 1; // largo en UTF-16
                        $n = ord($xml[$o]);
                        if ($n & 0x80) { $n = (($n & 0x7F) << 8) | ord($xml[$o + 1]); $o += 2; } else { $o += 1; }
                        $strings[$i] = substr($xml, $o, $n);
                    } else {
                        $n = $u16($o);
                        if ($n & 0x8000) { $n = (($n & 0x7FFF) << 16) | $u16($o + 2); $o += 4; } else { $o += 2; }
                        $strings[$i] = mb_convert_encoding(substr($xml, $o, $n * 2), 'UTF-8', 'UTF-16LE');
                    }
                }
            } elseif ($type === 0x0180) { // ids de recursos de los atributos
                for ($o = $pos + 8; $o + 4 <= $pos + $size; $o += 4) $resIds[] = $u32($o);
            } elseif ($type === 0x0102) { // primer elemento = <manifest>
                $attrStart = $u16($pos + 24);
                $count = $u16($pos + 28);
                $code = null;
                $name = null;
                for ($i = 0; $i < $count; $i++) {
                    $a = $pos + 16 + $attrStart + $i * 20;
                    $attr = $u32($a + 4);
                    $raw = $u32($a + 8);
                    $dataType = ord($xml[$a + 15]);
                    $data = $u32($a + 16);
                    $id = $resIds[$attr] ?? 0;
                    $key = $strings[$attr] ?? '';
                    if ($id === 0x0101021b || $key === 'versionCode') $code = $data;
                    if ($id === 0x0101021c || $key === 'versionName') $name = $raw !== 0xFFFFFFFF ? ($strings[$raw] ?? null) : ($dataType === 0x03 ? ($strings[$data] ?? null) : null);
                }
                return $code !== null ? ['code' => (int) $code, 'name' => (string) $name] : null;
            }
            $pos += $size;
        }
        return null;
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
            'url' => absolute_url('/api/v1/app/android/apk'), 'published_at' => str_replace(' ', 'T', $r['created_at']) . 'Z'];
    }

    public static function filePath(array $r): ?string
    {
        $path = Storage::path($r['path']);
        return is_file($path) ? $path : null;
    }
}
