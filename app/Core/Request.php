<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** Parámetros de la ruta ({id}), los completa el Router. */
    public array $params = [];

    /** Cuerpo crudo (subidas por partes). En tests se asigna directo. */
    public ?string $body = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        private readonly array $headers = [],
        public readonly array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Sin mod_rewrite se puede navegar igual con index.php?r=/ruta
        if (isset($_GET['r']) && is_string($_GET['r'])) {
            $path = $_GET['r'];
        } else {
            $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            $path = rawurldecode($path);
            foreach ([App::baseUrl() . '/public', App::baseUrl()] as $prefix) {
                if ($prefix !== '' && str_starts_with($path, $prefix)) {
                    $path = substr($path, strlen($prefix));
                    break;
                }
            }
            if (str_starts_with($path, '/index.php')) {
                $path = substr($path, strlen('/index.php'));
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        // Algunos Apache/CGI eliminan Authorization: lo recuperamos de donde haya quedado.
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($auth === null && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $name => $value) {
                if (strcasecmp($name, 'Authorization') === 0) {
                    $auth = $value;
                }
            }
        }
        if ($auth !== null) {
            $headers['authorization'] = $auth;
        }

        $post = $_POST;
        if (!$post && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            $json = json_decode((string) file_get_contents('php://input'), true);
            $post = is_array($json) ? $json : [];
        }

        return new self($method, self::normalize($path), $_GET, $post, $headers, $_FILES);
    }

    public static function normalize(string $path): string
    {
        $path = '/' . trim($path, '/');
        return preg_replace('#/+#', '/', $path);
    }

    public function rawBody(): string
    {
        return $this->body ??= (string) file_get_contents('php://input');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function ip(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth && preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            return $m[1];
        }
        return null;
    }

    public function isApi(): bool
    {
        return $this->path === '/api' || str_starts_with($this->path, '/api/');
    }

    public function wantsJson(): bool
    {
        return $this->isApi()
            || str_contains($this->header('accept') ?? '', 'application/json')
            || strtolower($this->header('x-requested-with') ?? '') === 'xmlhttprequest';
    }
}
