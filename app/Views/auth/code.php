<div class="card shadow-sm">
    <div class="card-body p-4">
        <h1 class="h5 mb-2">Verificación en dos pasos</h1>
        <p class="small text-body-secondary">Abrí la app de autenticación del celular e ingresá el código de 6 números.</p>
        <form method="post" action="<?= e(url('/login/codigo')) ?>">
            <?= csrf_field() ?>
            <input class="form-control form-control-lg text-center mb-3" name="code" inputmode="numeric" pattern="[0-9 ]*"
                   maxlength="7" autocomplete="one-time-code" required autofocus style="letter-spacing:.3em">
            <button class="btn btn-primary w-100">Verificar</button>
        </form>
    </div>
</div>
<p class="text-center small mt-3"><a href="<?= e(url('/login')) ?>">Volver</a></p>
