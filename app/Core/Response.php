<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** Formato estándar de la API: { ok, data, error } */
    public static function json(mixed $data = null, int $status = 200): self
    {
        return self::jsonRaw(['ok' => true, 'data' => $data, 'error' => null], $status);
    }

    public static function jsonError(string $message, int $status = 400, ?string $code = null): self
    {
        return self::jsonRaw([
            'ok'    => false,
            'data'  => null,
            'error' => ['message' => $message, 'code' => $code],
        ], $status);
    }

    private static function jsonRaw(array $payload, int $status): self
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return new self($body, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function redirect(string $path, int $status = 302): self
    {
        $location = preg_match('#^https?://#', $path) ? $path : url($path);
        return new self('', $status, ['Location' => $location]);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach (self::securityHeaders($this->headers['Content-Type'] ?? '') as $name => $value) {
                header($name . ': ' . $value);
            }
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }

    private static function securityHeaders(string $contentType): array
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options'        => 'SAMEORIGIN',
            'Referrer-Policy'        => 'strict-origin-when-cross-origin',
        ];
        if (str_starts_with($contentType, 'text/html')) {
            // Solo recursos propios (+ mapas de OpenStreetMap). Alpine.js (build estándar) necesita 'unsafe-eval'.
            $headers['Content-Security-Policy'] = "default-src 'self'; img-src 'self' data: blob: https://*.tile.openstreetmap.org; "
                . "style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
                . "frame-ancestors 'self'; base-uri 'self'; form-action 'self'";
        }
        if (App::isHttps()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        return $headers;
    }
}
