<?php
declare(strict_types=1);

use App\Controllers\Web\DiagController;
use App\Controllers\Web\HomeController;
use App\Core\Router;

return static function (Router $r): void {
    $r->get('/', [HomeController::class, 'index']);

    // Usadas por public/install/check.php para probar mod_rewrite y el header Authorization.
    $r->group('/_diag', [], static function (Router $r): void {
        $r->get('/rewrite', [DiagController::class, 'rewrite']);
        $r->get('/auth', [DiagController::class, 'auth']);
    });
};
