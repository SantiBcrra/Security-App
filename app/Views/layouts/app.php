<?php
/** Layout del área de la empresa (/panel): menú lateral a la izquierda + barra superior con avisos. */
use App\Services\UserAuth;

$tenant = App\Core\Tenant::current();
$logoUrl = $tenant ? App\Controllers\Web\App\HomeController::logoUrl($tenant) : null;
$support = App\Services\Impersonation::active();
$me = UserAuth::user();
// Sin permiso en "acciones", el menú aparece igual si es responsable de alguna abierta.
$hasOwnActions = function () use ($me): bool {
    try {
        return $me !== null && App\Models\Actions::count(['status' => App\Models\Actions::OPEN], ['responsible' => (int) $me['id']]) > 0;
    } catch (\PDOException) {
        return false; // base sin la migración de la Etapa 10 todavía
    }
};
// [enlace, texto, ícono, visible, prefijo que lo marca activo]
$sections = [
    '' => [
        ['/panel', 'Inicio', 'home', true, null],
    ],
    'Prevención' => [
        ['/panel/observaciones', 'Observaciones', 'eye', UserAuth::can('observaciones', 'ver'), '/panel/observaciones'],
        ['/panel/inspecciones', 'Inspecciones', 'clipboard', UserAuth::can('inspecciones', 'ver'), '/panel/inspecciones'],
        ['/panel/acciones', 'Acciones', 'check', UserAuth::can('acciones', 'ver') || $hasOwnActions(), '/panel/acciones'],
        ['/panel/incidentes', 'Incidentes', 'alert', UserAuth::can('incidentes', 'ver'), '/panel/incidentes'],
    ],
    'Operación' => [
        ['/panel/permisos', 'Permisos de trabajo', 'file', UserAuth::can('permisos_trabajo', 'ver'), '/panel/permisos'],
        ['/panel/epp', 'EPP', 'helmet', UserAuth::can('epp', 'ver'), '/panel/epp'],
        ['/panel/rondas', 'Rondas', 'route', UserAuth::can('rondas', 'ver'), '/panel/rondas'],
    ],
    'Administración' => [
        ['/panel/datos/empleados', 'Datos maestros', 'database', UserAuth::can('datos_maestros', 'ver'), '/panel/datos'],
        ['/panel/usuarios', 'Usuarios', 'users', UserAuth::can('usuarios', 'ver'), '/panel/usuarios'],
        ['/panel/roles', 'Roles y permisos', 'shield', UserAuth::can('roles', 'ver'), '/panel/roles'],
        ['/panel/configuracion', 'Configuración', 'settings', UserAuth::can('configuracion', 'ver'), '/panel/configuracion'],
    ],
];
$basePath = rtrim((string) parse_url(url('/'), PHP_URL_PATH), '/');
$currentPath = '/' . trim(substr('/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/'), strlen($basePath)), '/');
$isActive = fn (?string $prefix, string $href) => $prefix === null ? $currentPath === $href : ($currentPath === $prefix || str_starts_with($currentPath, $prefix . '/'));
$sectionLabel = null;
foreach ($sections as $items) {
    foreach ($items as [$href, $label, , $visible, $prefix]) {
        if ($visible && $isActive($prefix, $href)) {
            $sectionLabel = $label;
        }
    }
}
$initials = $me ? mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($me['name'])) ?: [], 0, 2)))) : '';
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#353c4f">
    <title><?= e(($title ?? '') !== '' ? $title . ' · ' : '') ?><?= e($tenant['name'] ?? '') ?> · <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script defer src="<?= e(asset('js/alpine.min.js')) ?>"></script>
</head>
<body class="app-body" x-data="{ menu: false }" :class="{ 'sidebar-open': menu }" @keydown.escape.window="menu = false">

