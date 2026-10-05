<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(($title ?? '') !== '' ? $title . ' · ' : '') ?><?= e(config('app.name')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script defer src="<?= e(asset('js/alpine.min.js')) ?>"></script>
</head>
<body class="bg-body-tertiary">
<nav class="navbar bg-dark" data-bs-theme="dark">
    <div class="container">
        <a class="navbar-brand fw-semibold" href="<?= e(url('/')) ?>"><?= e(config('app.name')) ?></a>
    </div>
</nav>
<main class="container py-4">
    <?= $content ?>
</main>
<script src="<?= e(asset('js/bootstrap.bundle.min.js')) ?>"></script>
</body>
</html>
