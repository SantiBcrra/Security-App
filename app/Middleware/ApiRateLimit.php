<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\ApiAuth;
use App\Services\RateLimiter;

/** Límite de pedidos por dispositivo (va después de RequireApiUser). */
final class ApiRateLimit
{
    public function __construct(private readonly int $perMinute = 300)
    {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        $device = ApiAuth::device();
        if ($device !== null && !RateLimiter::hit('dev:' . $device['uuid'], $this->perMinute)) {
            return new Response(json_encode(['ok' => false, 'data' => null, 'error' => ['message' => 'Demasiados pedidos: esperá un minuto.', 'code' => 'rate_limited']]),
                429, ['Content-Type' => 'application/json; charset=utf-8', 'Retry-After' => '60']);
        }
        return $next($request);
    }
}
