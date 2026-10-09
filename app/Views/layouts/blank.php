<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#353c4f">
    <title><?= e(($title ?? '') !== '' ? $title . ' · ' : '') ?><?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script defer src="<?= e(asset('js/alpine.min.js')) ?>"></script>
</head>
<body class="auth-body">
<main class="auth-wrap">
    <div class="auth-brand">
        <span class="brand-mark"><?= icon('shield', 22) ?></span>
        <span><span class="brand-name d-block"><?= e(config('app.name')) ?></span><span class="brand-sub">Seguridad e Higiene</span></span>
    </div>
    <div class="mx-auto" style="max-width: <?= e($width ?? '720px') ?>">
        <?php require BASE_PATH . '/app/Views/partials/flash.php'; ?>
        <?= $content ?>
        <div class="auth-foot">© <?= e(date('Y')) ?> <?= e(config('app.name')) ?></div>
    </div>
</main>
<script src="<?= e(asset('js/bootstrap.bundle.min.js')) ?>"></script>
</body>
</html>
