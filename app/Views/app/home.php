<?php
use App\Services\UserAuth;

$me = UserAuth::user();
$days = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
$months = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$localNow = fecha(gmdate('Y-m-d H:i:s'), 'w|j|n|H:i');
[$dow, $dom, $mon, $hour] = explode('|', $localNow);
$dateLabel = ucfirst($days[(int) $dow]) . ' ' . $dom . ' de ' . $months[(int) $mon];
$hasPending = $myPermits['active'] || $myPermits['toApprove'] || $myInspections || $myActions;
// Indicadores: [valor, texto, ícono, tono, link]
$stats = [];
if ($safety !== null) {
    $d = $safety['noLost']['days'];
    $stats[] = [$d === null ? '—' : $d, 'días sin accidentes con baja', 'shield', $d === null || $d >= 30 ? 'ok' : 'warn', '/panel/incidentes'];
    if ($safety['openLeaves']) {
        $stats[] = [$safety['openLeaves'], 'persona(s) de baja sin alta', 'alert', 'bad', '/panel/incidentes?bajas=1'];
    }
}
if ($obs !== null) {
    $stats[] = [$obs['pending'], 'observaciones pendientes', 'eye', 'brand', '/panel/observaciones?estado=pendientes'];
    $stats[] = [$obs['imminent'], 'riesgo inminente sin cerrar', 'alert', $obs['imminent'] ? 'bad' : 'muted', '/panel/observaciones?estado=pendientes&inminente=1'];
    $stats[] = [$obs['assigned'], 'asignadas a mí', 'user', $obs['assigned'] ? 'warn' : 'muted', '/panel/observaciones?estado=pendientes&asignadas=1'];
}
if ($myActions) {
    $stats[] = [count($myActions), 'acciones a mi cargo', 'check', 'warn', '/panel/acciones?estado=pendientes&mias=1'];
}
$moduleOf = ['observation' => 'Observaciones', 'action' => 'Acciones', 'inspection' => 'Inspecciones', 'inspection_template' => 'Checklists',
    'incident' => 'Incidentes', 'permit' => 'Permisos', 'ppe' => 'EPP', 'user' => 'Usuarios', 'role' => 'Roles', 'template' => 'Plantillas',
    'settings' => 'Configuración', 'round' => 'Rondas', 'patrol' => 'Rondas', 'import' => 'Importación'];
