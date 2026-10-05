<?php $admin = App\Services\AdminAuth::user(); ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e(($title ?? '') !== '' ? $title . ' · ' : '') ?>Plataforma · <?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script defer src="<?= e(asset('js/alpine.min.js')) ?>"></script>
</head>
<body class="bg-body-tertiary">
<nav class="navbar navbar-expand-md bg-dark" data-bs-theme="dark">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="<?= e(url('/admin')) ?>"><?= e(config('app.name')) ?> <span class="badge text-bg-secondary fw-normal">Plataforma</span></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-label="Menú"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="<?= e(url('/admin')) ?>">Tablero</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= e(url('/admin/empresas')) ?>">Empresas</a></li>
                <li class="nav-item"><a class="nav-link" href="<?= e(url('/admin/migraciones')) ?>">Base de datos</a></li>
            </ul>
            <?php if ($admin): ?>
                <span class="navbar-text me-3 small"><?= e($admin['name']) ?></span>
                <form method="post" action="<?= e(url('/admin/logout')) ?>" class="d-inline">
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-light btn-sm">Salir</button>
                </form>
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
