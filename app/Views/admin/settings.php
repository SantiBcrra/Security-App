<h1 class="h4 mb-3">Configuración de la plataforma</h1>
<div class="row g-3">
    <div class="col-lg-6">
        <form class="card shadow-sm" method="post" action="<?= e(url('/admin/configuracion')) ?>" x-data="{ t: '<?= e($mail['transport']) ?>' }">
            <?= csrf_field() ?><input type="hidden" name="section" value="mail">
            <div class="card-header"><strong>Email</strong></div>
            <div class="card-body row g-2">
                <div class="col-12">
                    <select class="form-select" name="transport" x-model="t">
                        <option value="archivo">Guardar en storage/mail (pruebas en local, no envía)</option>
                        <option value="smtp">Enviar por SMTP</option>
                    </select>
                </div>
                <template x-if="t === 'smtp'"><div class="row g-2 m-0 p-0">
                    <div class="col-8"><label class="form-label small mb-0">Servidor</label><input class="form-control" name="host" value="<?= e($mail['host']) ?>" placeholder="mail.exclusivehosting.net"></div>
                    <div class="col-4"><label class="form-label small mb-0">Puerto</label><input class="form-control" name="port" value="<?= e($mail['port']) ?>"></div>
                    <div class="col-6"><label class="form-label small mb-0">Seguridad</label>
                        <select class="form-select" name="security"><?php foreach (['ssl' => 'SSL (465)', 'tls' => 'STARTTLS (587)', 'none' => 'Ninguna'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $mail['security'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                    <div class="col-6"><label class="form-label small mb-0">Usuario</label><input class="form-control" name="username" value="<?= e($mail['username']) ?>" autocomplete="off"></div>
                    <div class="col-12"><label class="form-label small mb-0">Contraseña</label><input class="form-control" type="password" name="password" autocomplete="new-password" placeholder="<?= $mail['has_password'] ? 'guardada (cifrada) — dejá vacío para no cambiarla' : '' ?>"></div>
                </div></template>
                <div class="col-6"><label class="form-label small mb-0">Remitente (email)</label><input class="form-control" name="from_email" value="<?= e($mail['from_email']) ?>" placeholder="avisos@tudominio.com"></div>
                <div class="col-6"><label class="form-label small mb-0">Remitente (nombre)</label><input class="form-control" name="from_name" value="<?= e($mail['from_name']) ?>"></div>
                <div class="col-12"><button class="btn btn-primary btn-sm">Guardar</button></div>
            </div>
        </form>
        <form class="card shadow-sm mt-3" method="post" action="<?= e(url('/admin/configuracion/prueba-email')) ?>">
            <?= csrf_field() ?>
            <div class="card-body d-flex gap-2"><input class="form-control form-control-sm" type="email" name="to" placeholder="Enviar email de prueba a…" required>
                <button class="btn btn-outline-primary btn-sm text-nowrap">Probar</button></div>
        </form>
    </div>
    <div class="col-lg-6">
        <form class="card shadow-sm mb-3" method="post" action="<?= e(url('/admin/configuracion')) ?>">
            <?= csrf_field() ?><input type="hidden" name="section" value="whatsapp">
            <div class="card-header"><strong>WhatsApp (Meta Cloud API)</strong></div>
            <div class="card-body row g-2">
                <div class="col-12 form-check form-switch ms-2"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="wa_on" <?= $wa['enabled'] ? 'checked' : '' ?>><label class="form-check-label" for="wa_on">Habilitado</label></div>
                <div class="col-6"><label class="form-label small mb-0">Phone number ID</label><input class="form-control" name="phone_id" value="<?= e($wa['phone_id']) ?>"></div>
                <div class="col-6"><label class="form-label small mb-0">Plantilla aprobada</label><input class="form-control" name="template" value="<?= e($wa['template']) ?>"></div>
                <div class="col-6"><label class="form-label small mb-0">Idioma</label><input class="form-control" name="language" value="<?= e($wa['language']) ?>"></div>
                <div class="col-6"><label class="form-label small mb-0">Token</label><input class="form-control" type="password" name="token" autocomplete="new-password" placeholder="<?= $wa['has_token'] ? 'guardado (cifrado)' : '' ?>"></div>
                <div class="col-12 form-text">La plantilla debe tener 2 parámetros en el cuerpo: {{1}} título y {{2}} detalle. Teléfono: el de la ficha de empleado vinculada al usuario.</div>
                <div class="col-12"><button class="btn btn-primary btn-sm">Guardar</button></div>
            </div>
        </form>
        <form class="card shadow-sm mb-3" method="post" action="<?= e(url('/admin/configuracion')) ?>" id="fcm">
            <?= csrf_field() ?><input type="hidden" name="section" value="fcm">
            <div class="card-header d-flex justify-content-between"><strong>Notificaciones de la app Android (Firebase)</strong>
                <?= $push['fcm'] ? '<span class="badge text-bg-success">Configurado · ' . e($push['fcm_project']) . '</span>' : '<span class="badge text-bg-secondary">Sin configurar</span>' ?></div>
            <div class="card-body row g-2">
                <div class="col-12"><label class="form-label small mb-0">google-services.json (contenido completo)</label>
                    <textarea class="form-control font-monospace small" name="google_services" rows="3" placeholder="<?= $push['fcm'] ? 'cargado: pegá uno nuevo solo para reemplazarlo' : '{ &quot;project_info&quot;: … }' ?>"></textarea>
                    <div class="form-text">Firebase → Configuración del proyecto → Tus apps → Android → descargar google-services.json. Tiene que incluir la app <code>ar.com.securityapp.campo</code>
                        <?= $push['fcm'] && !$push['fcm_debug'] ? ' (y para probar, <code>ar.com.securityapp.campo.debug</code>)' : '' ?>.</div></div>
                <div class="col-12"><label class="form-label small mb-0">Cuenta de servicio (JSON, se guarda cifrada)</label>
                    <textarea class="form-control font-monospace small" name="service_account" rows="3" placeholder="<?= $push['fcm'] ? 'guardada (cifrada)' : '{ &quot;type&quot;: &quot;service_account&quot;, … }' ?>"></textarea>
                    <div class="form-text">Firebase → Configuración del proyecto → Cuentas de servicio → Generar nueva clave privada.</div></div>
                <div class="col-12"><button class="btn btn-primary btn-sm">Guardar</button></div>
            </div>
        </form>
        <form class="card shadow-sm" method="post" action="<?= e(url('/admin/configuracion')) ?>">
            <?= csrf_field() ?><input type="hidden" name="section" value="push">
            <div class="card-header"><strong>Push (Expo, en desuso)</strong></div>
            <div class="card-body row g-2">
                <div class="col-12 form-check form-switch ms-2"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="push_on" <?= $push['enabled'] ? 'checked' : '' ?>><label class="form-check-label" for="push_on">Habilitado</label></div>
                <div class="col-12"><label class="form-label small mb-0">Access token de Expo (opcional)</label><input class="form-control" type="password" name="token" autocomplete="new-password" placeholder="<?= $push['has_token'] ? 'guardado (cifrado)' : '' ?>"></div>
                <div class="col-12 form-text">Se usa cuando la app móvil (Etapa 8) registre los celulares.</div>
                <div class="col-12"><button class="btn btn-primary btn-sm">Guardar</button></div>
            </div>
        </form>
    </div>
</div>
