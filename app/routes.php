<?php
declare(strict_types=1);

use App\Controllers\Web\Admin\AuthController as AdminAuthController;
use App\Controllers\Web\Admin\DashboardController;
use App\Controllers\Web\Admin\MigrationsController;
use App\Controllers\Web\DiagController;
use App\Controllers\Web\HomeController;
use App\Controllers\Web\InstallController;
use App\Core\Router;
use App\Middleware\RequireInstalled;
use App\Middleware\RequireSuperAdmin;
use App\Middleware\VerifyCsrf;

return static function (Router $r): void {
    // Globales: sin instalar todo va a /install; CSRF en todo POST web.
    $r->middleware(new RequireInstalled(), new VerifyCsrf());

    $r->get('/', [HomeController::class, 'index']);

    // Instalador web (se bloquea solo con storage/installed.lock)
    $r->get('/install', [InstallController::class, 'show']);
    $r->post('/install', [InstallController::class, 'install']);
    $r->post('/install/test-db', [InstallController::class, 'testDb']);

    // Panel super-admin de la plataforma
    $r->get('/admin/login', [AdminAuthController::class, 'show']);
    $r->post('/admin/login', [AdminAuthController::class, 'login']);
    $r->group('/admin', [new RequireSuperAdmin()], static function (Router $r): void {
        $r->get('/', [DashboardController::class, 'index']);
        $r->post('/logout', [AdminAuthController::class, 'logout']);
        $r->get('/migraciones', [MigrationsController::class, 'index']);
        $r->post('/migraciones', [MigrationsController::class, 'run']);
    });

    // Usadas por public/install/check.php para probar mod_rewrite y el header Authorization.
    $r->group('/_diag', [], static function (Router $r): void {
        $r->get('/rewrite', [DiagController::class, 'rewrite']);
        $r->get('/auth', [DiagController::class, 'auth']);
    });
};
