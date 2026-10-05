<?php
$badge = ['ok' => 'success', 'pendiente' => 'secondary', 'error' => 'danger'][$db['status']] ?? 'secondary';
?>
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="badge text-bg-success fs-6">OK</span>
                    <h1 class="h4 m-0">El sistema está funcionando</h1>
                </div>
                <table class="table table-sm mb-3">
                    <tr><th class="w-50">Versión</th><td><?= e(config('app.version')) ?></td></tr>
                    <tr><th>PHP</th><td><?= e($phpVersion) ?></td></tr>
                    <tr><th>Entorno</th><td><?= e($env) ?><?= $debug ? ' <span class="badge text-bg-warning">debug</span>' : '' ?></td></tr>
                    <tr><th>Hora del servidor (UTC)</th><td><?= e($nowUtc) ?></td></tr>
                    <tr><th>Hora local (<?= e(config('app.timezone')) ?>)</th><td><?= e(fecha($nowUtc, 'd/m/Y H:i:s')) ?></td></tr>
                    <tr>
                        <th>Base maestra</th>
                        <td><span class="badge text-bg-<?= e($badge) ?>"><?= e($db['status']) ?></span> <?= e($db['detail']) ?></td>
                    </tr>
                </table>
                <a class="btn btn-outline-primary btn-sm" href="<?= e(url('install/check.php')) ?>">Diagnóstico del servidor</a>
            </div>
        </div>
    </div>
</div>
