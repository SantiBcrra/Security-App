<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/**
 * App de campo instalable (PWA) en /movil/. Funciona offline: el service worker guarda la app y
 * los datos viven en el celular (IndexedDB) hasta que hay señal. Habla con la API v1 (JWT).
 */
final class MobileController
{
    /** Archivos que el service worker guarda para abrir la app sin conexión. */
    private const ASSETS = [
        'css/bootstrap.min.css', 'js/alpine.min.js', 'movil/movil.css', 'movil/app.js', 'movil/db.js', 'movil/api.js',
        'movil/sync.js', 'movil/icon-192.png', 'movil/icon-512.png', 'movil/apple-touch-icon.png',
    ];

    public function shell(Request $request): Response
    {
        $raw = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/movil/', PHP_URL_PATH);
        if (!isset($_GET['r']) && !str_ends_with($raw, '/')) {
            return Response::redirect('/movil/'); // el alcance del service worker es /movil/
        }
        return new Response(View::render('movil/shell', ['version' => self::version()], null), 200, [
            'Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache',
        ]);
    }

    public function serviceWorker(Request $request): Response
    {
        $base = App::baseUrl();
        $shell = array_merge([$base . '/movil/'], array_map(fn ($a) => $base . '/assets/' . $a, self::ASSETS));
        $js = "const BASE = " . json_encode($base) . ";\nconst VERSION = " . json_encode(self::version()) . ";\nconst SHELL = "
            . json_encode($shell, JSON_UNESCAPED_SLASHES) . ";\n" . file_get_contents(BASE_PATH . '/public/assets/movil/sw-template.js');
        return new Response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8', 'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => $base . '/movil/',
        ]);
    }

    public function manifest(Request $request): Response
    {
        $base = App::baseUrl();
        $manifest = [
            'name' => config('app.name') . ' · Campo', 'short_name' => 'Seguridad', 'lang' => 'es',
            'description' => 'Reportes de seguridad e higiene en planta, con o sin señal.',
            'start_url' => $base . '/movil/', 'scope' => $base . '/movil/', 'id' => $base . '/movil/',
            'display' => 'standalone', 'orientation' => 'portrait', 'background_color' => '#212529', 'theme_color' => '#212529',
            'icons' => [
                ['src' => $base . '/assets/movil/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => $base . '/assets/movil/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => $base . '/assets/movil/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
        return new Response(json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 200, ['Content-Type' => 'application/manifest+json']);
    }

    /** Cambia cuando cambia cualquier archivo de la app: el service worker se actualiza solo. */
    private static function version(): string
    {
        $stamp = '';
        foreach (array_merge(self::ASSETS, ['movil/sw-template.js']) as $a) {
            $stamp .= (string) @filemtime(BASE_PATH . '/public/assets/' . $a);
        }
        $stamp .= (string) @filemtime(BASE_PATH . '/app/Views/movil/shell.php');
        return substr(md5($stamp), 0, 10);
    }
}
