<?php
/** @var ?array $route @var array $points @var array $users @var array $selected @var array $assigned @var array $old */
use App\Models\Patrols;
$v = $old ?: [];
$all = array_map(fn ($p) => ['uuid' => $p['uuid'], 'code' => $p['code'], 'name' => $p['name'], 'lat' => (float) $p['lat'], 'lng' => (float) $p['lng']], $points);
$mode = $v['order_mode'] ?? ($route['order_mode'] ?? 'libre');
?>
<h1 class="h4 mb-3"><?= $route ? 'Editar ruta' : 'Nueva ruta de ronda' ?></h1>
<form method="post" action="<?= e(url('/panel/rondas/rutas' . ($route ? '/' . $route['uuid'] : ''))) ?>" x-data="routeForm()" x-init="init()" @submit="if (!order.length) { $event.preventDefault(); alert('Agregá al menos un punto a la ruta.'); }">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card card-body mb-3">
                <label class="form-label">Nombre</label><input class="form-control mb-2" name="name" required maxlength="120" placeholder="Ronda nocturna planta 1" value="<?= e($v['name'] ?? ($route['name'] ?? '')) ?>">
                <label class="form-label">Descripción</label><textarea class="form-control mb-2" name="description" rows="2"><?= e($v['description'] ?? ($route['description'] ?? '')) ?></textarea>
                <label class="form-label">Duración esperada</label>
                <div class="input-group mb-1"><input class="form-control" name="expected_minutes" type="number" min="1" value="<?= e($v['expected_minutes'] ?? ($route['expected_minutes'] ?? '')) ?>"><span class="input-group-text">min</span></div>
                <div class="form-text mb-2">Para avisar si la ronda se atrasa. Recorrido en línea recta: <strong x-text="distance()"></strong>.</div>
                <label class="form-label">Orden de los puntos</label>
                <?php foreach (Patrols::ORDER_MODES as $k => $label): [$title, $help] = explode(': ', $label, 2); ?>
                    <div class="form-check"><input class="form-check-input" type="radio" name="order_mode" value="<?= e($k) ?>" id="om-<?= e($k) ?>" <?= $mode === $k ? 'checked' : '' ?>>
                        <label class="form-check-label" for="om-<?= e($k) ?>"><strong><?= e($title) ?></strong> <span class="small text-body-secondary">— <?= e($help) ?></span></label></div>
                <?php endforeach; ?>
                <div class="form-text">En secuencial y al azar, marcar fuera de orden se registra (no se rechaza) y se ve en el detalle de la ronda y en las métricas.</div>
            </div>

            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between"><strong>Puntos de la ruta, en orden</strong><span class="small text-body-secondary" x-text="order.length + ' punto(s)'"></span></div>
                <ul class="list-group list-group-flush">
                    <template x-for="(uuid, i) in order" :key="uuid">
                        <li class="list-group-item d-flex align-items-center gap-2" draggable="true"
                            @dragstart="drag = i" @dragover.prevent @drop.prevent="drop(i)" :class="drag === i ? 'opacity-50' : ''">
                            <span class="text-body-secondary" style="cursor:grab" title="Arrastrá para cambiar el orden">⠿</span>
                            <span class="badge rounded-pill text-bg-dark" x-text="i + 1"></span>
                            <span class="flex-grow-1"><code x-text="point(uuid).code"></code> · <span x-text="point(uuid).name"></span></span>
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="move(i, -1)" :disabled="i === 0" title="Subir">↑</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" @click="move(i, 1)" :disabled="i === order.length - 1" title="Bajar">↓</button>
                            <button type="button" class="btn btn-sm btn-outline-danger" @click="remove(i)" title="Sacar de la ruta">✕</button>
                            <input type="hidden" name="points[]" :value="uuid">
                        </li>
                    </template>
                    <li class="list-group-item small text-body-secondary" x-show="!order.length">Agregá puntos desde la lista de abajo o tocándolos en el mapa.</li>
                </ul>
            </div>

            <div class="card mb-3">
                <div class="card-header"><strong>Agregar puntos</strong></div>
                <ul class="list-group list-group-flush" style="max-height: 260px; overflow:auto">
                    <template x-for="p in available()" :key="p.uuid">
                        <li class="list-group-item d-flex align-items-center"><span class="flex-grow-1"><code x-text="p.code"></code> · <span x-text="p.name"></span></span>
                            <button type="button" class="btn btn-sm btn-outline-primary" @click="add(p.uuid)">+ Agregar</button></li>
                    </template>
                    <li class="list-group-item small text-body-secondary" x-show="!available().length">Todos los puntos están en la ruta. <a href="<?= e(url('/panel/rondas/puntos/nuevo')) ?>">Crear otro punto</a></li>
                </ul>
            </div>

            <div class="card mb-3">
                <div class="card-header"><strong>Guardias asignados</strong></div>
                <div class="list-group list-group-flush" style="max-height: 240px; overflow:auto">
                    <?php foreach ($users as $u): ?><label class="list-group-item"><input class="form-check-input me-2" type="checkbox" name="users[]" value="<?= e($u['uuid']) ?>" <?= in_array($u['uuid'], $assigned, true) ? 'checked' : '' ?>><?= e($u['name']) ?> <span class="small text-body-secondary">· <?= e($u['role_name']) ?></span></label><?php endforeach; ?>
                </div>
            </div>
            <div class="d-flex gap-2 mb-4">
                <button class="btn btn-primary"><?= $route ? 'Guardar cambios' : 'Crear ruta' ?></button>
                <a class="btn btn-outline-secondary" href="<?= e(url($route ? '/panel/rondas/rutas/' . $route['uuid'] : '/panel/rondas')) ?>">Cancelar</a>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="small text-body-secondary mb-1">El recorrido se dibuja en el orden de la lista. Tocá un punto gris para agregarlo.</div>
            <div id="route-map" style="height: 640px" class="rounded border position-sticky" ></div>
        </div>
    </div>
