<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\Request;
use App\Core\Response;

final class HomeController
{
    /** Por ahora la entrada es el panel super-admin; el login de usuarios llega en la Etapa 3. */
    public function index(Request $request): Response
    {
        return Response::redirect('/admin');
    }
}
