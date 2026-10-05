<?php
/** Layout del área de la empresa (/panel). */
$tenant = App\Core\Tenant::current();
$logoUrl = $tenant ? App\Controllers\Web\App\HomeController::logoUrl($tenant) : null;
$support = App\Services\Impersonation::active();
$me = App\Services\UserAuth::user();
$menu = array_filter([
    ['/panel', 'Inicio', true],
    ['/panel/datos/empleados', 'Datos maestros', App\Services\UserAuth::can('datos_maestros', 'ver')],
    ['/panel/usuarios', 'Usuarios', App\Services\UserAuth::can('usuarios', 'ver')],
    ['/panel/roles', 'Roles', App\Services\UserAuth::can('roles', 'ver')],
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
            <?php if ($me): ?>
                <div class="d-flex align-items-center gap-2">
                    <a class="nav-link small" href="<?= e(url('/panel/perfil')) ?>"><?= e($me['name']) ?> · <span class="text-body-secondary"><?= e($me['role_name']) ?></span></a>
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
</body>
</html>
