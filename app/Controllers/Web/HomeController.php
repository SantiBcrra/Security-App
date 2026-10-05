<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\UserAuth;

final class HomeController
{
    /** Entrada del sistema: usuarios de empresas → /login o /panel. (El super-admin entra por /admin.) */
    public function index(Request $request): Response
    {
        return Response::redirect(Session::get(UserAuth::USER_KEY) ? '/panel' : '/login');
    }
}