</form>
<link rel="stylesheet" href="<?= e(asset('vendor/leaflet/leaflet.css')) ?>">
<script src="<?= e(asset('vendor/leaflet/leaflet.js')) ?>"></script><script src="<?= e(asset('js/maps.js')) ?>"></script>
<script>
function routeForm() {
    // El mapa vive fuera de los datos de Alpine (si no, Alpine envuelve los objetos de Leaflet y se rompen).
    let map = null, drawn = null; const markers = {};
    return {
        all: <?= json_encode($all, JSON_UNESCAPED_UNICODE) ?>,
        order: <?= json_encode(array_values(array_filter((array) $selected, fn ($u) => in_array($u, array_column($all, 'uuid'), true)))) ?>,
        drag: null,
        point(uuid) { return this.all.find(p => p.uuid === uuid) || { code: '?', name: '' }; },
        available() { return this.all.filter(p => !this.order.includes(p.uuid)); },
        add(uuid) { if (!this.order.includes(uuid)) { this.order.push(uuid); this.redraw(); } },
        remove(i) { this.order.splice(i, 1); this.redraw(); },
        move(i, d) { const j = i + d; if (j < 0 || j >= this.order.length) return; [this.order[i], this.order[j]] = [this.order[j], this.order[i]]; this.redraw(); },
        drop(i) { if (this.drag === null || this.drag === i) { this.drag = null; return; } const [u] = this.order.splice(this.drag, 1); this.order.splice(i, 0, u); this.drag = null; this.redraw(); },
        distance() { const m = SAMaps.length(this.order.map(u => this.point(u))); return m >= 1000 ? (m / 1000).toFixed(1).replace('.', ',') + ' km' : Math.round(m) + ' m'; },
        init() {
            map = L.map('route-map'); SAMaps.base(map, 'satellite');
            // Puntos que no están en la ruta: grises, tocarlos los agrega al final.
            this.all.forEach(p => {
                const el = document.createElement('div'); el.textContent = p.code + ' · ' + p.name + ' — tocá para agregarlo';
                markers[p.uuid] = L.circleMarker([p.lat, p.lng], { radius: 9, color: '#fff', weight: 2, fillColor: '#6c757d', fillOpacity: .9 })
                    .bindTooltip(el).on('click', () => this.add(p.uuid));
            });
            const all = this.all.map(p => [p.lat, p.lng]);
            if (all.length) map.fitBounds(all, { padding: [40, 40], maxZoom: 18 }); else map.setView([-34.6, -64.2], 4);
            this.redraw();
        },
        redraw() {
            if (!map) return;
            if (drawn) drawn.remove();
            this.all.forEach(p => { const m = markers[p.uuid]; if (this.order.includes(p.uuid)) m.remove(); else m.addTo(map); });
            drawn = SAMaps.route(map, JSON.parse(JSON.stringify(this.order.map(u => this.point(u)))));
        },
    };
}
</script>
