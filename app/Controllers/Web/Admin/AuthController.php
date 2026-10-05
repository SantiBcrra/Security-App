<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\AdminAuth;

final class AuthController
{
    public function show(Request $request): Response
    {
        if (AdminAuth::user() !== null) {
            return Response::redirect('/admin');
        }
        [$old] = Flash::pullInput();
        return Response::html(View::render('admin/login', [
            'title' => 'Ingreso super-admin',
            'email' => $old['email'] ?? '',
        ], 'layouts/blank'));
    }

    public function login(Request $request): Response
    {
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');

        $result = ($email === '' || $password === '')
            ? 'invalid'
            : AdminAuth::attempt($email, $password, $request->ip());

        if ($result === 'ok') {
            return Response::redirect('/admin');
        }
        Flash::add('danger', $result === 'locked'
            ? 'Demasiados intentos fallidos. Esperá ' . AdminAuth::WINDOW_MINUTES . ' minutos y volvé a probar.'
            : 'Email o contraseña incorrectos.');
        Flash::withInput(['email' => $email]);
        return Response::redirect('/admin/login');
    }

    public function logout(Request $request): Response
    {
        AdminAuth::logout();
        Flash::add('info', 'Sesión cerrada.');
        return Response::redirect('/admin/login');
    }
}