?>
<div class="page-head">
    <div>
        <h1 class="h3 mb-1"><?= $me ? 'Hola, ' . e(explode(' ', $me['name'])[0]) : 'Inicio' ?></h1>
        <div class="text-body-secondary"><?= e($dateLabel) ?> · <?= e($hour) ?> h · <?= e($tenant['name']) ?></div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if (UserAuth::can('permisos_trabajo', 'crear')): ?>
            <a class="btn btn-outline-secondary" href="<?= e(url('/panel/permisos/nuevo')) ?>"><?= icon('file', 16) ?> Solicitar permiso</a>
        <?php endif; ?>
        <?php if (UserAuth::can('observaciones', 'crear')): ?>
            <a class="btn btn-warning" href="<?= e(url('/panel/observaciones/nueva')) ?>"><?= icon('eye', 16) ?> Reportar observación</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($stats): ?>
    <div class="stat-grid mb-4">
        <?php foreach ($stats as [$value, $label, $ico, $tone, $href]): ?>
            <a class="stat-card tone-<?= e($tone) ?>" href="<?= e(url($href)) ?>">
                <span class="stat-icon"><?= icon($ico, 20) ?></span>
                <span><span class="stat-value"><?= e($value) ?></span><span class="stat-label"><?= e($label) ?></span></span>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="<?= $events !== null ? 'col-xl-8' : 'col-12' ?>">
        <?php if (!$hasPending && $obs === null): ?>
            <div class="card shadow-sm mb-4"><div class="card-body d-flex align-items-center gap-3">
                <span class="stat-icon tone-ok"><?= icon('check', 20) ?></span>
                <div><div class="fw-semibold">Todo al día</div><div class="small text-body-secondary">No tenés permisos, inspecciones ni acciones pendientes.</div></div>
            </div></div>
        <?php endif; ?>

        <?php if ($myPermits['active'] || $myPermits['toApprove']): require BASE_PATH . '/app/Views/panel/permits/_badges.php'; ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center"><span class="d-flex align-items-center gap-2"><?= icon('file') ?><strong>Mis permisos de trabajo</strong></span>
                    <a class="small" href="<?= e(url('/panel/permisos')) ?>">Ver tablero</a></div>
                <div class="list-group list-group-flush">
                    <?php foreach ($myPermits['toApprove'] as $wp): ?>
                        <a class="list-group-item list-group-item-action d-flex justify-content-between gap-2 row-flag-warn" href="<?= e(url('/panel/permisos/' . $wp['uuid'])) ?>">
                            <span><span class="badge text-bg-warning me-1">Para autorizar</span> <strong><?= e(App\Models\WorkPermits::format((int) $wp['number'])) ?></strong> <?= $typeBadges($wp['type_list']) ?>
                                <span class="small text-body-secondary"><?= e($wp['sector_name'] ?? '') ?> · <?= e($wp['requested_by_name'] ?? '') ?></span></span>
                            <span class="small text-nowrap text-body-secondary"><?= e(fecha($wp['valid_from'], 'd/m H:i')) ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php foreach ($myPermits['active'] as $wp): $wpLeft = $minutesLeft($wp); ?>
                        <a class="list-group-item list-group-item-action d-flex justify-content-between gap-2" href="<?= e(url('/panel/permisos/' . $wp['uuid'])) ?>">
                            <span><strong><?= e(App\Models\WorkPermits::format((int) $wp['number'])) ?></strong> <?= $typeBadges($wp['type_list']) ?> <?= $stateBadge($wp['status']) ?>
                                <span class="small text-body-secondary"><?= e($wp['sector_name'] ?? '') ?></span></span>
                            <span class="small text-nowrap <?= $wpLeft < 30 ? 'text-danger fw-semibold' : 'text-body-secondary' ?>">vence <?= e(fecha($wp['ends_at'], 'H:i')) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($myInspections): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center"><span class="d-flex align-items-center gap-2"><?= icon('clipboard') ?><strong>Inspecciones de hoy</strong></span>
                    <a class="small" href="<?= e(url('/panel/inspecciones/programadas?mias=1')) ?>">Ver todas</a></div>
                <div class="list-group list-group-flush">
                    <?php foreach ($myInspections as $s): $late = $s['due_on'] < $today; ?>
                        <a class="list-group-item list-group-item-action d-flex justify-content-between gap-2 <?= $late ? 'row-flag-bad' : '' ?>" href="<?= e(url('/panel/inspecciones/nueva?' . http_build_query(array_filter([
                            'plantilla' => $s['template_uuid'], 'equipo' => $s['equipment_uuid'], 'sector' => $s['equipment_uuid'] ? null : $s['sector_uuid'], 'programada' => $s['uuid']])))) ?>">
                            <span><?= e($s['equipment_code'] ? $s['equipment_code'] . ' · ' . $s['equipment_name'] : $s['sector_name']) ?> <span class="small text-body-secondary">· <?= e($s['template_name']) ?></span></span>
                            <span class="small text-nowrap <?= $late ? 'text-danger fw-semibold' : 'text-body-secondary' ?>"><?= $late ? 'vencida ' . e(date('d/m', strtotime($s['due_on']))) : ($s['due_on'] === $today ? 'hoy' : 'hasta el ' . e(date('d/m', strtotime($s['due_on'])))) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($myActions): require BASE_PATH . '/app/Views/panel/actions/_badges.php'; ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center"><span class="d-flex align-items-center gap-2"><?= icon('check') ?><strong>Mis acciones pendientes</strong></span>
                    <a class="small" href="<?= e(url('/panel/acciones?estado=pendientes&mias=1')) ?>">Ver todas</a></div>
                <div class="list-group list-group-flush">
                    <?php foreach ($myActions as $act): ?>
                        <a class="list-group-item list-group-item-action" href="<?= e(url('/panel/acciones/' . $act['uuid'])) ?>">
                            <div class="d-flex justify-content-between gap-2">
                                <span><span class="small text-body-secondary"><?= e(App\Models\Actions::format((int) $act['number'])) ?></span> <?= e($act['title']) ?></span>
                                <span class="text-nowrap"><?= $priorityBadge($act['priority']) ?></span>
                            </div>
                            <div class="small"><?= $dueLabel($act, $today) ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($obs !== null): require BASE_PATH . '/app/Views/panel/observations/_badges.php'; ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center"><span class="d-flex align-items-center gap-2"><?= icon('eye') ?><strong>Últimas observaciones</strong></span>
                    <a class="small" href="<?= e(url('/panel/observaciones')) ?>">Ver todas</a></div>
                <?php if ($obs['latest']): ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($obs['latest'] as $r): ?>
                            <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2 <?= $r['imminent_risk'] ? 'row-flag-bad' : '' ?>" href="<?= e(url('/panel/observaciones/' . $r['uuid'])) ?>">
                                <span class="min-w-0"><span class="fw-semibold"><?= e(App\Models\Observations::format((int) $r['number'])) ?></span>
                                    <?= $r['imminent_risk'] ? $imminentBadge : '' ?>
                                    <span class="small text-body-secondary"><?= e(mb_strimwidth($r['description'], 0, 90, '…')) ?></span></span>
                                <span class="text-nowrap"><?= $severityBadge($r['severity_name'], $r['severity_color']) ?> <?= $statusBadge($r['status']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="card-body small text-body-secondary">Todavía no hay observaciones. Reportá la primera desde el botón de arriba o desde la app del celular.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($events !== null): ?>
        <div class="col-xl-4">
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <span class="stat-icon tone-brand"><?= icon('building', 20) ?></span>
                        <div class="min-w-0"><div class="fw-semibold text-truncate"><?= e($tenant['legal_name'] ?: $tenant['name']) ?></div>
                            <div class="small text-body-secondary"><?= $tenant['cuit'] ? 'CUIT ' . e(App\Core\Cuit::format($tenant['cuit'])) . ' · ' : '' ?><?php $status = $tenant['status']; require BASE_PATH . '/app/Views/partials/tenant_status.php'; ?></div></div>
                    </div>
                    <dl class="kv mb-0">
                        <dt>Zona horaria</dt><dd><?= e($tenant['timezone']) ?></dd>
                        <?php if ($me): ?><dt>Tu rol</dt><dd><?= e($me['role_name']) ?></dd><?php endif; ?>
                        <?php if ($dbName !== null): ?><dt>Base</dt><dd><code><?= e($dbName) ?></code></dd><?php endif; ?>
                    </dl>
                </div>
            </div>
            <div class="card shadow-sm">
                <div class="card-header"><strong>Actividad reciente</strong></div>
                <ul class="activity">
                    <?php foreach ($events as $ev): $mod = $moduleOf[explode('.', (string) $ev['action'])[0]] ?? 'Sistema'; ?>
                        <li><span class="activity-dot"></span>
                            <div class="min-w-0"><div class="small"><strong><?= e($ev['actor_name'] ?? 'Sistema') ?></strong> · <?= e($mod) ?></div>
                                <div class="small text-body-secondary text-truncate"><code><?= e($ev['action']) ?></code> · <?= e(fecha($ev['created_at'], 'd/m H:i')) ?></div></div></li>
                    <?php endforeach; ?>
                    <?php if (!$events): ?><li class="small text-body-secondary">Sin actividad todavía.</li><?php endif; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>
</div>
