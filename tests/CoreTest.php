<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

return [
    'e() escapa HTML y comillas' => function () {
        assert_same('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;', e('<script>alert("x")</script>'));
        assert_same('O&#039;Brien', e("O'Brien"));
    },

    'Config lee con notación de puntos y default' => function () {
        assert_same('Security App', Config::get('app.name'));
        assert_same('x', Config::get('no.existe', 'x'));
    },

    'Request::normalize limpia barras' => function () {
        assert_same('/', Request::normalize(''));
        assert_same('/a/b', Request::normalize('//a///b/'));
    },

    'Router resuelve rutas con parámetros' => function () {
        $r = new Router();
        $r->get('/obs/{id}', fn (Request $req, string $id) => Response::json(['id' => $id]));
        $res = $r->dispatch(new Request('GET', '/obs/abc-123'));
        assert_same(200, $res->status);
        assert_same('abc-123', json_decode($res->body, true)['data']['id']);
    },

    'Router: grupos con prefijo y middlewares en orden' => function () {
        $r = new Router();
        $log = [];
        $mw = function (string $tag) use (&$log) {
            return function (Request $req, callable $next) use ($tag, &$log): Response {
                $log[] = $tag;
                return $next($req);
            };
        };
        $r->group('/api/v1', [$mw('a')], function (Router $r) use ($mw) {
            $r->get('/ping', fn () => Response::json('pong'), [$mw('b')]);
        });
        $res = $r->dispatch(new Request('GET', '/api/v1/ping'));
        assert_same(200, $res->status);
        assert_same(['a', 'b'], $log);
    },

    'Router: un middleware puede cortar la cadena' => function () {
        $r = new Router();
        $deny = fn (Request $req, callable $next) => Response::jsonError('No autorizado', 401);
        $r->get('/api/privado', fn () => Response::json('secreto'), [$deny]);
        $res = $r->dispatch(new Request('GET', '/api/privado'));
        assert_same(401, $res->status);
        assert_true(!str_contains($res->body, 'secreto'), 'no debe ejecutar el handler');
    },

    'Router: 404 y 405 en JSON para la API' => function () {
        $r = new Router();
        $r->post('/api/cosa', fn () => Response::json());
        assert_same(404, $r->dispatch(new Request('GET', '/api/otra'))->status);
        $res = $r->dispatch(new Request('GET', '/api/cosa'));
        assert_same(405, $res->status);
        assert_same(false, json_decode($res->body, true)['ok']);
    },

    'Response::json respeta el formato { ok, data, error }' => function () {
        $ok = json_decode(Response::json(['a' => 1])->body, true);
        assert_same(['ok' => true, 'data' => ['a' => 1], 'error' => null], $ok);
        $err = json_decode(Response::jsonError('Mal', 422, 'validation')->body, true);
        assert_same(false, $err['ok']);
        assert_same('validation', $err['error']['code']);
    },

    'Request lee el token Bearer' => function () {
        $req = new Request('GET', '/api/x', [], [], ['authorization' => 'Bearer abc.def.ghi']);
        assert_same('abc.def.ghi', $req->bearerToken());
        assert_same(null, (new Request('GET', '/'))->bearerToken());
    },

    'fecha() convierte de UTC a la zona de la empresa' => function () {
        assert_same('05/10/2026 09:00', fecha('2026-10-05 12:00:00', 'd/m/Y H:i', 'America/Argentina/Buenos_Aires'));
    },
];
