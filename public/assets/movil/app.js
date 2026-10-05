// App de campo (PWA): reportar en planta con o sin señal. Pantallas con Alpine.js, datos en IndexedDB,
// sincronización con la API v1. Sin compilación: módulos nativos del navegador.
import * as db from './db.js';
import { request, isOnline, blobUrl, OfflineError } from './api.js';
import { syncNow, startAutoSync, pendingCounts, enqueue, retryFailed } from './sync.js';

const BASE = document.querySelector('meta[name="app-base"]').content;
const MAX_PHOTOS = 6;
const PHOTO_SIDE = 1600;

function nowLocalInput() {
    const d = new Date();
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
}

function emptyForm() {
    return {
        category: '', severity: '', risk_type: '', sector: '', sectorQuery: '', equipment: '', description: '', location_text: '',
        occurred_at: nowLocalInput(), imminent: false, anonymous: false, photos: [],
        gps: { lat: null, lng: null, acc: null, status: '' },
    };
}

async function sha256Hex(blob) {
    const hash = await crypto.subtle.digest('SHA-256', await blob.arrayBuffer());
    return [...new Uint8Array(hash)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/** Achica la foto a 1600 px (JPEG) antes de guardarla en el celular: menos datos y subida más rápida. */
async function shrinkPhoto(file) {
    const url = URL.createObjectURL(file);
    try {
        const img = await new Promise((resolve, reject) => {
            const i = new Image();
            i.onload = () => resolve(i);
            i.onerror = reject;
            i.src = url;
        });
        const scale = Math.min(1, PHOTO_SIDE / Math.max(img.naturalWidth, img.naturalHeight));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(img.naturalWidth * scale);
        canvas.height = Math.round(img.naturalHeight * scale);
        canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
        return await new Promise((resolve) => canvas.toBlob((b) => resolve(b || file), 'image/jpeg', 0.85));
    } finally {
        URL.revokeObjectURL(url);
    }
}

function b64ToUint8(base64) {
    const pad = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = atob((base64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('movil', () => ({
        view: 'loading',
        tab: 'reportar',
        detailUuid: null,
        toast: null,
        meta: null,
        sync: { state: 'idle', ops: 0, photos: 0, failed: 0, lastSync: null, message: '' },
        login: { empresa: '', usuario: '', password: '', totp: '', needTotp: false, error: '', busy: false },
        form: emptyForm(),
        saving: false,
        catalogs: { categories: [], severities: [], risks: [] },
        sectors: [],
        equipment: [],
        observations: [],
        failedOps: [],
        notifications: [],
        unread: 0,
        detail: null,
        detailPhotos: [],
        detailComment: '',
        scanner: { open: false, error: '' },
        installPrompt: null,
        pushState: 'unknown',
        simOffline: false,
        online: navigator.onLine,

        async init() {
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register(BASE + '/movil/sw.js', { scope: BASE + '/movil/' }).catch(() => {});
            }
            window.addEventListener('beforeinstallprompt', (e) => { e.preventDefault(); this.installPrompt = e; });
            window.addEventListener('sync-state', (e) => this.onSyncState(e.detail));
            window.addEventListener('session-lost', () => this.sessionLost());
            window.addEventListener('online', () => { this.online = true; });
            window.addEventListener('offline', () => { this.online = false; });
            window.addEventListener('hashchange', () => this.route());
            this.simOffline = await db.getKv('simOffline', false);
            this.login.empresa = (await db.getKv('lastEmpresa', '')) || '';
            if (await db.getKv('accessToken')) {
                await this.enterApp();
            } else {
                this.view = 'login';
            }
        },

        // ── sesión ─────────────────────────────────────────────────────
        async doLogin() {
            this.login.error = '';
            this.login.busy = true;
            try {
                let device = await db.getKv('deviceUuid');
                if (!device) { device = crypto.randomUUID(); await db.setKv('deviceUuid', device); }
                const data = await request('POST', '/auth/login', { auth: false, json: {
                    empresa: this.login.empresa.trim().toLowerCase(), usuario: this.login.usuario.trim(), password: this.login.password,
                    device_uuid: device, device_name: this.deviceName(), totp: this.login.needTotp ? this.login.totp : undefined,
                } });
                // Otra persona u otra empresa en este celular: se empieza con datos limpios.
                const who = this.login.empresa.trim().toLowerCase() + '|' + this.login.usuario.trim().toLowerCase();
                if ((await db.getKv('who')) !== who) {
                    await db.clearAll();
                    await db.setKv('deviceUuid', device);
                    await db.setKv('simOffline', this.simOffline);
                }
                await db.setKv('who', who);
                await db.setKv('accessToken', data.access_token);
                await db.setKv('refreshToken', data.refresh_token);
                await db.setKv('lastEmpresa', this.login.empresa.trim().toLowerCase());
                this.login.password = '';
                this.login.totp = '';
                this.login.needTotp = false;
                await this.enterApp();
            } catch (e) {
                if (e.code === 'totp_required' || e.code === 'totp_invalid') {
                    this.login.needTotp = true;
                }
                this.login.error = e instanceof OfflineError ? 'Para ingresar la primera vez necesitás conexión.' : e.message;
            } finally {
                this.login.busy = false;
            }
        },

        deviceName() {
            const ua = navigator.userAgent;
            const m = ua.match(/\(([^)]+)\)/);
            return ((m ? m[1].split(';').slice(0, 2).join(' ').trim() : 'Navegador') + ' · app de campo').slice(0, 110);
        },

        async enterApp() {
            this.view = 'app';
            startAutoSync();
            await this.reloadLocal();
            this.route();
            syncNow('start').then(() => this.reloadLocal());
            this.refreshPushState();
        },

        async sessionLost() {
            await db.setKv('accessToken', null);
            await db.setKv('refreshToken', null);
            this.view = 'login';
            this.login.error = 'Tu sesión venció o fue cerrada. Ingresá de nuevo (lo pendiente no se pierde).';
        },

        async logout() {
            const counts = await pendingCounts();
            if (counts.ops + counts.photos > 0 && !confirm('Hay ' + (counts.ops + counts.photos) + ' envío(s) pendiente(s) que se van a perder. ¿Salir igual?')) return;
            try { await request('POST', '/auth/logout'); } catch (e) { /* sin red: igual se sale */ }
            const device = await db.getKv('deviceUuid');
            await db.clearAll();
            await db.setKv('deviceUuid', device);
            this.view = 'login';
        },

        // ── navegación ─────────────────────────────────────────────────
        route() {
            const hash = location.hash.replace(/^#\/?/, '');
            const [section, id] = hash.split('/');
            if (section === 'observacion' && id) {
                this.openDetail(id);
                return;
            }
            this.detailUuid = null;
            this.tab = ['reportar', 'reportes', 'avisos', 'ajustes'].includes(section) ? section : 'reportar';
            if (this.tab === 'avisos') this.loadNotifications();
            if (this.tab === 'reportar') this.ensureGps();
            if (this.tab === 'ajustes') this.loadFailed();
        },

        go(tab) { location.hash = '#/' + tab; },

        // ── datos locales ──────────────────────────────────────────────
        async reloadLocal() {
            const items = await db.all('catalog_items');
            const sorted = (list) => list.sort((a, b) => (a.sort - b.sort) || a.name.localeCompare(b.name));
            this.catalogs.categories = sorted(items.filter((i) => i.catalog === 'categoria'));
            this.catalogs.severities = sorted(items.filter((i) => i.catalog === 'severidad'));
            this.catalogs.risks = sorted(items.filter((i) => i.catalog === 'tipo_riesgo'));
            this.sectors = (await db.all('sectors')).sort((a, b) => a.label.localeCompare(b.label));
            this.equipment = (await db.all('equipment')).sort((a, b) => a.code.localeCompare(b.code));
            this.observations = (await db.all('observations')).sort((a, b) => (b.created_at_device || '').localeCompare(a.created_at_device || ''));
            this.meta = await db.getKv('meta');
            Object.assign(this.sync, await pendingCounts(), { lastSync: await db.getKv('lastSync') });
        },

        onSyncState(s) {
            Object.assign(this.sync, s);
            if (s.state === 'ok' || s.state === 'offline' || s.state === 'error') this.reloadLocal();
            if (s.state === 'ok' && this.detailUuid) this.openDetail(this.detailUuid, true);
        },

        catalogName(uuid) {
            const all = [...this.catalogs.categories, ...this.catalogs.severities, ...this.catalogs.risks];
            return all.find((c) => c.uuid === uuid)?.name || '';
        },
        severityColor(uuid) { return this.catalogs.severities.find((c) => c.uuid === uuid)?.color || '#6c757d'; },
        sectorLabel(uuid) { return this.sectors.find((s) => s.uuid === uuid)?.label || ''; },
        get filteredSectors() {
            const q = this.form.sectorQuery.trim().toLowerCase();
            return (q ? this.sectors.filter((s) => s.label.toLowerCase().includes(q)) : this.sectors).slice(0, 40);
        },
        get canCreate() { return !!this.meta?.permisos?.observaciones?.acciones?.includes('crear'); },
        get myReports() { return this.observations.filter((o) => o.mine || o.local); },
        get otherReports() { return this.observations.filter((o) => !o.mine && !o.local); },
        fmtDate(iso) {
            if (!iso) return '';
            const d = new Date(iso);
            return d.toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit' }) + ' ' + d.toLocaleTimeString('es-AR', { hour: '2-digit', minute: '2-digit' });
        },
        code(o) { return o.number ? 'OBS-' + String(o.number).padStart(6, '0') : 'Sin número aún'; },

        // ── reportar ───────────────────────────────────────────────────
        ensureGps() {
            if (this.form.gps.lat !== null || this.form.gps.status === 'buscando') return;
            if (!navigator.geolocation) { this.form.gps.status = 'sin GPS'; return; }
            this.form.gps.status = 'buscando';
            navigator.geolocation.getCurrentPosition((p) => {
                this.form.gps = { lat: p.coords.latitude.toFixed(7), lng: p.coords.longitude.toFixed(7), acc: Math.round(p.coords.accuracy), status: 'ok' };
            }, () => { this.form.gps.status = 'no disponible'; }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 60000 });
        },

        pickSector(s) { this.form.sector = s.uuid; this.form.sectorQuery = s.label; },

        async addPhotos(event) {
            const files = [...event.target.files].slice(0, MAX_PHOTOS - this.form.photos.length);
            event.target.value = '';
            for (const f of files) {
                const blob = await shrinkPhoto(f);
                this.form.photos.push({ blob, url: URL.createObjectURL(blob), name: (f.name || 'foto').replace(/\.\w+$/, '') + '.jpg' });
            }
        },
        removePhoto(i) {
            URL.revokeObjectURL(this.form.photos[i].url);
            this.form.photos.splice(i, 1);
        },

        toggleImminent() {
            this.form.imminent = !this.form.imminent;
            if (this.form.imminent) {
                alert('RIESGO INMINENTE\n\nAvisá YA en persona o por radio al supervisor y frená la tarea.\nEste reporte no reemplaza el aviso inmediato.');
            }
        },

        async openScanner() {
            this.scanner = { open: true, error: '' };
            if (!('BarcodeDetector' in window)) {
                this.scanner.error = 'Este navegador no puede leer QR desde la app: elegí el equipo de la lista o escanealo con la cámara del celular.';
                return;
            }
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
                const video = this.$refs.scanVideo;
                video.srcObject = stream;
                await video.play();
                const detector = new BarcodeDetector({ formats: ['qr_code'] });
                const loop = async () => {
                    if (!this.scanner.open) { stream.getTracks().forEach((t) => t.stop()); return; }
                    try {
                        const codes = await detector.detect(video);
                        const m = codes.map((c) => c.rawValue.match(/\/q\/([0-9a-f-]{36})/i)).find(Boolean);
                        if (m) {
                            const eq = this.equipment.find((e) => e.uuid === m[1].toLowerCase());
                            if (eq) {
                                this.form.equipment = eq.uuid;
                                if (eq.sector_uuid && !this.form.sector) this.pickSector(this.sectors.find((s) => s.uuid === eq.sector_uuid) || { uuid: eq.sector_uuid, label: '' });
                                this.scanner.open = false;
                                stream.getTracks().forEach((t) => t.stop());
                                this.flash('Equipo ' + eq.code + ' seleccionado');
                                return;
                            }
                            this.scanner.error = 'Ese QR no es de un equipo de esta empresa (o falta sincronizar).';
                        }
                    } catch (e) { /* cuadro sin QR */ }
                    setTimeout(loop, 300);
                };
                loop();
            } catch (e) {
                this.scanner.error = 'No se pudo abrir la cámara: ' + e.message;
            }
        },
        closeScanner() { this.scanner.open = false; },

        formErrors() {
            const errs = [];
            if (!this.form.category) errs.push('¿Qué es? (acto, condición…)');
            if (!this.form.severity) errs.push('la severidad');
            if (!this.form.sector) errs.push('el sector');
            if (this.form.description.trim().length < 10) errs.push('qué viste (mínimo 10 letras)');
            return errs;
        },

        async save() {
            const errs = this.formErrors();
            if (errs.length) { alert('Falta: ' + errs.join(', ') + '.'); return; }
            if (this.form.imminent && !confirm('¿Ya avisaste en persona o por radio y se frenó la tarea?\n\nAceptar = enviar el reporte de RIESGO INMINENTE.')) return;
            this.saving = true;
            try {
                const f = this.form;
                const uuid = crypto.randomUUID();
                const createdAt = new Date(f.occurred_at).toISOString();
                const data = {
                    uuid, category: f.category, severity: f.severity, risk_type: f.risk_type || undefined, sector: f.sector,
                    equipment: f.equipment || undefined, description: f.description.trim(), location_text: f.location_text.trim() || undefined,
                    lat: f.gps.lat ?? undefined, lng: f.gps.lng ?? undefined, gps_accuracy: f.gps.acc ?? undefined,
                    imminent: f.imminent ? 1 : 0, anonymous: f.anonymous ? 1 : 0, created_at_device: createdAt,
                };
                await db.put('observations', {
                    uuid, local: true, mine: !f.anonymous, number: null, status: 'pendiente', status_label: 'Pendiente de enviar',
                    category_uuid: f.category, severity_uuid: f.severity, sector_uuid: f.sector, equipment_uuid: f.equipment || null,
                    description: data.description, imminent: f.imminent, anonymous: f.anonymous, created_at_device: createdAt, photos: f.photos.length,
                });
                for (const p of f.photos) {
                    await db.put('uploads', {
                        upload_uuid: crypto.randomUUID(), observation_uuid: uuid, name: p.name, size: p.blob.size,
                        sha256: await sha256Hex(p.blob), blob: p.blob, status: 'pending', received: 0,
                    });
                    URL.revokeObjectURL(p.url);
                }
                await enqueue('observation.create', data);
                this.form = emptyForm();
                await this.reloadLocal();
                this.flash((await isOnline()) ? 'Reporte guardado. Enviando…' : 'Reporte guardado en el celular. Se envía cuando haya señal.');
                this.go('reportes');
                syncNow('save');
            } catch (e) {
                alert('No se pudo guardar: ' + e.message);
            } finally {
                this.saving = false;
            }
        },

        // ── detalle ────────────────────────────────────────────────────
        async openDetail(uuid, silent = false) {
            this.detailUuid = uuid;
            const local = await db.get('observations', uuid);
            if (!silent) { this.detail = { local, remote: null, loading: true }; this.detailPhotos = []; }
            const pendingPhotos = (await db.byIndex('uploads', 'observation_uuid', uuid)).filter((u) => u.status !== 'done' && u.blob);
            const localPhotos = pendingPhotos.map((u) => ({ url: URL.createObjectURL(u.blob), pending: true }));
            let remote = null;
            if (local && !local.local) {
                try {
                    remote = await request('GET', '/observations/' + uuid);
                    const remotePhotos = [];
                    for (const p of remote.photos) {
                        try { remotePhotos.push({ url: await blobUrl(p.url.slice(p.url.indexOf('/api/v1') + 7)), pending: false }); } catch (e) { /* sin foto */ }
                    }
                    this.detailPhotos = [...remotePhotos, ...localPhotos];
                } catch (e) {
                    this.detailPhotos = localPhotos;
                }
            } else {
                this.detailPhotos = localPhotos;
            }
            this.detail = { local, remote, loading: false };
        },

        closeDetail() { history.length > 1 ? history.back() : this.go('reportes'); },

        async transition(action) {
            const labels = { analizar: 'Tomar en análisis', cerrar: 'Cerrar', descartar: 'Descartar', reabrir: 'Reabrir' };
            const comment = prompt(labels[action] + ': comentario' + (action === 'analizar' ? ' (opcional)' : ' (obligatorio)'));
            if (comment === null) return;
            await enqueue('observation.transition', { uuid: this.detailUuid, action, input: { comment } });
            this.flash('Guardado. Se envía al sincronizar.');
            await syncNow('save');
            await this.loadFailed();
            if (this.failedOps.length) alert('El servidor rechazó la acción: ' + this.failedOps[this.failedOps.length - 1].error);
        },

        async addComment() {
            const text = this.detailComment.trim();
            if (text.length < 2) return;
            await enqueue('observation.comment', { uuid: this.detailUuid, comment: text });
            this.detailComment = '';
            this.flash('Comentario guardado. Se envía al sincronizar.');
            syncNow('save');
        },

        // ── avisos ─────────────────────────────────────────────────────
        async loadNotifications() {
            try {
                const data = await request('GET', '/notifications');
                this.notifications = data.items;
                this.unread = data.unread;
                await db.setKv('notifications', data);
            } catch (e) {
                const cached = await db.getKv('notifications');
                if (cached) { this.notifications = cached.items; this.unread = cached.unread; }
            }
        },
        async ack(n) {
            try {
                await request('POST', '/alerts/' + n.alert_uuid + '/ack');
                this.flash('Confirmaste que recibiste la alerta.');
                await this.loadNotifications();
            } catch (e) {
                alert(e instanceof OfflineError ? 'Necesitás conexión para confirmar. Avisá por radio mientras tanto.' : e.message);
            }
        },
        async markAllRead() {
            try { await request('POST', '/notifications/read'); await this.loadNotifications(); } catch (e) { /* sin red */ }
        },

        // ── ajustes ────────────────────────────────────────────────────
        async loadFailed() { this.failedOps = (await db.all('outbox')).filter((o) => o.status === 'failed'); },
        async retryOp(op) { await retryFailed(op.op_id); await this.loadFailed(); syncNow('manual'); },
        async dropOp(op) {
            if (!confirm('¿Descartar este envío? No se va a mandar.')) return;
            await db.del('outbox', op.op_id);
            if (op.type === 'observation.create') await db.del('observations', op.data.uuid);
            await this.loadFailed();
            await this.reloadLocal();
        },
        async toggleSimOffline() {
            this.simOffline = !this.simOffline;
            await db.setKv('simOffline', this.simOffline);
            if (!this.simOffline) syncNow('online');
            else this.onSyncState({ state: 'offline', ...(await pendingCounts()) });
        },
        async syncManual() { await db.setKv('syncRetryAt', 0); await syncNow('manual'); await this.loadFailed(); },

        async refreshPushState() {
            if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) { this.pushState = 'unsupported'; return; }
            if (Notification.permission === 'denied') { this.pushState = 'denied'; return; }
            const reg = await navigator.serviceWorker.getRegistration(BASE + '/movil/');
            const sub = reg ? await reg.pushManager.getSubscription() : null;
            this.pushState = sub ? 'on' : 'off';
        },
        async enablePush() {
            try {
                const perm = await Notification.requestPermission();
                if (perm !== 'granted') { this.pushState = 'denied'; return; }
                const reg = await navigator.serviceWorker.ready;
                const { public_key } = await request('GET', '/push/vapid-key');
                const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToUint8(public_key) });
                await request('POST', '/push/subscribe', { json: { subscription: sub.toJSON() } });
                this.pushState = 'on';
                this.flash('Listo: las alertas te llegan al celular aunque la app esté cerrada.');
            } catch (e) {
                alert('No se pudieron activar las notificaciones: ' + e.message);
            }
        },
        async install() {
            if (!this.installPrompt) return;
            this.installPrompt.prompt();
            await this.installPrompt.userChoice;
            this.installPrompt = null;
        },

        flash(text) {
            this.toast = text;
            clearTimeout(this._toastTimer);
            this._toastTimer = setTimeout(() => { this.toast = null; }, 3500);
        },
    }));
});
