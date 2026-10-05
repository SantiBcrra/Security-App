<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Router con parámetros ({id}), grupos con prefijo y middlewares.
 *
 * Handler: [Controller::class, 'metodo'] o closure; recibe (Request $req, ...$params)
 * y devuelve Response o string (se envuelve como HTML).
 * Middleware: fn(Request $req, callable $next): Response
 */
final class Router
{
    private array $routes = [];
    private string $prefix = '';
    private array $middlewares = [];

    public function get(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add(['GET', 'HEAD'], $path, $handler, $middlewares);
    }

    public function post(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add(['POST'], $path, $handler, $middlewares);
    }

    public function put(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add(['PUT'], $path, $handler, $middlewares);
    }

    public function delete(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->add(['DELETE'], $path, $handler, $middlewares);
    }

    /** Middleware para todas las rutas que se declaren después (llamar antes de definir rutas). */
    public function middleware(callable ...$middlewares): void
    {
        $this->middlewares = array_merge($this->middlewares, $middlewares);
    }

    public function group(string $prefix, array $middlewares, callable $routes): void
    {
        [$prevPrefix, $prevMw] = [$this->prefix, $this->middlewares];
        $this->prefix .= '/' . trim($prefix, '/');
        $this->middlewares = array_merge($this->middlewares, $middlewares);
        $routes($this);
        [$this->prefix, $this->middlewares] = [$prevPrefix, $prevMw];
    }

    private function add(array $methods, string $path, callable|array $handler, array $middlewares): void
    {
        $full = Request::normalize($this->prefix . '/' . trim($path, '/'));
        $regex = '#^' . preg_replace('#\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\}#', '(?P<$1>[^/]+)', preg_quote($full, '#')) . '$#';
        $this->routes[] = [
            'methods'     => $methods,
            'regex'       => $regex,
            'handler'     => $handler,
            'middlewares' => array_merge($this->middlewares, $middlewares),
        ];
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $m)) {
                continue;
            }
            if (!in_array($request->method, $route['methods'], true)) {
                $allowed = array_merge($allowed, $route['methods']);
                continue;
            }
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);

            $core = fn (Request $req): Response => $this->call($route['handler'], $req);
            $pipeline = array_reduce(
                array_reverse($route['middlewares']),
                fn (callable $next, callable $mw) => fn (Request $req): Response => $mw($req, $next),
                $core
            );
            return $pipeline($request);
        }

        if ($allowed) {
            return self::errorResponse($request, 405, 'Método no permitido');
        }
        return self::errorResponse($request, 404, 'Página no encontrada');
    }

    private function call(callable|array $handler, Request $request): Response
    {
        if (is_array($handler) && is_string($handler[0])) {
            $handler = [new $handler[0](), $handler[1]];
        }
        $result = $handler($request, ...array_values($request->params));
        return $result instanceof Response ? $result : Response::html((string) $result);
    }

    public static function errorResponse(Request $request, int $status, string $message): Response
    {
        if ($request->wantsJson()) {
            return Response::jsonError($message, $status);
        }
        $view = $status === 404 ? 'errors/404' : 'errors/generic';
        return Response::html(View::render($view, ['status' => $status, 'message' => $message]), $status);
    }
}
