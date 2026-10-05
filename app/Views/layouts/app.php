<?php
/** Layout del área de la empresa (/panel). */
$tenant = App\Core\Tenant::current();
$logoUrl = $tenant ? App\Controllers\Web\App\HomeController::logoUrl($tenant) : null;
$support = App\Services\Impersonation::active();
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
        <span class="navbar-text small text-body-secondary"><?= e(config('app.name')) ?></span>
    </div>
</nav>
<main class="container py-4">
    <?php require BASE_PATH . '/app/Views/partials/flash.php'; ?>
    <?= $content ?>
</main>
<script src="<?= e(asset('js/bootstrap.bundle.min.js')) ?>"></script>
</body>
</html>
