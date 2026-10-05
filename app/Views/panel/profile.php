<h1 class="h4 mb-3">Mi perfil</h1>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm mb-3">
            <div class="card-body">
                <div class="fw-semibold"><?= e($user['name']) ?></div>
                <div class="small text-body-secondary"><?= e($user['email'] ?: '') ?><?= $user['dni'] ? ' · DNI ' . e($user['dni']) : '' ?> · <?= e($user['role_name']) ?></div>
            </div>
        </div>

        <form class="card shadow-sm mb-3" method="post" action="<?= e(url('/panel/perfil/notificaciones')) ?>">
            <?= csrf_field() ?>
            <div class="card-header"><strong>Notificaciones</strong></div>
            <div class="card-body">
                <p class="small text-body-secondary">Qué avisos no críticos querés recibir además de la campanita. Los de <strong>riesgo inminente</strong> llegan siempre.</p>
                <?php foreach (['email' => 'Email', 'push' => 'Notificaciones en el celular (app)', 'whatsapp' => 'WhatsApp'] as $k => $l): ?>
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="<?= e($k) ?>" value="1" id="pref_<?= e($k) ?>" <?= ($prefs[$k] ?? true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pref_<?= e($k) ?>"><?= e($l) ?></label></div>
                <?php endforeach; ?>
                <button class="btn btn-primary btn-sm mt-2">Guardar</button>
            </div>
        </form>

        <form class="card shadow-sm mb-3" method="post" action="<?= e(url('/panel/perfil/contrasena')) ?>">
            <?= csrf_field() ?>
            <div class="card-header"><strong>Cambiar contraseña</strong></div>
            <div class="card-body row g-2">
                <div class="col-12"><input class="form-control" type="password" name="current_password" placeholder="Contraseña actual" required autocomplete="current-password"></div>
                <div class="col-md-6"><input class="form-control" type="password" name="password" placeholder="Nueva (mín. 10)" minlength="10" required autocomplete="new-password"></div>
                <div class="col-md-6"><input class="form-control" type="password" name="password_confirmation" placeholder="Repetir nueva" minlength="10" required autocomplete="new-password"></div>
                <div class="col-12"><button class="btn btn-primary btn-sm">Actualizar contraseña</button></div>
            </div>
        </form>
    </div>

    <div class="col-lg-6">
        <div class="card shadow-sm mb-3" id="dos-pasos">
            <div class="card-header"><strong>Verificación en dos pasos</strong></div>
            <div class="card-body">
                <?php if ((int) $user['totp_enabled'] === 1): ?>
                    <p class="small"><span class="badge text-bg-success">Activada</span> Al ingresar se te pide el código de la app de autenticación.</p>
                    <form method="post" action="<?= e(url('/panel/perfil/dos-pasos/desactivar')) ?>" class="d-flex gap-2">
                        <?= csrf_field() ?>
                        <input class="form-control form-control-sm" type="password" name="current_password" placeholder="Tu contraseña" required>
                        <button class="btn btn-outline-danger btn-sm text-nowrap">Desactivar</button>
                    </form>
                <?php elseif ($setupSecret): ?>
                    <ol class="small ps-3">
                        <li>Instalá una app de autenticación (Google Authenticator, Microsoft Authenticator, Authy…).</li>
                        <li>Escaneá este código QR (o cargá la clave a mano).</li>
                        <li>Escribí el código de 6 números que te muestra.</li>
                    </ol>
                    <div class="text-center mb-2" x-data x-init="
                        const qr = qrcode(0, 'M'); qr.addData($el.dataset.uri); qr.make();
                        $el.querySelector('.qr').innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2 });"
                         data-uri="<?= e($setupUri) ?>">
                        <div class="qr d-inline-block bg-white p-1 border rounded"></div>
                        <div class="small font-monospace mt-1 text-break"><?= e(trim(chunk_split($setupSecret, 4, ' '))) ?></div>
                    </div>
                    <form method="post" action="<?= e(url('/panel/perfil/dos-pasos/confirmar')) ?>" class="d-flex gap-2">
                        <?= csrf_field() ?>
                        <input class="form-control" name="code" inputmode="numeric" maxlength="7" placeholder="Código" required autocomplete="one-time-code">
                        <button class="btn btn-primary text-nowrap">Activar</button>
                    </form>
                <?php else: ?>
                    <p class="small text-body-secondary">Suma un código del celular al ingresar. Recomendado para administradores.</p>
                    <form method="post" action="<?= e(url('/panel/perfil/dos-pasos')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-outline-primary btn-sm">Configurar</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header"><strong>Mis dispositivos (app móvil)</strong></div>
            <?php if (!$devices): ?>
                <p class="small text-body-secondary p-3 mb-0">Todavía no ingresaste desde la app.</p>
            <?php else: ?>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($devices as $d): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span><?= e($d['device_name'] ?: 'Dispositivo') ?><br>
                                <span class="text-body-secondary"><?= $d['revoked_at'] ? 'cerrada' : 'último uso ' . e(fecha($d['last_used_at'])) ?></span></span>
                            <?php if (!$d['revoked_at']): ?>
                                <form method="post" action="<?= e(url('/panel/perfil/dispositivos/' . $d['uuid'] . '/revocar')) ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-outline-danger btn-sm">Cerrar sesión</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="<?= e(asset('js/qrcode.min.js')) ?>"></script>
