<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\AppReleases;
use App\Services\AppDistribution;

/**
 * Página de descarga de la app Android y el APK. Requiere sesión (decisión del usuario: que no la baje cualquiera): un
 * usuario de una empresa o el super-admin. Normalmente la baja un administrador y la pasa por WhatsApp. La app instalada
 * se actualiza por la API con su propio token (`GET /api/v1/app/android/apk`).
 */
final class DownloadsController
{
    /** Super-admin, o usuario de una empresa (RequireTenant: sin sesión manda a /login y vuelve acá). */
    private function guard(Request $request, callable $next): Response
    {
        if (\App\Services\AdminAuth::user() !== null) return $next($request);
        return (new \App\Middleware\RequireTenant())($request, $next);
    }

    public function android(Request $request): Response
    {
        return $this->guard($request, fn () => $this->page());
    }

    private function page(): Response
    {
        return Response::html(View::render('downloads/android', [
            'title'   => 'Descargar la app',
            'release' => AppReleases::latest(),
            'page'    => absolute_url('/descargas/android'),
        ], 'layouts/blank'));
    }

    public function apk(Request $request, string $code): Response
    {
        return $this->guard($request, fn () => self::send($code));
    }

    /** APK de una versión publicada (también lo usa la API de la app). @param string $code versionCode o "latest" */
    public static function send(string $code): Response
    {
        if ($code === 'latest') $code = (string) (AppReleases::latest()['version_code'] ?? '');
        $r = null;
        foreach (AppReleases::all() as $row) {
            if ((string) $row['version_code'] === $code && (int) $row['is_active']) {
                $r = $row;
            }
        }
        $file = $r ? AppDistribution::filePath($r) : null;
        if ($file === null) {
            return new Response('Versión no disponible.', 404);
        }
        return new Response((string) file_get_contents($file), 200, [
            'Content-Type'        => 'application/vnd.android.package-archive',
            'Content-Disposition' => 'attachment; filename="SecurityApp-' . $r['version_name'] . '.apk"',
            'Content-Length'      => (string) filesize($file),
            'Cache-Control'       => 'private, no-store',
        ]);
    }
}
