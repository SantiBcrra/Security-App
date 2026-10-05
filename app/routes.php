<?php
declare(strict_types=1);

use App\Controllers\Api\AuthController as ApiAuthController;
use App\Controllers\Api\DevicesController;
use App\Controllers\Api\NotificationsController as ApiNotificationsController;
use App\Controllers\Api\ObservationsController as ApiObservationsController;
use App\Controllers\Api\SyncController;
use App\Controllers\Api\UploadsController;
use App\Controllers\Web\Admin\AuthController as AdminAuthController;
use App\Controllers\Web\Admin\DashboardController;
use App\Controllers\Web\Admin\MigrationsController;
use App\Controllers\Web\Admin\TenantsController;
use App\Controllers\Web\App\HomeController as PanelHomeController;
use App\Controllers\Web\AuthController;
use App\Controllers\Web\Admin\PlatformSettingsController;
use App\Controllers\Web\CronController;
use App\Controllers\Web\DiagController;
use App\Controllers\Web\HomeController;
use App\Controllers\Web\InstallController;
use App\Controllers\Web\MobileController;
use App\Controllers\Web\Panel\ImportController;
use App\Controllers\Web\Panel\MasterDataController;
use App\Controllers\Web\Panel\NotificationRulesController;
use App\Controllers\Web\Panel\NotificationsController;
use App\Controllers\Web\Panel\ObservationsController;
use App\Controllers\Web\Panel\SettingsController;
use App\Controllers\Web\Panel\ProfileController;
use App\Controllers\Web\Panel\QrController;
use App\Controllers\Web\Panel\RolesController;
use App\Controllers\Web\Panel\UsersController;
use App\Core\Router;
use App\Middleware\ApiRateLimit;
use App\Middleware\ReadOnlyImpersonation;
use App\Middleware\RequireApiUser;
use App\Middleware\RequireInstalled;
use App\Middleware\RequirePermission;
use App\Middleware\RequireSuperAdmin;
use App\Middleware\RequireTenant;
use App\Middleware\VerifyCsrf;

