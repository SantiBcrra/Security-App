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
