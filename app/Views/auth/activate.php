<?php if ($user === null): ?>
    <div class="card shadow-sm"><div class="card-body p-4 text-center">
        <h1 class="h5">El link no es válido o ya venció</h1>
        <p class="text-body-secondary small mb-0">Pedile al administrador de tu empresa que te genere uno nuevo.</p>
    </div></div>
<?php else: ?>
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h5 mb-1">Activá tu cuenta</h1>
            <p class="small text-body-secondary"><?= e($user['name']) ?> · <?= e($tenant['name']) ?></p>
            <form method="post" action="<?= e($action) ?>">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label">Vas a ingresar con</label>
                    <input class="form-control" value="<?= e($user['email'] ?: 'DNI ' . $user['dni']) ?>" disabled>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Elegí tu contraseña (mín. 10 caracteres)</label>
                    <input class="form-control" type="password" id="password" name="password" minlength="10" required autocomplete="new-password" autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password2">Repetila</label>
                    <input class="form-control" type="password" id="password2" name="password_confirmation" minlength="10" required autocomplete="new-password">
                </div>
                <button class="btn btn-primary w-100">Activar cuenta</button>
            </form>
        </div>
    </div>
<?php endif; ?>
