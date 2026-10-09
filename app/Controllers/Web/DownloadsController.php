<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\AppReleases;
use App\Services\AppDistribution;

/** Página pública de descarga de la app Android (QR para escanear con el celular) y el APK. Sin sesión: la app pide ingresar. */
final class DownloadsController
{
    public function android(Request $request): Response
    {
        return Response::html(View::render('downloads/android', [
            'title'   => 'Descargar la app',
            'release' => AppReleases::latest(),
            'page'    => absolute_url('/descargas/android'),
        ], 'layouts/blank'));
    }

    public function apk(Request $request, string $code): Response
    {
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
            'Cache-Control'       => 'public, max-age=86400',
        ]);
    }
}
