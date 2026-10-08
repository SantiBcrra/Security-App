<?php
declare(strict_types=1);

namespace App\Controllers\Web\App;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\SignedUrl;
use App\Core\Tenant;
use App\Core\View;
use App\Models\TenantAudit;
use App\Models\Observations;
use App\Services\ObservationService;
use App\Services\UserAuth;

/** Inicio del área de la empresa. Acá se montan los módulos desde la Etapa 3. */
final class HomeController
{
    public function index(Request $request): Response
    {
        $tenant = Tenant::current();
        // Datos técnicos y auditoría: solo para quien administra usuarios (o soporte).
        $isManager = UserAuth::can('usuarios', 'ver');
        $obs = null;
        if (UserAuth::can('observaciones', 'ver')) {
            $scope = ObservationService::scope();
            $pending = ['status' => ['abierta', 'en_analisis', 'accion_asignada']];
            $me = UserAuth::user();
            $obs = [
                'pending'   => Observations::count($pending, $scope),
                'imminent'  => Observations::count($pending + ['imminent' => 1], $scope),
                'assigned'  => $me ? Observations::count($pending + ['assigned_user_id' => (int) $me['id']], $scope) : 0,
                'mine'      => $me ? Observations::count(['reporter_user_id' => (int) $me['id']], $scope) : 0,
                'latest'    => Observations::search([], $scope, 6),
            ];
        }
        // "Mis acciones pendientes": las que tengo como responsable (con o sin permiso del módulo).
        try {
            $myActions = UserAuth::user() ? \App\Models\Actions::search(['status' => \App\Models\Actions::OPEN],
                ['responsible' => (int) UserAuth::user()['id']], 8) : [];
        } catch (\PDOException) {
            $myActions = []; // base sin la migración de la Etapa 10 todavía
        }
        // Días sin accidentes con baja y bajas abiertas (Etapa 12).
        $safety = null;
        if (UserAuth::can('incidentes', 'ver')) {
            try {
                $safety = ['noLost' => \App\Models\Incidents::daysWithoutLostTime(\App\Services\IncidentService::today(), Tenant::timezone() ?? 'UTC'),
                    'openLeaves' => \App\Services\IncidentService::canSeeHealth() ? count(\App\Models\Incidents::openLeaves()) : null];
            } catch (\PDOException) {
                $safety = null;
            }
        }
        // "Inspecciones de hoy": programadas a mi cargo para hoy y vencidas (Etapa 11).
        $myInspections = [];
        if (UserAuth::user() && UserAuth::can('inspecciones', 'crear')) {
            try {
                $today = \App\Services\ActionService::today();
                $myInspections = \App\Models\InspectionSchedules::search(['status' => 'pendiente', 'due_from_max' => $today,
                    'mine' => \App\Services\InspectionPlanner::mine()], null, 10);
            } catch (\PDOException) {
                $myInspections = [];
            }
        }
        // "Mis permisos": activos que solicité o autoricé + los que esperan mi autorización (Etapa 13).
        $myPermits = ['active' => [], 'toApprove' => []];
        if (UserAuth::user() && UserAuth::can('permisos_trabajo', 'ver')) {
            try {
                $me = (int) UserAuth::user()['id'];
                $myPermits['active'] = \App\Models\WorkPermits::search(['status' => \App\Models\WorkPermits::ACTIVE], ['own' => $me], 8);
                if (UserAuth::can('permisos_trabajo', 'aprobar')) {
                    $myPermits['toApprove'] = array_values(array_filter(\App\Models\WorkPermits::search(['status' => 'solicitado'],
                        \App\Services\WorkPermitService::scope(), 20), [\App\Services\WorkPermitService::class, 'canApprove']));
                }
            } catch (\PDOException) {
                $myPermits = ['active' => [], 'toApprove' => []];
            }
        }
        return Response::html(View::render('app/home', [
            'myPermits' => $myPermits,
            'title'   => 'Inicio',
            'obs'     => $obs,
            'myActions' => $myActions,
            'myInspections' => $myInspections,
            'safety'  => $safety,
            'today'   => \App\Services\ActionService::today(),
            'tenant'  => $tenant,
            'dbName'  => UserAuth::user() === null ? (string) DB::tenant()->query('SELECT DATABASE()')->fetchColumn() : null,
            'events'  => $isManager ? TenantAudit::latest(DB::tenant(), 10) : null,
        ], 'layouts/app'));
    }

    public static function logoUrl(array $tenant): ?string
    {
        return $tenant['logo_path'] ? SignedUrl::make('/archivos/logo/' . $tenant['uuid'], 600) : null;
    }
}
