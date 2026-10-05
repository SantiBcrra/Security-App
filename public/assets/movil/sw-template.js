/*
 * Service worker de la app de campo. Lo sirve PHP en /movil/sw.js agregando arriba BASE, VERSION y
 * SHELL (lista de archivos). Estrategia:
 *  - La API nunca se cachea (los datos offline viven en IndexedDB, los maneja la app).
 *  - La app (HTML + JS + CSS) se guarda al instalar: abre sin señal.
 *  - Recibe las notificaciones push (alertas) aunque la app esté cerrada.
 */
const CACHE = 'secapp-movil-' + VERSION;

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k.startsWith('secapp-movil-') && k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== location.origin || url.pathname.startsWith(BASE + '/api/')) return; // API: siempre a la red

    if (req.mode === 'navigate' && url.pathname.startsWith(BASE + '/movil')) {
        // La app: red primero (para tomar actualizaciones), si no hay señal la copia guardada.
        event.respondWith(
            fetch(req).then((res) => {
                const copy = res.clone();
                caches.open(CACHE).then((c) => c.put(BASE + '/movil/', copy));
                return res;
            }).catch(() => caches.match(BASE + '/movil/'))
        );
        return;
    }
    if (url.pathname.startsWith(BASE + '/assets/')) {
        event.respondWith(caches.match(req, { ignoreSearch: true }).then((hit) => hit || fetch(req)));
    }
});

self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) { data = { title: 'Aviso', body: event.data ? event.data.text() : '' }; }
    const options = {
        body: data.body || '',
        icon: BASE + '/assets/movil/icon-192.png',
        badge: BASE + '/assets/movil/icon-192.png',
        tag: data.tag || undefined,
        renotify: !!data.critical,
        requireInteraction: !!data.critical,
        vibrate: data.critical ? [400, 150, 400, 150, 400] : [200],
        data: { url: data.url || BASE + '/movil/#/avisos' },
    };
    event.waitUntil(self.registration.showNotification(data.title || 'Seguridad', options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = event.notification.data && event.notification.data.url;
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
            for (const c of list) {
                if (c.url.includes('/movil/')) { c.focus(); return c.navigate(target); }
            }
            return self.clients.openWindow(target);
        })
    );
});

self.addEventListener('message', (event) => {
    if (event.data === 'skipWaiting') self.skipWaiting();
});
