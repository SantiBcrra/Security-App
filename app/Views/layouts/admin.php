<?php
/** Layout del super-admin de la plataforma (/admin): mismo menú lateral que el panel de empresa. */
$admin = App\Services\AdminAuth::user();
$items = [
    ['/admin', 'Tablero', 'chart', null],
    ['/admin/empresas', 'Empresas', 'building', '/admin/empresas'],
    ['/admin/migraciones', 'Base de datos', 'layers', '/admin/migraciones'],
    ['/admin/configuracion', 'Configuración', 'settings', '/admin/configuracion'],
    ['/admin/tareas', 'Tareas programadas', 'clock', '/admin/tareas'],
    ['/admin/app-android', 'App Android', 'route', '/admin/app-android'],
];
$basePath = rtrim((string) parse_url(url('/'), PHP_URL_PATH), '/');
$currentPath = '/' . trim(substr('/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/'), strlen($basePath)), '/');
$isActive = fn (?string $prefix, string $href) => $prefix === null ? $currentPath === $href : ($currentPath === $prefix || str_starts_with($currentPath, $prefix . '/'));
$sectionLabel = null;
foreach ($items as [$href, $label, , $prefix]) {
    if ($isActive($prefix, $href)) {
        $sectionLabel = $label;
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#353c4f">
    <title><?= e(($title ?? '') !== '' ? $title . ' · ' : '') ?>Plataforma · <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script defer src="<?= e(asset('js/alpine.min.js')) ?>"></script>
</head>
<body class="app-body" x-data="{ menu: false }" :class="{ 'sidebar-open': menu }" @keydown.escape.window="menu = false">
<aside class="sidebar" aria-label="Menú de la plataforma">
    <a class="sidebar-brand" href="<?= e(url('/admin')) ?>">
        <span class="brand-mark"><?= icon('shield', 22) ?></span>
        <span class="min-w-0"><span class="brand-name d-block text-truncate"><?= e(config('app.name')) ?></span><span class="brand-sub">Administración de la plataforma</span></span>
    </a>
    <nav class="sidebar-nav">
        <div class="nav-section">Plataforma</div>
        <?php foreach ($items as [$href, $label, $ico, $prefix]): ?>
            <a class="side-link <?= $isActive($prefix, $href) ? 'active' : '' ?>" href="<?= e(url($href)) ?>"><?= icon($ico) ?><span><?= e($label) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <?php if ($admin): ?>
        <div class="sidebar-footer">
            <span class="avatar"><?= e(mb_strtoupper(mb_substr($admin['name'], 0, 1))) ?></span>
            <div class="sidebar-user"><span class="d-block text-white fw-semibold small text-truncate"><?= e($admin['name']) ?></span><small>Super-admin</small></div>
            <form method="post" action="<?= e(url('/admin/logout')) ?>">
                <?= csrf_field() ?>
                <button class="sidebar-icon-btn" title="Salir" aria-label="Salir"><?= icon('logout') ?></button>
            </form>
        </div>
    <?php endif; ?>
</aside>
<div class="sidebar-backdrop" @click="menu = false"></div>
<div class="main-area">
    <header class="topbar">
        <button class="icon-btn menu-toggle" type="button" @click="menu = !menu" aria-label="Abrir menú"><?= icon('menu', 20) ?></button>
        <div class="topbar-crumbs"><span class="d-none d-sm-inline">Plataforma</span><?php if ($sectionLabel): ?><span class="d-none d-sm-inline">/</span><strong><?= e($sectionLabel) ?></strong><?php endif; ?></div>
    </header>
    <main class="content">
        <?php require BASE_PATH . '/app/Views/partials/flash.php'; ?>
        <?= $content ?>
    </main>
</div>
<script src="<?= e(asset('js/bootstrap.bundle.min.js')) ?>"></script>
</body>
</html>
