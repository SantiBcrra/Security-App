<?php
$v = fn (string $k, $d = '') => $old[$k] ?? $d;
$err = fn (string $k) => isset($errors[$k]) ? '<div class="text-danger small">' . e($errors[$k]) . '</div>' : '';
?>
<div class="page-head">
    <div><h1 class="h4 mb-1">App Android</h1>
        <div class="text-body-secondary small">Distribución propia, sin Google Play. Página de descarga para los empleados:
            <a href="<?= e($download) ?>" target="_blank"><?= e($download) ?></a></div></div>
</div>
<div class="row g-4">
    <div class="col-xl-7">
        <div class="card shadow-sm">
            <div class="card-header"><strong>Versiones</strong></div>
            <div class="table-responsive">
                <table class="table table-sm small mb-0 align-middle">
                    <thead><tr><th>Versión</th><th>Código</th><th>Mínima obligatoria</th><th>Tamaño</th><th>Subida</th><th>Estado</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($releases as $r): ?>
                        <tr class="<?= (int) $r['is_active'] ? '' : 'text-body-secondary' ?>">
                            <td><strong><?= e($r['version_name']) ?></strong><?= $r['notes'] ? '<br><span class="text-body-secondary">' . e(mb_strimwidth($r['notes'], 0, 80, '…')) . '</span>' : '' ?></td>
                            <td><?= e($r['version_code']) ?></td>
                            <td><?= (int) $r['min_version_code'] ?: '—' ?></td>
                            <td class="text-nowrap"><?= e(number_format((int) $r['size_bytes'] / 1048576, 1, ',', '.')) ?> MB</td>
                            <td class="text-nowrap"><?= e(fecha($r['created_at'], 'd/m/Y H:i')) ?><br><span class="text-body-secondary"><?= e($r['uploaded_by_name'] ?? '') ?></span></td>
                            <td><?= (int) $r['is_active'] ? '<span class="badge text-bg-success">Publicada</span>' : '<span class="badge text-bg-secondary">Retirada</span>' ?></td>
                            <td class="text-end"><form method="post" action="<?= e(url('/admin/app-android/' . $r['uuid'] . '/estado')) ?>"><?= csrf_field() ?>
                                <button class="btn btn-link btn-sm p-0"><?= (int) $r['is_active'] ? 'Retirar' : 'Publicar' ?></button></form></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$releases): ?><tr><td colspan="7" class="text-body-secondary">Todavía no se subió ninguna versión.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <form class="card shadow-sm" method="post" action="<?= e(url('/admin/app-android')) ?>" id="release-form">
            <?= csrf_field() ?>
            <input type="hidden" name="upload_id" id="upload-id">
            <div class="card-header"><strong>Publicar una versión</strong></div>
            <div class="card-body">
                <label class="form-label small mb-0">APK firmado (release)</label>
                <?php if ($inbox): ?>
                    <select class="form-select form-select-sm mb-1" name="inbox" id="inbox">
                        <option value="">Subir desde esta computadora…</option>
                        <?php foreach ($inbox as $f): ?><option value="<?= e($f['name']) ?>"><?= e($f['name']) ?> · <?= e(number_format($f['size'] / 1048576, 1, ',', '.')) ?> MB (subido por FTP)</option><?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <div id="file-box"><input class="form-control form-control-sm" type="file" id="apk" accept=".apk"></div>
                <?= $err('apk') ?>
                <div class="form-text">Se sube por partes de <?= (int) ($chunk / 1048576) ?> MB: funciona con internet lento y si se corta retoma solo.
                    Alternativa: subilo por FTP o el administrador de archivos del hosting a <code><?= e($inboxDir) ?></code> y elegilo acá.</div>
                <div class="progress my-2 d-none" id="up-progress" style="height: 18px"><div class="progress-bar" id="up-bar" style="width: 0%">0 %</div></div>
                <div class="small mb-2" id="up-status"></div>
                <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label small mb-0">Nombre de versión</label><input class="form-control form-control-sm" name="version_name" value="<?= e($v('version_name')) ?>" placeholder="se lee del APK"><?= $err('version_name') ?></div>
                    <div class="col-6"><label class="form-label small mb-0">Código (versionCode)</label><input class="form-control form-control-sm" name="version_code" inputmode="numeric" value="<?= e($v('version_code')) ?>" placeholder="se lee del APK"><?= $err('version_code') ?></div>
                </div>
                <div class="mb-2"><label class="form-label small mb-0">Versión mínima obligatoria (código)</label>
                    <input class="form-control form-control-sm" name="min_version_code" inputmode="numeric" value="<?= e($v('min_version_code')) ?>" placeholder="vacío = actualización opcional"><?= $err('min_version_code') ?>
                    <div class="form-text">Las apps con un código menor no dejan seguir hasta actualizar (para cambios incompatibles o de seguridad).</div></div>
                <div class="mb-2"><label class="form-label small mb-0">Novedades</label><textarea class="form-control form-control-sm" name="notes" rows="3"><?= e($v('notes')) ?></textarea></div>
                <div class="form-text mb-2">El nombre y el código de la versión <strong>se leen del APK</strong> (no hace falta escribirlos). Cada versión nueva tiene que tener un código mayor que la anterior,
                    y Android solo acepta la actualización si el APK está firmado con la misma clave.</div>
                <button class="btn btn-primary btn-sm" id="publish">Publicar</button>
            </div>
        </form>
        <script>
        (function () {
            var form = document.getElementById('release-form'), input = document.getElementById('apk'), inbox = document.getElementById('inbox');
            var bar = document.getElementById('up-bar'), box = document.getElementById('up-progress'), status = document.getElementById('up-status'), btn = document.getElementById('publish');
            var base = <?= json_encode(url('/admin/app-android/partes')) ?>, token = form.querySelector('[name=csrf_token]').value;
            if (inbox) inbox.addEventListener('change', function () { document.getElementById('file-box').classList.toggle('d-none', inbox.value !== ''); });
            function say(t, cls) { status.className = 'small mb-2 ' + (cls || ''); status.textContent = t; }
            function show(done, total) { var p = Math.floor(100 * done / total); box.classList.remove('d-none'); bar.style.width = p + '%'; bar.textContent = p + ' %'; }
            function hex(buf) { return Array.prototype.map.call(new Uint8Array(buf), function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
            function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
            function post(url, body, type) {
                return fetch(url, { method: 'POST', credentials: 'same-origin', body: body,
                    headers: { 'Content-Type': type, 'X-CSRF-Token': token, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json().then(function (j) { if (!j.ok) throw new Error(j.error && j.error.message || 'Error del servidor'); return j.data; }); });
            }
            form.addEventListener('submit', async function (ev) {
                if (inbox && inbox.value !== '') return; // APK subido por FTP: se manda el formulario tal cual
                ev.preventDefault();
                var file = input.files[0];
                if (!file) { say('Elegí el archivo .apk.', 'text-danger'); return; }
                btn.disabled = true;
                try {
                    say('Preparando el archivo…');
                    var sha = hex(await crypto.subtle.digest('SHA-256', await file.arrayBuffer()));
                    var up = await post(base, JSON.stringify({ name: file.name, size: file.size, sha256: sha }), 'application/json');
                    var at = up.received, fails = 0;
                    while (at < file.size) {
                        show(at, file.size);
                        say('Subiendo… ' + (at / 1048576).toFixed(1) + ' de ' + (file.size / 1048576).toFixed(1) + ' MB. No cierres esta página.');
                        try {
                            var r = await post(base + '/' + up.id + '?offset=' + at, file.slice(at, Math.min(at + up.chunk, file.size)), 'application/octet-stream');
                            at = r.received; fails = 0;
                        } catch (e) {
                            if (++fails > 30) throw e;
                            say('Se cortó la conexión: reintentando en ' + Math.min(30, fails * 3) + ' s… (' + e.message + ')', 'text-warning');
                            await wait(Math.min(30, fails * 3) * 1000);
                        }
                    }
                    show(1, 1);
                    say('Archivo completo. Publicando…', 'text-success');
                    document.getElementById('upload-id').value = up.id;
                    form.submit();
                } catch (e) {
                    say('No se pudo subir: ' + e.message + '. Volvé a tocar Publicar: retoma desde donde quedó.', 'text-danger');
                    btn.disabled = false;
                }
            });
        })();
        </script>
    </div>
</div>
