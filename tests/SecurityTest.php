<?php
declare(strict_types=1);

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Uuid;
use App\Core\Validator;
use App\Middleware\VerifyCsrf;

return [
    'CSRF: token válido pasa, inválido o vacío no' => function () {
        $_SESSION = [];
        $token = Csrf::token();
        assert_same(64, strlen($token));
        assert_true(Csrf::check($token));
        assert_true(!Csrf::check('otro'));
        assert_true(!Csrf::check(''));
        assert_true(!Csrf::check(null));
        assert_true(!Csrf::check(['array']));
    },

    'CSRF: rotate invalida el token anterior' => function () {
        $_SESSION = [];
        $old = Csrf::token();
        Csrf::rotate();
        assert_true(!Csrf::check($old));
        assert_true(Csrf::check(Csrf::token()));
    },

    'VerifyCsrf bloquea POST sin token y no ejecuta el handler' => function () {
        $_SESSION = [];
        Csrf::token();
        $ran = false;
        $next = function () use (&$ran) { $ran = true; return Response::json('ok'); };
        $res = (new VerifyCsrf())(new Request('POST', '/admin/x', [], [], ['accept' => 'application/json']), $next);
        assert_same(403, $res->status);
        assert_true(!$ran, 'el handler no debe correr');
    },

    'VerifyCsrf deja pasar POST con token (campo o header), GET y la API' => function () {
        $_SESSION = [];
        $token = Csrf::token();
        $next = fn () => Response::json('ok');
        $mw = new VerifyCsrf();
        assert_same(200, $mw(new Request('POST', '/x', [], ['csrf_token' => $token]), $next)->status);
        assert_same(200, $mw(new Request('POST', '/x', [], [], ['x-csrf-token' => $token]), $next)->status);
        assert_same(200, $mw(new Request('GET', '/x'), $next)->status);
        assert_same(200, $mw(new Request('POST', '/api/v1/sync/push'), $next)->status);
    },

    'Validator: reglas y mensajes en español' => function () {
        $errors = Validator::validate(
            ['email' => 'no-es-email', 'pass' => 'corta', 'pass2' => 'otra', 'env' => 'x', 'opcional' => ''],
            ['email' => 'required|email', 'pass' => 'required|min:10', 'pass2' => 'same:pass',
             'env' => 'in:local,production', 'nombre' => 'required', 'opcional' => 'email'],
            ['email' => 'Email']
        );
        assert_same('Email: no es un email válido.', $errors['email']);
        assert_same('pass: mínimo 10 caracteres.', $errors['pass']);
        assert_true(isset($errors['pass2'], $errors['env'], $errors['nombre']));
        assert_true(!isset($errors['opcional']), 'vacío y no obligatorio no valida formato');
        assert_same([], Validator::validate(['a' => 'x@y.com'], ['a' => 'required|email']));
    },

    'Uuid v4 tiene formato válido y no se repite' => function () {
        $a = Uuid::v4();
        assert_true(Uuid::isValid($a), $a);
        assert_same('4', $a[14]);
        assert_true($a !== Uuid::v4());
    },
];