return static function (Router $r): void {
    // Globales: sin instalar todo va a /install; CSRF en todo POST web.
    $r->middleware(new RequireInstalled(), new VerifyCsrf());
    $can = fn (string $module, string $action) => new RequirePermission($module, $action);

    $r->get('/', [HomeController::class, 'index']);

    // Instalador web (se bloquea solo con storage/installed.lock)
    $r->get('/install', [InstallController::class, 'show']);
    $r->post('/install', [InstallController::class, 'install']);
    $r->post('/install/test-db', [InstallController::class, 'testDb']);

    // Login de usuarios de las empresas (empresa + email/DNI + contraseña) y activación de cuentas
    $r->get('/login', [AuthController::class, 'show']);
    $r->post('/login', [AuthController::class, 'login']);
    $r->get('/login/codigo', [AuthController::class, 'showCode']);
    $r->post('/login/codigo', [AuthController::class, 'verifyCode']);
    $r->get('/login/{slug}', [AuthController::class, 'show']);
    $r->get('/activar/{slug}/{token}', [AuthController::class, 'showActivate']);
    $r->post('/activar/{slug}/{token}', [AuthController::class, 'activate']);

    // Panel super-admin de la plataforma
    $r->get('/admin/login', [AdminAuthController::class, 'show']);
    $r->post('/admin/login', [AdminAuthController::class, 'login']);
    $r->group('/admin', [new RequireSuperAdmin()], static function (Router $r): void {
        $r->get('/', [DashboardController::class, 'index']);
        $r->post('/logout', [AdminAuthController::class, 'logout']);
        $r->get('/migraciones', [MigrationsController::class, 'index']);
        $r->post('/migraciones', [MigrationsController::class, 'run']);

        $r->get('/empresas', [TenantsController::class, 'index']);
        $r->get('/empresas/nueva', [TenantsController::class, 'create']);
        $r->post('/empresas', [TenantsController::class, 'store']);
        $r->post('/empresas/probar-base', [TenantsController::class, 'testDb']);
        $r->post('/empresas/salir', [TenantsController::class, 'stopImpersonation']);
        $r->get('/empresas/{uuid}', [TenantsController::class, 'show']);
        $r->post('/empresas/{uuid}', [TenantsController::class, 'update']);
        $r->post('/empresas/{uuid}/logo', [TenantsController::class, 'uploadLogo']);
        $r->post('/empresas/{uuid}/suspender', [TenantsController::class, 'suspend']);
        $r->post('/empresas/{uuid}/activar', [TenantsController::class, 'activate']);
        $r->post('/empresas/{uuid}/entrar', [TenantsController::class, 'impersonate']);
        $r->post('/empresas/{uuid}/administrador', [TenantsController::class, 'createAdmin']);

        $r->get('/configuracion', [PlatformSettingsController::class, 'show']);
        $r->post('/configuracion', [PlatformSettingsController::class, 'save']);
        $r->post('/configuracion/prueba-email', [PlatformSettingsController::class, 'testMail']);
        $r->get('/tareas', [PlatformSettingsController::class, 'tasks']);
        $r->post('/tareas/ejecutar', [PlatformSettingsController::class, 'runNow']);
    });

    // Área de la empresa (/panel; NO puede ser /app: choca con la carpeta app/).
    // RequireTenant conecta DB::tenant() a la base de la empresa de la sesión.
    $r->group('/panel', [new RequireTenant(), new ReadOnlyImpersonation()], static function (Router $r) use ($can): void {
        $r->get('/', [PanelHomeController::class, 'index']);
        $r->post('/salir', [AuthController::class, 'logout']);

        $r->get('/perfil', [ProfileController::class, 'show']);
        $r->post('/perfil/contrasena', [ProfileController::class, 'changePassword']);
        $r->post('/perfil/dos-pasos', [ProfileController::class, 'totpStart']);
        $r->post('/perfil/dos-pasos/confirmar', [ProfileController::class, 'totpConfirm']);
        $r->post('/perfil/dos-pasos/desactivar', [ProfileController::class, 'totpDisable']);
        $r->post('/perfil/dispositivos/{device}/revocar', [ProfileController::class, 'revokeDevice']);

        $r->get('/usuarios', [UsersController::class, 'index'], [$can('usuarios', 'ver')]);
        $r->get('/usuarios/nuevo', [UsersController::class, 'create'], [$can('usuarios', 'crear')]);
        $r->post('/usuarios', [UsersController::class, 'store'], [$can('usuarios', 'crear')]);
        $r->get('/usuarios/{uuid}', [UsersController::class, 'edit'], [$can('usuarios', 'ver')]);
        $r->post('/usuarios/{uuid}', [UsersController::class, 'update'], [$can('usuarios', 'editar')]);
        $r->post('/usuarios/{uuid}/estado', [UsersController::class, 'toggle'], [$can('usuarios', 'editar')]);
        $r->post('/usuarios/{uuid}/invitacion', [UsersController::class, 'invite'], [$can('usuarios', 'editar')]);
        $r->post('/usuarios/{uuid}/dispositivos/{device}/revocar', [UsersController::class, 'revokeDevice'], [$can('usuarios', 'editar')]);

        // Observaciones (actos / condiciones inseguras). Alcance por rol en ObservationService::scope().
        $r->get('/observaciones', [ObservationsController::class, 'index'], [$can('observaciones', 'ver')]);
        $r->get('/observaciones/nueva', [ObservationsController::class, 'create'], [$can('observaciones', 'crear')]);
        $r->post('/observaciones', [ObservationsController::class, 'store'], [$can('observaciones', 'crear')]);
        $r->get('/observaciones/{uuid}', [ObservationsController::class, 'show'], [$can('observaciones', 'ver')]);
        $r->get('/observaciones/{uuid}/imprimir', [ObservationsController::class, 'printable'], [$can('observaciones', 'ver')]);
        $r->get('/observaciones/{uuid}/fotos/{attachment}', [ObservationsController::class, 'photo'], [$can('observaciones', 'ver')]);
        $r->post('/observaciones/{uuid}/fotos', [ObservationsController::class, 'addPhotos'], [$can('observaciones', 'crear')]);
        $r->post('/observaciones/{uuid}/comentario', [ObservationsController::class, 'comment'], [$can('observaciones', 'crear')]);
        $r->post('/observaciones/{uuid}/correccion', [ObservationsController::class, 'correct'], [$can('observaciones', 'editar')]);
        $r->post('/observaciones/{uuid}/accion/{action}', [ObservationsController::class, 'transition'], [$can('observaciones', 'ver')]);

        // Notificaciones (campanita) y "Recibido" de alertas críticas
        $r->get('/notificaciones', [NotificationsController::class, 'index']);
        $r->get('/notificaciones/recientes', [NotificationsController::class, 'recent']);
        $r->post('/notificaciones/leer-todas', [NotificationsController::class, 'readAll']);
        $r->get('/notificaciones/{uuid}', [NotificationsController::class, 'open']);
        $r->post('/alertas/{uuid}/recibido', [NotificationsController::class, 'ack']);
        $r->get('/configuracion/notificaciones', [NotificationRulesController::class, 'index'], [$can('configuracion', 'ver')]);
        $r->post('/configuracion/notificaciones', [NotificationRulesController::class, 'save'], [$can('configuracion', 'editar')]);
        $r->post('/configuracion/notificaciones/ajustes', [NotificationRulesController::class, 'saveSettings'], [$can('configuracion', 'editar')]);
        $r->post('/perfil/notificaciones', [ProfileController::class, 'savePrefs']);

        $r->get('/configuracion', [SettingsController::class, 'show'], [$can('configuracion', 'ver')]);
        $r->post('/configuracion', [SettingsController::class, 'update'], [$can('configuracion', 'editar')]);

        // Datos maestros (plantas, sectores, puestos, empleados, contratistas, equipos, catálogos).
        // Rutas fijas antes que las genéricas /datos/{resource}/...
        $r->post('/datos/plantilla', [MasterDataController::class, 'applyTemplate'], [$can('datos_maestros', 'crear')]);
        $r->get('/datos/equipos/etiquetas', [QrController::class, 'labels'], [$can('datos_maestros', 'ver')]);
        $r->get('/datos/{resource}', [MasterDataController::class, 'index'], [$can('datos_maestros', 'ver')]);
        $r->get('/datos/{resource}/nuevo', [MasterDataController::class, 'create'], [$can('datos_maestros', 'crear')]);
        $r->post('/datos/{resource}', [MasterDataController::class, 'store'], [$can('datos_maestros', 'crear')]);
        $r->get('/datos/{resource}/{uuid}', [MasterDataController::class, 'edit'], [$can('datos_maestros', 'ver')]);
        $r->post('/datos/{resource}/{uuid}', [MasterDataController::class, 'update'], [$can('datos_maestros', 'editar')]);
        $r->post('/datos/{resource}/{uuid}/estado', [MasterDataController::class, 'toggle'], [$can('datos_maestros', 'editar')]);

        // Importación Excel/CSV
        $r->get('/importar', [ImportController::class, 'index'], [$can('datos_maestros', 'crear')]);
        $r->post('/importar', [ImportController::class, 'upload'], [$can('datos_maestros', 'crear')]);
        $r->get('/importar/plantilla/{entity}', [ImportController::class, 'template'], [$can('datos_maestros', 'ver')]);
        $r->get('/importar/{uuid}', [ImportController::class, 'show'], [$can('datos_maestros', 'crear')]);
        $r->post('/importar/{uuid}/mapeo', [ImportController::class, 'saveMapping'], [$can('datos_maestros', 'crear')]);
        $r->post('/importar/{uuid}/lote', [ImportController::class, 'batch'], [$can('datos_maestros', 'crear')]);
        $r->get('/importar/{uuid}/errores', [ImportController::class, 'errors'], [$can('datos_maestros', 'crear')]);

        $r->get('/roles', [RolesController::class, 'index'], [$can('roles', 'ver')]);
        $r->get('/roles/{uuid}', [RolesController::class, 'edit'], [$can('roles', 'ver')]);
        $r->post('/roles/{uuid}', [RolesController::class, 'update'], [$can('roles', 'editar')]);
        $r->post('/roles/{uuid}/copiar', [RolesController::class, 'duplicate'], [$can('roles', 'crear')]);
        $r->post('/roles/{uuid}/borrar', [RolesController::class, 'delete'], [$can('roles', 'editar')]);
    });

    // API v1 (app móvil). Sin cookies ni CSRF: autentica con Bearer JWT.
    $r->group('/api/v1', [], static function (Router $r): void {
        $r->post('/auth/login', [ApiAuthController::class, 'login']);
        $r->post('/auth/refresh', [ApiAuthController::class, 'refresh']);
        $r->group('', [new RequireApiUser(), new ApiRateLimit(300)], static function (Router $r): void {
            $r->post('/auth/logout', [ApiAuthController::class, 'logout']);
            $r->get('/me', [ApiAuthController::class, 'me']);

            // Sincronización offline (Etapa 5)
            $r->get('/sync/pull', [SyncController::class, 'pull']);
            $r->post('/sync/push', [SyncController::class, 'push']);
            $r->post('/uploads', [UploadsController::class, 'init']);
            $r->get('/uploads/{uuid}', [UploadsController::class, 'status']);
            $r->put('/uploads/{uuid}', [UploadsController::class, 'chunk']);
            $r->post('/uploads/{uuid}/complete', [UploadsController::class, 'complete']);

            $r->get('/observations/{uuid}', [ApiObservationsController::class, 'show']);
            $r->get('/observations/{uuid}/photos/{photo}', [ApiObservationsController::class, 'photo']);
            $r->get('/notifications', [ApiNotificationsController::class, 'index']);
            $r->post('/notifications/read', [ApiNotificationsController::class, 'readAll']);
            $r->post('/alerts/{uuid}/ack', [ApiNotificationsController::class, 'ack']);

            $r->get('/push/vapid-key', [DevicesController::class, 'vapidKey']);
            $r->post('/push/subscribe', [DevicesController::class, 'subscribe']);
            $r->post('/push/unsubscribe', [DevicesController::class, 'unsubscribe']);
        });
    });

    // App de campo instalable (PWA): funciona offline y habla con la API v1.
    $r->get('/movil', [MobileController::class, 'shell']);
    $r->get('/movil/sw.js', [MobileController::class, 'serviceWorker']);
    $r->get('/movil/manifest.webmanifest', [MobileController::class, 'manifest']);

    // Tareas programadas por URL (cron del hosting o cron-job.org), protegidas con clave.
    $r->get('/cron/run', [CronController::class, 'run']);

    // QR de equipos: lo que lee la cámara del celular (con sesión abre la ficha; si no, login y vuelve)
    $r->get('/q/{uuid}', [QrController::class, 'resolve']);

    // Archivos por link firmado temporal (sin sesión, la firma es el permiso)
    $r->get('/archivos/logo/{uuid}', [TenantsController::class, 'logo']);

    // Usadas por public/check.php para probar mod_rewrite y el header Authorization.
    $r->group('/_diag', [], static function (Router $r): void {
        $r->get('/rewrite', [DiagController::class, 'rewrite']);
        $r->get('/auth', [DiagController::class, 'auth']);
    });
};
