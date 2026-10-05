<div class="card shadow-sm mx-auto" style="max-width: 420px">
    <div class="card-body p-4">
        <h1 class="h5 mb-3">Ingreso super-admin</h1>
        <form method="post" action="<?= e(url('/admin/login')) ?>">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="email">Email</label>
                <input class="form-control" type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus autocomplete="username">
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Contraseña</label>
                <input class="form-control" type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <button class="btn btn-primary w-100">Ingresar</button>
        </form>
    </div>
</div>
