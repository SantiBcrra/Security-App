<?php
declare(strict_types=1);

use App\Controllers\Api\AuthController as ApiAuthController;
use App\Controllers\Api\DevicesController;
use App\Controllers\Api\NotificationsController as ApiNotificationsController;
use App\Controllers\Api\ActionsController as ApiActionsController;
use App\Controllers\Api\IncidentsController as ApiIncidentsController;
use App\Controllers\Api\WorkPermitsController as ApiWorkPermitsController;
use App\Controllers\Api\InspectionsController as ApiInspectionsController;
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
use App\Controllers\Web\Panel\ActionsController;
use App\Controllers\Web\Panel\ImportController;
use App\Controllers\Web\Panel\IncidentsController;
use App\Controllers\Web\Panel\InspectionProgramsController;
use App\Controllers\Web\Panel\InspectionsController;
use App\Controllers\Web\Panel\InspectionTemplatesController;
use App\Controllers\Web\Panel\MasterDataController;
use App\Controllers\Web\Panel\NotificationRulesController;
use App\Controllers\Web\Panel\NotificationsController;
use App\Controllers\Web\Panel\ObservationsController;
use App\Controllers\Web\Panel\SettingsController;
use App\Controllers\Web\Panel\ProfileController;
use App\Controllers\Web\Panel\QrController;
use App\Controllers\Web\Panel\RolesController;
use App\Controllers\Web\Panel\PpeController;
use App\Controllers\Web\Panel\RoundsController;
use App\Controllers\Web\Panel\UsersController;
use App\Controllers\Web\Panel\WorkPermitsController;
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

        // Acciones CAPA (Etapa 10). Sin permiso del módulo, cada uno igual ve y cierra las suyas:
        // el alcance y lo que se puede hacer los decide ActionService.
        $r->get('/acciones', [ActionsController::class, 'index']);
        $r->get('/acciones/nueva', [ActionsController::class, 'create'], [$can('acciones', 'crear')]);
        $r->get('/acciones/tablero', [ActionsController::class, 'board'], [$can('acciones', 'ver')]);
        $r->get('/acciones/exportar', [ActionsController::class, 'export'], [$can('acciones', 'exportar')]);
        $r->get('/acciones/{uuid}/imprimir', [ActionsController::class, 'printable']);
        $r->post('/acciones', [ActionsController::class, 'store'], [$can('acciones', 'crear')]);
        $r->get('/acciones/{uuid}', [ActionsController::class, 'show']);
        $r->post('/acciones/{uuid}/accion/{key}', [ActionsController::class, 'transition']);
        $r->post('/acciones/{uuid}/editar', [ActionsController::class, 'update']);
        $r->post('/acciones/{uuid}/comentario', [ActionsController::class, 'comment']);
        $r->post('/acciones/{uuid}/evidencia', [ActionsController::class, 'evidence']);
        $r->get('/acciones/{uuid}/archivos/{attachment}', [ActionsController::class, 'file']);

        // Permisos de trabajo (Etapa 13). Los pasos (autorizar, iniciar…) validan el permiso fino en WorkPermitService.
        $r->get('/permisos', [WorkPermitsController::class, 'index'], [$can('permisos_trabajo', 'ver')]);
        $r->get('/permisos/historial', [WorkPermitsController::class, 'list'], [$can('permisos_trabajo', 'ver')]);
        $r->get('/permisos/exportar', [WorkPermitsController::class, 'export'], [$can('permisos_trabajo', 'exportar')]);
        $r->get('/permisos/nuevo', [WorkPermitsController::class, 'create'], [$can('permisos_trabajo', 'crear')]);
        $r->post('/permisos', [WorkPermitsController::class, 'store'], [$can('permisos_trabajo', 'crear')]);
        $r->get('/permisos/{uuid}', [WorkPermitsController::class, 'show'], [$can('permisos_trabajo', 'ver')]);
        $r->get('/permisos/{uuid}/imprimir', [WorkPermitsController::class, 'printable'], [$can('permisos_trabajo', 'ver')]);
        $r->get('/permisos/{uuid}/firmas/{sig}', [WorkPermitsController::class, 'signature'], [$can('permisos_trabajo', 'ver')]);
        $r->post('/permisos/{uuid}/{step}', [WorkPermitsController::class, 'step'], [$can('permisos_trabajo', 'ver')]);
        // Adonde lleva el QR del permiso colgado: cualquier usuario de la empresa ve si está vigente
        $r->get('/permisos/{uuid}/verificar', [WorkPermitsController::class, 'verify']);

        // EPP (Etapa 14). Catálogo y matriz: PpeService::canManage() (editar + alcance toda la empresa).
        $r->get('/epp', [PpeController::class, 'index'], [$can('epp', 'ver')]);
        $r->get('/epp/catalogo', [PpeController::class, 'catalog'], [$can('epp', 'ver')]);
        $r->post('/epp/catalogo', [PpeController::class, 'saveItem'], [$can('epp', 'editar')]);
        $r->post('/epp/catalogo/plantilla', [PpeController::class, 'applyTemplate'], [$can('epp', 'editar')]);
        $r->post('/epp/catalogo/{uuid}/estado', [PpeController::class, 'toggleItem'], [$can('epp', 'editar')]);
        $r->get('/epp/matriz', [PpeController::class, 'matrix'], [$can('epp', 'ver')]);
        $r->post('/epp/matriz', [PpeController::class, 'saveCell'], [$can('epp', 'editar')]);
        $r->post('/epp/matriz/copiar', [PpeController::class, 'copyMatrix'], [$can('epp', 'editar')]);
        $r->get('/epp/empleado/{uuid}', [PpeController::class, 'employee'], [$can('epp', 'ver')]);
        $r->post('/epp/empleado/{uuid}/talles', [PpeController::class, 'sizes'], [$can('epp', 'crear')]);
        $r->post('/epp/empleado/{uuid}/extras', [PpeController::class, 'extra'], [$can('epp', 'editar')]);
        $r->get('/epp/empleado/{uuid}/entregar', [PpeController::class, 'deliverForm'], [$can('epp', 'crear')]);
        $r->post('/epp/empleado/{uuid}/entregar', [PpeController::class, 'deliver'], [$can('epp', 'crear')]);
        $r->get('/epp/empleado/{uuid}/constancia', [PpeController::class, 'certificate'], [$can('epp', 'ver')]);
        $r->get('/epp/entregas/{uuid}/firma', [PpeController::class, 'signature'], [$can('epp', 'ver')]);
        $r->post('/epp/entregas/{uuid}/anular', [PpeController::class, 'void'], [$can('epp', 'cerrar')]);

        // Incidentes y accidentes (Etapa 12)
        $r->get('/incidentes', [IncidentsController::class, 'index'], [$can('incidentes', 'ver')]);
        $r->get('/incidentes/nuevo', [IncidentsController::class, 'create'], [$can('incidentes', 'crear')]);
        $r->get('/incidentes/horas', [IncidentsController::class, 'hours'], [$can('incidentes', 'ver')]);
        $r->post('/incidentes/horas', [IncidentsController::class, 'saveHours'], [$can('incidentes', 'editar')]);
        $r->get('/incidentes/exportar', [IncidentsController::class, 'export'], [$can('incidentes', 'exportar')]);
        $r->post('/incidentes', [IncidentsController::class, 'store'], [$can('incidentes', 'crear')]);
        $r->get('/incidentes/{uuid}', [IncidentsController::class, 'show'], [$can('incidentes', 'ver')]);
        $r->get('/incidentes/{uuid}/imprimir', [IncidentsController::class, 'printable'], [$can('incidentes', 'ver')]);
        $r->get('/incidentes/{uuid}/art/{person}', [IncidentsController::class, 'art'], [$can('incidentes', 'datos_salud')]);
        $r->post('/incidentes/{uuid}/accion/{key}', [IncidentsController::class, 'transition'], [$can('incidentes', 'cerrar')]);
        $r->post('/incidentes/{uuid}/correccion', [IncidentsController::class, 'correct'], [$can('incidentes', 'editar')]);
        $r->post('/incidentes/{uuid}/investigacion/{step}', [IncidentsController::class, 'investigation'], [$can('incidentes', 'editar')]);
        $r->post('/incidentes/{uuid}/acciones', [IncidentsController::class, 'createAction'], [$can('incidentes', 'editar')]);
        $r->post('/incidentes/{uuid}/personas', [IncidentsController::class, 'addPerson'], [$can('incidentes', 'editar')]);
        $r->post('/incidentes/{uuid}/personas/{person}', [IncidentsController::class, 'updatePerson'], [$can('incidentes', 'editar')]);
        $r->post('/incidentes/{uuid}/comentario', [IncidentsController::class, 'comment'], [$can('incidentes', 'ver')]);
        $r->post('/incidentes/{uuid}/archivos', [IncidentsController::class, 'files'], [$can('incidentes', 'crear')]);
        $r->get('/incidentes/{uuid}/archivos/{attachment}', [IncidentsController::class, 'file'], [$can('incidentes', 'ver')]);

        // Inspecciones y checklists (Etapa 11). Rutas fijas antes que /inspecciones/{uuid}.
        $r->get('/inspecciones', [InspectionsController::class, 'index'], [$can('inspecciones', 'ver')]);
        $r->get('/inspecciones/nueva', [InspectionsController::class, 'create'], [$can('inspecciones', 'crear')]);
        $r->post('/inspecciones', [InspectionsController::class, 'store'], [$can('inspecciones', 'crear')]);
        $r->get('/inspecciones/programadas', [InspectionsController::class, 'scheduled'], [$can('inspecciones', 'ver')]);
        $r->post('/inspecciones/programadas/{uuid}/omitir', [InspectionsController::class, 'skip'], [$can('inspecciones', 'cerrar')]);
        $r->get('/inspecciones/cumplimiento', [InspectionsController::class, 'compliance'], [$can('inspecciones', 'ver')]);
        $r->get('/inspecciones/exportar', [InspectionsController::class, 'export'], [$can('inspecciones', 'exportar')]);
        $r->get('/inspecciones/programas', [InspectionProgramsController::class, 'index'], [$can('inspecciones', 'editar')]);
        $r->get('/inspecciones/programas/nuevo', [InspectionProgramsController::class, 'create'], [$can('inspecciones', 'editar')]);
        $r->post('/inspecciones/programas', [InspectionProgramsController::class, 'save'], [$can('inspecciones', 'editar')]);
        $r->get('/inspecciones/programas/{uuid}', [InspectionProgramsController::class, 'edit'], [$can('inspecciones', 'editar')]);
        $r->post('/inspecciones/programas/{uuid}/estado', [InspectionProgramsController::class, 'toggle'], [$can('inspecciones', 'editar')]);
        $r->get('/inspecciones/plantillas', [InspectionTemplatesController::class, 'index'], [$can('inspecciones', 'editar')]);
        $r->get('/inspecciones/plantillas/nueva', [InspectionTemplatesController::class, 'create'], [$can('inspecciones', 'editar')]);
        $r->post('/inspecciones/plantillas', [InspectionTemplatesController::class, 'save'], [$can('inspecciones', 'editar')]);
        $r->post('/inspecciones/plantillas/precargadas', [InspectionTemplatesController::class, 'presets'], [$can('inspecciones', 'editar')]);
        $r->get('/inspecciones/plantillas/{uuid}', [InspectionTemplatesController::class, 'edit'], [$can('inspecciones', 'editar')]);
        $r->post('/inspecciones/plantillas/{uuid}/estado', [InspectionTemplatesController::class, 'toggle'], [$can('inspecciones', 'editar')]);
        $r->get('/inspecciones/{uuid}', [InspectionsController::class, 'show'], [$can('inspecciones', 'ver')]);
        $r->get('/inspecciones/{uuid}/imprimir', [InspectionsController::class, 'printable'], [$can('inspecciones', 'ver')]);
        $r->post('/inspecciones/{uuid}/anular', [InspectionsController::class, 'annul'], [$can('inspecciones', 'cerrar')]);
        $r->get('/inspecciones/{uuid}/fotos/{attachment}', [InspectionsController::class, 'photo'], [$can('inspecciones', 'ver')]);
        // Ficha de campo de un equipo (adonde lleva su QR): cualquier usuario de la empresa
        $r->get('/equipo/{uuid}', [InspectionsController::class, 'equipment']);

        $r->get('/rondas', [RoundsController::class, 'index'], [$can('rondas', 'ver')]);
        $r->get('/rondas/rutas/nueva', [RoundsController::class, 'routeForm'], [$can('rondas', 'crear')]);
        $r->get('/rondas/ronda/{uuid}', [RoundsController::class, 'round'], [$can('rondas', 'ver')]);
        $r->post('/rondas/rutas', [RoundsController::class, 'routeStore'], [$can('rondas', 'crear')]);
        $r->get('/rondas/puntos/nuevo', [RoundsController::class, 'pointForm'], [$can('rondas', 'crear')]);
        $r->post('/rondas/puntos', [RoundsController::class, 'pointStore'], [$can('rondas', 'crear')]);
        $r->get('/rondas/puntos/{uuid}/qr', [RoundsController::class, 'qr'], [$can('rondas', 'ver')]);

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
        $r->post('/configuracion/empresa', [SettingsController::class, 'updateCompany'], [$can('configuracion', 'editar')]);
        $r->post('/configuracion/permisos', [SettingsController::class, 'updatePermits'], [$can('configuracion', 'editar')]);

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
            $r->get('/actions/{uuid}', [ApiActionsController::class, 'show']);
            $r->get('/inspections/{uuid}', [ApiInspectionsController::class, 'show']);
            $r->get('/incidents/{uuid}', [ApiIncidentsController::class, 'show']);
            $r->get('/permits/{uuid}', [ApiWorkPermitsController::class, 'show']);
            $r->get('/permits/{uuid}/verify', [ApiWorkPermitsController::class, 'verify']);
            $r->post('/permits/{uuid}/approve', [ApiWorkPermitsController::class, 'approve']);
            $r->post('/permits/{uuid}/reject', [ApiWorkPermitsController::class, 'reject']);
            $r->get('/actions/{uuid}/files/{file}', [ApiActionsController::class, 'file']);
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
    $r->get('/ronda/punto/{uuid}', [QrController::class, 'patrolPoint']);

    // Archivos por link firmado temporal (sin sesión, la firma es el permiso)
    $r->get('/archivos/logo/{uuid}', [TenantsController::class, 'logo']);

    // Usadas por public/check.php para probar mod_rewrite y el header Authorization.
    $r->group('/_diag', [], static function (Router $r): void {
        $r->get('/rewrite', [DiagController::class, 'rewrite']);
        $r->get('/auth', [DiagController::class, 'auth']);
    });
};