<aside class="sidebar" aria-label="Menú principal">
    <a class="sidebar-brand" href="<?= e(url('/panel')) ?>">
        <span class="brand-mark"><?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt=""><?php else: ?><?= icon('shield', 22) ?><?php endif; ?></span>
        <span class="min-w-0"><span class="brand-name d-block text-truncate"><?= e($tenant['name'] ?? config('app.name')) ?></span>
            <span class="brand-sub">Seguridad e Higiene</span></span>
    </a>
    <nav class="sidebar-nav">
        <?php foreach ($sections as $group => $items): $items = array_filter($items, fn ($i) => $i[3]); if (!$items) { continue; } ?>
            <?php if ($group !== ''): ?><div class="nav-section"><?= e($group) ?></div><?php endif; ?>
            <?php foreach ($items as [$href, $label, $ico, , $prefix]): ?>
                <a class="side-link <?= $isActive($prefix, $href) ? 'active' : '' ?>" href="<?= e(url($href)) ?>"><?= icon($ico) ?><span><?= e($label) ?></span></a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>
    <?php if ($me): ?>
        <div class="sidebar-footer">
            <span class="avatar"><?= e($initials) ?></span>
            <div class="sidebar-user"><a href="<?= e(url('/panel/perfil')) ?>" title="Mi perfil"><?= e($me['name']) ?></a><small><?= e($me['role_name']) ?></small></div>
            <form method="post" action="<?= e(url('/panel/salir')) ?>">
                <?= csrf_field() ?>
                <button class="sidebar-icon-btn" title="Salir" aria-label="Salir"><?= icon('logout') ?></button>
            </form>
        </div>
    <?php endif; ?>
</aside>
<div class="sidebar-backdrop" @click="menu = false"></div>

<div class="main-area">
    <?php if ($support): ?>
        <div class="support-banner d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>Estás viendo <strong><?= e($tenant['name']) ?></strong> como super-admin · <strong>solo lectura</strong> · todo queda auditado</span>
            <form method="post" action="<?= e(url('/admin/empresas/salir')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn-dark btn-sm">Salir de la empresa</button>
            </form>
        </div>
    <?php endif; ?>
    <header class="topbar">
        <button class="icon-btn menu-toggle" type="button" @click="menu = !menu" aria-label="Abrir menú"><?= icon('menu', 20) ?></button>
        <div class="topbar-crumbs">
            <span class="d-none d-sm-inline text-truncate"><?= e($tenant['name'] ?? '') ?></span>
            <?php if ($sectionLabel): ?><span class="d-none d-sm-inline">/</span><strong><?= e($sectionLabel) ?></strong><?php endif; ?>
        </div>
        <?php if ($me): $unread = App\Models\Notifications::unreadCount((int) $me['id']); ?>
            <div class="topbar-actions">
                <div class="dropdown" x-data="bell(<?= e($unread) ?>, '<?= e(url('/panel/notificaciones/recientes')) ?>')" x-init="start()">
                    <button class="icon-btn" :class="{ 'is-critical': critical }" type="button" data-bs-toggle="dropdown" aria-label="Notificaciones" @click="load()">
                        <?= icon('bell', 19) ?>
                        <span class="dot" x-show="unread > 0" x-text="unread > 99 ? '99+' : unread"><?= $unread ?: '' ?></span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end p-0" style="width: min(380px, 92vw)">
                        <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center"><strong class="small">Notificaciones</strong>
                            <a class="small" href="<?= e(url('/panel/notificaciones')) ?>">Ver todas</a></div>
                        <template x-if="!items.length"><div class="p-3 small text-body-secondary">Sin notificaciones.</div></template>
                        <div style="max-height: 420px; overflow-y: auto">
                            <template x-for="n in items" :key="n.uuid">
                                <a class="dropdown-item small border-bottom py-2" style="white-space: normal" :class="{ 'fw-semibold': !n.read, 'bg-danger-subtle': n.pending_ack }" :href="'<?= e(url('/panel/notificaciones/')) ?>' + n.uuid">
                                    <div class="d-flex justify-content-between gap-2"><span x-text="n.title"></span><span class="text-body-secondary text-nowrap" x-text="n.when"></span></div>
                                    <div class="text-body-secondary fw-normal" x-text="n.body"></div>
                                </a>
                            </template>
                        </div>
                    </div>
                </div>
                <a class="icon-btn d-none d-md-grid" href="<?= e(url('/panel/perfil')) ?>" title="Mi perfil · <?= e($me['role_name']) ?>"><?= icon('user', 19) ?></a>
            </div>
        <?php endif; ?>
    </header>
    <main class="content">
        <?php require BASE_PATH . '/app/Views/partials/flash.php'; ?>
        <?= $content ?>
    </main>
</div>
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
