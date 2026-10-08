<?php
/** Layout del área de la empresa (/panel). */
$tenant = App\Core\Tenant::current();
$logoUrl = $tenant ? App\Controllers\Web\App\HomeController::logoUrl($tenant) : null;
$support = App\Services\Impersonation::active();
$me = App\Services\UserAuth::user();
// Sin permiso en "acciones", el menú aparece igual si es responsable de alguna abierta.
$hasOwnActions = function () use ($me): bool {
    try {
        return $me !== null && App\Models\Actions::count(['status' => App\Models\Actions::OPEN], ['responsible' => (int) $me['id']]) > 0;
    } catch (\PDOException) {
        return false; // base sin la migración de la Etapa 10 todavía
    }
};
$menu = array_filter([
    ['/panel', 'Inicio', true],
    ['/panel/observaciones', 'Observaciones', App\Services\UserAuth::can('observaciones', 'ver')],
    ['/panel/inspecciones', 'Inspecciones', App\Services\UserAuth::can('inspecciones', 'ver')],
    ['/panel/acciones', 'Acciones', App\Services\UserAuth::can('acciones', 'ver') || $hasOwnActions()],
    ['/panel/rondas', 'Rondas', App\Services\UserAuth::can('rondas', 'ver')],
    ['/panel/datos/empleados', 'Datos maestros', App\Services\UserAuth::can('datos_maestros', 'ver')],
    ['/panel/usuarios', 'Usuarios', App\Services\UserAuth::can('usuarios', 'ver')],
    ['/panel/roles', 'Roles', App\Services\UserAuth::can('roles', 'ver')],
    ['/panel/configuracion', 'Configuración', App\Services\UserAuth::can('configuracion', 'ver')],
], fn ($item) => $item[2]);
$currentPath = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e(($title ?? '') !== '' ? $title . ' · ' : '') ?><?= e($tenant['name'] ?? '') ?> · <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script defer src="<?= e(asset('js/alpine.min.js')) ?>"></script>
</head>
<body class="bg-body-tertiary">
<?php if ($support): ?>
    <div class="support-banner py-2">
        <div class="container d-flex justify-content-between align-items-center gap-2">
            <span>Estás viendo <strong><?= e($tenant['name']) ?></strong> como super-admin · <strong>solo lectura</strong> · todo queda auditado</span>
            <form method="post" action="<?= e(url('/admin/empresas/salir')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-dark btn-sm">Salir de la empresa</button>
            </form>
        </div>
    </div>
<?php endif; ?>
<nav class="navbar navbar-expand-md bg-white border-bottom">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= e(url('/panel')) ?>">
            <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="" style="height:32px"><?php endif; ?>
            <span class="fw-semibold"><?= e($tenant['name'] ?? '') ?></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#panelnav" aria-label="Menú"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="panelnav">
            <ul class="navbar-nav me-auto">
                <?php foreach ($menu as [$href, $label]): ?>
                    <?php $active = $href === '/panel' ? str_ends_with($currentPath, '/panel') : str_contains($currentPath, explode('/', trim($href, '/'))[1] ?? '~'); ?>
                    <li class="nav-item"><a class="nav-link <?= $active ? 'active fw-semibold' : '' ?>" href="<?= e(url($href)) ?>"><?= e($label) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($me): $unread = App\Models\Notifications::unreadCount((int) $me['id']); ?>
                <div class="d-flex align-items-center gap-2">
                    <div class="dropdown" x-data="bell(<?= e($unread) ?>, '<?= e(url('/panel/notificaciones/recientes')) ?>')" x-init="start()">
                        <button class="btn btn-sm position-relative" :class="critical ? 'btn-danger' : 'btn-outline-secondary'" type="button" data-bs-toggle="dropdown" aria-label="Notificaciones" @click="load()">
                            <span aria-hidden="true">🔔</span>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-danger" x-show="unread > 0" x-text="unread > 99 ? '99+' : unread"><?= $unread ?: '' ?></span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end p-0 shadow" style="width: min(360px, 92vw)">
                            <template x-if="!items.length"><div class="p-3 small text-body-secondary">Sin notificaciones.</div></template>
                            <template x-for="n in items" :key="n.uuid">
                                <a class="dropdown-item small border-bottom py-2" style="white-space: normal" :class="{ 'fw-semibold': !n.read, 'bg-danger-subtle': n.pending_ack }" :href="'<?= e(url('/panel/notificaciones/')) ?>' + n.uuid">
                                    <div class="d-flex justify-content-between gap-2"><span x-text="n.title"></span><span class="text-body-secondary text-nowrap" x-text="n.when"></span></div>
                                    <div class="text-body-secondary fw-normal" x-text="n.body"></div>
                                </a>
                            </template>
                            <a class="dropdown-item small text-center py-2" href="<?= e(url('/panel/notificaciones')) ?>">Ver todas</a>
                        </div>
                    </div>
                    <a class="nav-link small text-nowrap" href="<?= e(url('/panel/perfil')) ?>" title="<?= e($me['role_name']) ?> · Mi perfil"><?= e($me['name']) ?></a>
                    <form method="post" action="<?= e(url('/panel/salir')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-outline-secondary btn-sm">Salir</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>
</nav>
<main class="container py-4">
    <?php require BASE_PATH . '/app/Views/partials/flash.php'; ?>
    <?= $content ?>
</main>
<script src="<?= e(asset('js/bootstrap.bundle.min.js')) ?>"></script>
<script>
// Campanita: consulta cada 60 s (sin websockets: funciona en cualquier hosting).
function bell(unread, url) {
    return {
        unread, items: [], critical: false,
        start() { this.load(); setInterval(() => { if (!document.hidden) this.load(); }, 60000); },
        async load() {
            try {
                const res = await fetch(url, { headers: { Accept: 'application/json' } });
                const json = await res.json();
                if (!json.ok) return;
                const before = this.unread;
                this.unread = json.data.unread; this.items = json.data.items;
                this.critical = this.items.some(n => n.pending_ack);
                if (this.unread > before && this.critical && 'vibrate' in navigator) navigator.vibrate([300, 100, 300]);
            } catch (e) { /* sin conexión: se reintenta en el próximo ciclo */ }
        },
    };
}
</script>
</body>
</html>
