<div class="card shadow-sm">
    <div class="card-body p-4">
        <h1 class="h5 mb-3">Ingresar</h1>
        <form method="post" action="<?= e(url('/login')) ?>">
            <?= csrf_field() ?>
            <?php if ($fixed): ?>
                <input type="hidden" name="fixed" value="1">
                <input type="hidden" name="empresa" value="<?= e($empresa) ?>">
                <div class="mb-3 small text-body-secondary">
                    Empresa: <strong><?= e($empresa) ?></strong> · <a href="<?= e(url('/login')) ?>">cambiar</a>
                </div>
            <?php else: ?>
                <div class="mb-3">
                    <label class="form-label" for="empresa">Empresa</label>
                    <input class="form-control" id="empresa" name="empresa" value="<?= e($empresa) ?>" required
                           autocapitalize="none" autocomplete="organization" placeholder="identificador de tu empresa"
                           <?= $empresa === '' ? 'autofocus' : '' ?>>
                </div>
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label" for="usuario">Email o DNI</label>
                <input class="form-control" id="usuario" name="usuario" value="<?= e($usuario) ?>" required
                       autocapitalize="none" autocomplete="username" <?= $empresa !== '' && $usuario === '' ? 'autofocus' : '' ?>>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Contraseña</label>
                <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password"
                       <?= $usuario !== '' ? 'autofocus' : '' ?>>
            </div>
            <button class="btn btn-primary w-100">Ingresar</button>
        </form>
    </div>
</div>
<p class="text-center small text-body-secondary mt-3">¿No tenés cuenta? Pedile acceso al administrador de tu empresa.</p>
