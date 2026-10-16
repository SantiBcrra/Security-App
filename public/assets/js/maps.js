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
};
