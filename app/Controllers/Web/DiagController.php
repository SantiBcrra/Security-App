<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\Request;
use App\Core\Response;

/** Endpoints mínimos para el diagnóstico de check.php. No exponen datos del servidor. */
final class DiagController
{
    /** Si esto responde, la URL limpia llegó al front controller: mod_rewrite funciona. */
    public function rewrite(Request $request): Response
    {
        return Response::json(['rewrite' => true]);
    }

    /** check.php manda "Authorization: Bearer diag-test"; respondemos solo si llegó igual. */
    public function auth(Request $request): Response
    {
        return Response::json(['authorization' => $request->bearerToken() === 'diag-test']);
    }
}
