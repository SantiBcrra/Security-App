<?php /** @var array $p alerta de pánico, @var list<array> $tracks recorrido de su ronda */ ?>
<a class="btn btn-link px-0 mb-2" href="<?= e(url('/panel/rondas')) ?>">← Rondas</a>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <h1 class="h4 m-0">Pánico · <?= e($p['user_name']) ?></h1>
    <?= $p['acked_at'] === null ? '<span class="badge text-bg-danger">SIN ATENDER' . ((int) $p['level'] > 0 ? ' · nivel ' . e($p['level']) : '') . '</span>' : '<span class="badge text-bg-success">Atendida</span>' ?>
</div>
<p class="text-body-secondary">
    Activado <?= e(fecha($p['triggered_at_device'], 'd/m/Y H:i:s')) ?> (hora del celular) · recibido <?= e(fecha($p['received_at'], 'H:i:s')) ?>
    · <?= e($p['via'] === 'cola' ? 'llegó por datos con demora (sin señal en el momento)' : 'llegó por datos') ?><?= (int) $p['sms_sent'] ? ' · también se envió SMS' : '' ?>
    <?= $p['user_dni'] ? '· DNI ' . e($p['user_dni']) : '' ?>
    <?= $p['round_uuid'] ? '· <a href="' . e(url('/panel/rondas/ronda/' . $p['round_uuid'])) . '">ronda ' . e($p['route_name'] ?: 'libre') . '</a>' : '' ?>
</p>
<div class="row g-4">
    <div class="col-lg-8">
        <?php if ($p['lat'] !== null): ?>
            <link rel="stylesheet" href="<?= e(asset('vendor/leaflet/leaflet.css')) ?>">
            <div id="panic-map" style="height: 380px" class="rounded border mb-2"></div>
            <div class="small mb-3"><a target="_blank" rel="noopener" href="https://maps.google.com/?q=<?= e($p['lat']) ?>,<?= e($p['lng']) ?>">Abrir en Google Maps</a>
                <?= $p['accuracy_m'] !== null ? ' · precisión ±' . e((int) $p['accuracy_m']) . ' m' : '' ?></div>
            <script src="<?= e(asset('vendor/leaflet/leaflet.js')) ?>"></script>
            <script>
            (function () {
                var at = [<?= (float) $p['lat'] ?>, <?= (float) $p['lng'] ?>];
                var tracks = <?= json_encode(array_map(fn ($t) => [(float) $t['lat'], (float) $t['lng']], $tracks)) ?>;
                var map = L.map('panic-map').setView(at, 17);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
                if (tracks.length) L.polyline(tracks, { color: '#353c4f', weight: 4, opacity: .6 }).addTo(map);
                L.circle(at, { radius: <?= max(10, (int) ($p['accuracy_m'] ?? 10)) ?>, color: '#dc3545', fillOpacity: .15 }).addTo(map);
                L.circleMarker(at, { radius: 10, color: '#dc3545', fillOpacity: 1 }).addTo(map).bindTooltip('Pánico', { permanent: true });
            })();
            </script>
        <?php else: ?>
            <div class="alert alert-warning">El celular no tenía ubicación al activar el pánico. Comunicate con el guardia.</div>
        <?php endif; ?>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header"><strong>Atención</strong></div>
            <div class="card-body">
                <?php if ($p['acked_at'] !== null): ?>
                    <div><strong><?= e($p['acked_name']) ?></strong> · <?= e(fecha($p['acked_at'], 'd/m H:i')) ?></div>
                    <div class="text-body-secondary"><?= e($p['ack_comment']) ?></div>
                <?php else: ?>
                    <p class="small">Comunicate con el guardia o mandá a alguien al lugar. Hasta que se marque como atendida, el aviso se repite a los responsables.</p>
                    <form method="post" action="<?= e(url('/panel/rondas/panico/' . $p['uuid'] . '/atendido')) ?>">
                        <?= csrf_field() ?>
                        <textarea class="form-control mb-2" name="comment" rows="3" required minlength="3" placeholder="Qué se hizo (ej. se llamó al guardia, fue una falsa alarma)"></textarea>
                        <button class="btn btn-danger w-100">Marcar como atendida</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
