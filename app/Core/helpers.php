<?php
declare(strict_types=1);

use App\Core\App;
use App\Core\Config;

/** Escapa para HTML. Usar SIEMPRE al imprimir datos en vistas. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** URL interna respetando la carpeta de instalación: url('/observaciones') */
function url(string $path = '/'): string
{
    return App::baseUrl() . '/' . ltrim($path, '/');
}

/** URL de un archivo en public/assets con versión para invalidar caché. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = BASE_PATH . '/public/assets/' . $path;
    $version = is_file($file) ? (string) filemtime($file) : '';
    return url('assets/' . $path) . ($version !== '' ? '?v=' . $version : '');
}

function config(string $key, mixed $default = null): mixed
{
    return Config::get($key, $default);
}

/** Fecha UTC de la base → zona horaria de la empresa activa (o la por defecto), para mostrar. */
function fecha(?string $utc, string $format = 'd/m/Y H:i', ?string $timezone = null): string
{
    if ($utc === null || $utc === '') {
        return '';
    }
    $tz = new DateTimeZone($timezone ?? \App\Core\Tenant::timezone() ?? (string) Config::get('app.timezone', 'UTC'));
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format($format);
}

function csrf_field(): string
{
    return \App\Core\Csrf::field();
}

function csrf_token(): string
{
    return \App\Core\Csrf::token();
}

/** URL completa (con esquema y dominio) para links que salen del sistema: invitaciones, mails. */
function absolute_url(string $path = '/'): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (\App\Core\App::isHttps() ? 'https://' : 'http://') . $host . url($path);
}

/**
 * Ícono SVG en línea (trazo, 24×24, sin librerías ni CDN). Toma el color del texto (currentColor).
 * Nombres: home, eye, clipboard, check, alert, file, helmet, route, database, users, shield, settings, bell,
 * logout, menu, user, building, layers, clock, chart, x.
 */
function icon(string $name, int $size = 18, string $class = ''): string
{
    static $paths = [
        'home'      => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h4v-6h4v6h4a1 1 0 0 0 1-1V9.5"/>',
        'eye'       => '<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V3h6v1"/><path d="m9 13 2 2 4-4"/>',
        'check'     => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        'alert'     => '<path d="M10.3 3.9 2.4 18a2 2 0 0 0 1.7 3h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'file'      => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9Z"/><path d="M14 3v6h6"/><path d="M8 17c1.5-2 2.5-2 3.5 0s2 2 3.5 0"/>',
        'helmet'    => '<path d="M3 17h18"/><path d="M4 17v-2a8 8 0 0 1 16 0v2"/><path d="M10 7V5h4v2"/><path d="M12 7v6"/><path d="M3 17v2h18v-2"/>',
        'route'     => '<circle cx="6" cy="19" r="2"/><circle cx="18" cy="5" r="2"/><path d="M8 19h8.5a3.5 3.5 0 0 0 0-7h-9a3.5 3.5 0 0 1 0-7H16"/>',
        'database'  => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
        'users'     => '<circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M16 4a4 4 0 0 1 0 8"/><path d="M22 21a7 7 0 0 0-4-6.3"/>',
        'shield'    => '<path d="M12 3 4 6v6c0 5 3.4 8.4 8 9 4.6-.6 8-4 8-9V6Z"/><path d="m9 12 2 2 4-4"/>',
        'settings'  => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
        'bell'      => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'menu'      => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
        'user'      => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'building'  => '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M9 7h1M14 7h1M9 11h1M14 11h1M9 15h1M14 15h1"/><path d="M10 21v-3h4v3"/>',
        'layers'    => '<path d="m12 3 9 5-9 5-9-5Z"/><path d="m3 13 9 5 9-5"/>',
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'chart'     => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
        'x'         => '<path d="M6 6l12 12"/><path d="M18 6 6 18"/>',
    ];
    return '<svg class="icon ' . e($class) . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['file']) . '</svg>';
}
