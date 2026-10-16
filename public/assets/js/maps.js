/*
 * Capas base de los mapas del panel (Leaflet): "Satélite" (Esri World Imagery + nombres de calles y lugares) y "Mapa"
 * (OpenStreetMap), con selector arriba a la derecha. Uso: SAMaps.base(map, 'satellite' | 'map').
 * Los mosaicos se piden a server.arcgisonline.com y *.tile.openstreetmap.org (permitidos en la CSP de Response.php).
 */
window.SAMaps = {
    base(map, initial) {
        const satellite = L.layerGroup([
            L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
                maxZoom: 20, maxNativeZoom: 19, attribution: 'Imágenes © Esri, Maxar, Earthstar Geographics',
            }),
            L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Transportation/MapServer/tile/{z}/{y}/{x}', {
                maxZoom: 20, maxNativeZoom: 19, opacity: 0.8,
            }),
            L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', {
                maxZoom: 20, maxNativeZoom: 19,
            }),
        ]);
        const street = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 20, maxNativeZoom: 19, attribution: '© OpenStreetMap' });
        // Recuerda la última capa elegida en este navegador.
        let pick = initial || 'satellite';
        try { pick = localStorage.getItem('sa-map-layer') || pick; } catch (e) {}
        (pick === 'map' ? street : satellite).addTo(map);
        L.control.layers({ 'Satélite': satellite, 'Mapa': street }, null, { position: 'topright' }).addTo(map);
        map.on('baselayerchange', (e) => { try { localStorage.setItem('sa-map-layer', e.layer === street ? 'map' : 'satellite'); } catch (err) {} });
        return map;
    },

    /**
     * Recorrido planificado: puntos numerados en orden, línea punteada y una flecha en el medio de cada tramo indicando el
     * sentido. points = [{lat, lng, code, name}] en orden. Devuelve un control con remove() que borra el dibujo vigente
     * (las flechas se redibujan solas al hacer zoom).
     */
    route(map, points, opts, handle) {
        opts = Object.assign({ color: '#f2c014', numbers: true, line: true }, opts || {});
        handle = handle || { layer: null, remove() { if (this.layer) map.removeLayer(this.layer); this.layer = null; } };
        const group = L.layerGroup().addTo(map);
        handle.layer = group;
        const ll = points.filter(p => isFinite(p.lat) && isFinite(p.lng)).map(p => [p.lat, p.lng]);
        if (opts.line && ll.length > 1) {
            L.polyline(ll, { color: '#000', weight: 6, opacity: 0.35 }).addTo(group); // borde para que se vea sobre el satélite
            L.polyline(ll, { color: opts.color, weight: 3, dashArray: '8 8' }).addTo(group);
            for (let i = 0; i < ll.length - 1; i++) {
                const a = map.latLngToLayerPoint(ll[i]), b = map.latLngToLayerPoint(ll[i + 1]);
                const angle = Math.atan2(b.y - a.y, b.x - a.x) * 180 / Math.PI;
                const mid = [(ll[i][0] + ll[i + 1][0]) / 2, (ll[i][1] + ll[i + 1][1]) / 2];
                L.marker(mid, { interactive: false, icon: L.divIcon({ className: 'sa-arrow', iconSize: [18, 18], iconAnchor: [9, 9],
                    html: '<div style="transform:rotate(' + angle + 'deg);color:' + opts.color + ';font-size:18px;line-height:18px;text-shadow:0 0 3px #000">➤</div>' }) }).addTo(group);
            }
        }
        if (opts.numbers) {
            points.forEach((p, i) => {
                if (!isFinite(p.lat) || !isFinite(p.lng)) return;
                const el = document.createElement('div');
                el.textContent = (i + 1) + '. ' + p.code + ' · ' + p.name;
                L.marker([p.lat, p.lng], { icon: L.divIcon({ className: 'sa-num', iconSize: [28, 28], iconAnchor: [14, 14],
                    html: '<div style="width:28px;height:28px;border-radius:50%;background:' + (p.color || '#353c4f') + ';color:#fff;border:2px solid ' + opts.color
                        + ';display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;box-shadow:0 1px 4px rgba(0,0,0,.5)">' + (i + 1) + '</div>' }) })
                    .addTo(group).bindTooltip(el);
            });
        }
        // Las flechas dependen del zoom (ángulo en pantalla): se redibujan al hacer zoom. Si el grupo se saca del mapa
        // (por ejemplo, el formulario redibuja con otro orden), se cancela.
        if (opts.line) {
            const redraw = () => { map.removeLayer(group); SAMaps.route(map, points, opts, handle); };
            map.once('zoomend', redraw);
            group.on('remove', () => map.off('zoomend', redraw));
        }
        return handle;
    },

    /** Distancia total aproximada (m) del recorrido en línea recta entre puntos consecutivos. */
    length(points) {
        let m = 0;
        for (let i = 0; i < points.length - 1; i++) m += L.latLng(points[i].lat, points[i].lng).distanceTo(L.latLng(points[i + 1].lat, points[i + 1].lng));
        return m;
    },
};
