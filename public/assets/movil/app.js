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
        roundPointUuid: null,
        roundPoint: null,
        activeRound: null,
        patrolPoints: [],
        patrolRoutes: [],
        patrolRounds: [],
        patrolScans: [],
        actions: [],
        actionUuid: null,
        action: null,          // { local, remote, loading }
        actionPhotos: [],      // evidencia ya subida (remota) + pendiente en el celular
        actionForm: { text: '', photos: [] },
        actionBusy: false,
        newMode: 'obs',          // pestaña Nuevo: 'obs' (reportar observación) | 'insp' (hacer inspección)
        inspTemplates: [],
        inspSchedule: [],
        inspections: [],
        insp: null,              // checklist en curso
        inspPickEquipment: null, // equipo escaneado con varios checklists posibles
        inspSearch: '',
        inspBusy: false,
        inspDetailUuid: null,
        inspDetail: null,
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
            if (section === 'inspeccion' && id) {
                this.detailUuid = null; this.actionUuid = null;
                this.tab = 'reportes'; this.openInspection(id);
                return;
            }
            if (section === 'programada' && id) { // desde un aviso "Inspección para hoy"
                this.detailUuid = null; this.actionUuid = null; this.inspDetailUuid = null;
                const s = this.inspSchedule.find((x) => x.uuid === id);
                if (s) this.startInspection(s.template_uuid, s.equipment_uuid, s.uuid, s.sector_uuid);
                this.tab = 'reportar'; this.newMode = 'insp';
                return;
            }
            this.inspDetailUuid = null;
            if (section === 'accion' && id) {
                this.detailUuid = null;
                this.openAction(id);
                return;
            }
            this.actionUuid = null;
            if (section === 'ronda' && id === 'punto') {
                this.roundPointUuid = (hash.split('/')[2] || '').toLowerCase() || null;
                this.tab = 'rondas'; this.loadRoundPoint(); return;
            }
            this.detailUuid = null;
            this.roundPointUuid = null;
            this.tab = ['reportar', 'reportes', 'rondas', 'avisos', 'ajustes'].includes(section) ? section : 'reportar';
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
            this.patrolPoints = (await db.all('patrol_points')).sort((a, b) => a.code.localeCompare(b.code));
            this.patrolRoutes = (await db.all('patrol_routes')).sort((a, b) => a.name.localeCompare(b.name));
            this.patrolScans = await db.all('patrol_scans');
            const order = { abierta: 0, en_curso: 0, cerrada: 1, verificada: 2, cancelada: 3 };
            this.inspTemplates = (await db.all('inspection_templates')).sort((a, b) => a.name.localeCompare(b.name));
            this.inspSchedule = (await db.all('inspection_schedule')).sort((a, b) => a.due_on.localeCompare(b.due_on));
            this.inspections = (await db.all('inspections')).sort((a, b) => (b.done_at || '').localeCompare(a.done_at || ''));
            this.actions = (await db.all('actions')).sort((a, b) => (order[a.status] - order[b.status]) || (a.due_on || '').localeCompare(b.due_on || ''));
            this.patrolRounds = (await db.all('patrol_rounds')).sort((a, b) => (b.started_at || '').localeCompare(a.started_at || ''));
            // La ronda en curso sobrevive a cerrar la app: es la mía más reciente que no terminó
            this.activeRound = this.patrolRounds.find((r) => r.mine && r.status === 'en_curso') || null;
            if (this.roundPointUuid) this.loadRoundPoint();
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
        // ── inspecciones (checklists) ───────────────────────────────────
        get canInspect() { return !!this.meta?.permisos?.inspecciones?.acciones?.includes('crear'); },
        /** Programadas a mi cargo ya habilitadas (hoy y vencidas), sin las que ya hice en este celular. */
        get inspToday() {
            const done = new Set(this.inspections.map((i) => i.schedule).filter(Boolean));
            return this.inspSchedule.filter((s) => s.due_from <= this.todayLocal() && !done.has(s.uuid));
        },
        get areaTemplates() { return this.inspTemplates.filter((t) => t.scope !== 'equipo'); },
        get inspEquipmentResults() {
            const q = this.inspSearch.trim().toLowerCase();
            const typed = new Set(this.inspTemplates.filter((t) => t.scope === 'equipo').map((t) => t.type_uuid));
            return this.equipment.filter((e) => typed.has(e.type_uuid) && (!q || (e.code + ' ' + e.name).toLowerCase().includes(q))).slice(0, 30);
        },
        templatesForEquipment(eq) { return eq ? this.inspTemplates.filter((t) => t.scope === 'equipo' && t.type_uuid === eq.type_uuid) : []; },
        templateName(uuid) { return this.inspTemplates.find((t) => t.uuid === uuid)?.name || 'Checklist'; },
        equipmentLabel(uuid) { const e = this.equipment.find((x) => x.uuid === uuid); return e ? e.code + ' · ' + e.name : ''; },
        /** Equipo elegido (escaneado o de la lista): si tiene un solo checklist se abre directo. */
        chooseEquipment(eq) {
            const list = this.templatesForEquipment(eq);
            if (!list.length) { this.flash('No hay checklists para ' + eq.code + '.'); return; }
            const sched = this.inspToday.find((s) => s.equipment_uuid === eq.uuid);
            if (list.length === 1) { this.startInspection(list[0].uuid, eq.uuid, sched?.template_uuid === list[0].uuid ? sched.uuid : null); return; }
            this.inspPickEquipment = eq;
        },
        startInspection(templateUuid, equipmentUuid = null, scheduleUuid = null, sectorUuid = null) {
            const t = this.inspTemplates.find((x) => x.uuid === templateUuid);
            if (!t) { this.flash('Ese checklist todavía no está en el celular: sincronizá.'); return; }
            const answers = {};
            const photos = {};
            for (const sec of t.structure.sections) for (const it of sec.items) { answers[it.key] = { value: '', comment: '' }; photos[it.key] = []; }
            const eq = this.equipment.find((e) => e.uuid === equipmentUuid);
            this.insp = { uuid: crypto.randomUUID(), template: t, equipment: eq || null, schedule: scheduleUuid,
                sector: sectorUuid || eq?.sector_uuid || '', answers, photos, notes: '', startedAt: new Date().toISOString(), showErrors: false };
            this.inspPickEquipment = null;
            this.newMode = 'insp';
            if (location.hash !== '#/reportar') this.go('reportar');
            window.scrollTo(0, 0);
        },
        cancelInspection() {
            if (!confirm('¿Descartar este checklist? Lo cargado se pierde.')) return;
            Object.values(this.insp.photos).flat().forEach((p) => URL.revokeObjectURL(p.url));
            this.insp = null;
        },
        itemFails(it) {
            const v = this.insp.answers[it.key].value;
            if (it.type === 'si_no' || it.type === 'si_no_na') return v !== '' && v !== 'na' && v !== it.ok_when;
            if (it.type === 'numero' && v !== '' && !isNaN(parseFloat(String(v).replace(',', '.')))) {
                const n = parseFloat(String(v).replace(',', '.'));
                return (it.min !== null && n < it.min) || (it.max !== null && n > it.max);
            }
            return false;
        },
        itemError(it) {
            const a = this.insp.answers[it.key];
            if ((it.type === 'si_no' || it.type === 'si_no_na') && !a.value) return 'Falta responder';
            if (it.type === 'numero' && (a.value === '' || isNaN(parseFloat(String(a.value).replace(',', '.'))))) return 'Cargá un número';
            const fails = this.itemFails(it);
            if (fails && !a.comment.trim()) return 'Contá qué pasa';
            if ((it.photo === 'siempre' || (it.photo === 'si_no_cumple' && fails)) && !this.insp.photos[it.key].length) return 'Falta la foto';
            return null;
        },
        get inspPending() {
            if (!this.insp) return 0;
            let n = 0;
            for (const sec of this.insp.template.structure.sections) for (const it of sec.items) if (this.itemError(it)) n++;
            if (this.insp.template.scope !== 'equipo' && !this.insp.sector) n++;
            return n;
        },
        async addInspPhotos(it, event) {
            const files = [...event.target.files].slice(0, 3 - this.insp.photos[it.key].length);
            event.target.value = '';
            for (const f of files) {
                const blob = await shrinkPhoto(f);
                this.insp.photos[it.key].push({ blob, url: URL.createObjectURL(blob), name: 'item.jpg' });
            }
        },
        removeInspPhoto(it, i) { URL.revokeObjectURL(this.insp.photos[it.key][i].url); this.insp.photos[it.key].splice(i, 1); },
        /** Se guarda en el celular: checklist + fotos; se envía cuando hay señal (primero la inspección, después las fotos). */
        async saveInspection() {
            this.insp.showErrors = true;
            if (this.inspPending) { alert('Faltan ' + this.inspPending + ' dato(s): revisá los ítems marcados en rojo.'); return; }
            this.inspBusy = true;
            try {
                const i = this.insp;
                const gps = await this.currentPosition();
                const photoCounts = {};
                for (const [key, list] of Object.entries(i.photos)) {
                    if (!list.length) continue;
                    photoCounts[key] = list.length;
                    for (const p of list) {
                        await db.put('uploads', { upload_uuid: crypto.randomUUID(), inspection_uuid: i.uuid, item_key: key, name: p.name, size: p.blob.size,
                            sha256: await sha256Hex(p.blob), blob: p.blob, status: 'pending', received: 0 });
                        URL.revokeObjectURL(p.url);
                    }
                }
                const answers = {};
                for (const [key, a] of Object.entries(i.answers)) answers[key] = { value: String(a.value), comment: a.comment.trim() };
                const fails = i.template.structure.sections.flatMap((s) => s.items).filter((it) => this.itemFails(it));
                const doneAt = new Date().toISOString();
                await db.put('inspections', { uuid: i.uuid, local: true, template_name: i.template.name, equipment: i.equipment ? i.equipment.code + ' · ' + i.equipment.name : null,
                    sector_uuid: i.sector || null, schedule: i.schedule, done_at: doneAt, fails: fails.length, critical: fails.filter((it) => it.critical).length });
                await enqueue('inspection.create', { uuid: i.uuid, template: i.template.uuid, version: i.template.version_uuid, equipment: i.equipment?.uuid,
                    sector: i.sector || undefined, schedule: i.schedule || undefined, done_at: doneAt, lat: gps?.lat, lng: gps?.lng, gps_accuracy: gps?.acc,
                    notes: i.notes.trim() || undefined, answers, photo_counts: photoCounts });
                const critical = fails.some((it) => it.critical);
                this.insp = null;
                await this.reloadLocal();
                if (critical) alert('⚠ Falló un ítem CRÍTICO. No uses el equipo y avisá al supervisor. Se crea la acción correctiva al enviar.');
                this.flash((await isOnline()) ? 'Checklist guardado. Enviando…' : 'Checklist guardado en el celular. Se envía cuando haya señal.');
                this.go('reportes');
                syncNow('save');
            } catch (e) {
                alert('No se pudo guardar: ' + e.message);
            } finally {
                this.inspBusy = false;
            }
        },
        async openInspection(uuid) {
            this.inspDetailUuid = uuid;
            this.inspDetail = { local: await db.get('inspections', uuid), remote: null, loading: true };
            try { this.inspDetail.remote = await request('GET', '/inspections/' + uuid); } catch (e) { /* sin red o todavía no enviada */ }
            this.inspDetail.loading = false;
        },
        resultClass(r) { return { conforme: 'text-bg-success', con_observaciones: 'text-bg-warning', no_conforme_critico: 'text-bg-danger' }[r] || 'text-bg-secondary'; },

        // ── acciones (CAPA) ────────────────────────────────────────────
        get myActions() { return this.actions.filter((a) => a.mine && (['abierta', 'en_curso', 'cerrada'].includes(a.status) || a.pending)); },
        get myOverdueActions() { return this.myActions.filter((a) => this.actionLate(a)).length; },
        todayLocal() {
            const d = new Date();
            d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
            return d.toISOString().slice(0, 10);
        },
        actionLate(a) { return ['abierta', 'en_curso'].includes(a.status) && a.due_on < this.todayLocal(); },
        dueText(a) {
            const days = Math.round((new Date(a.due_on + 'T00:00:00') - new Date(this.todayLocal() + 'T00:00:00')) / 86400000);
            const date = a.due_on.split('-').reverse().join('/');
            if (!['abierta', 'en_curso'].includes(a.status)) return 'Límite ' + date;
            if (days < 0) return 'Vencida hace ' + (-days) + (days === -1 ? ' día' : ' días') + ' (' + date + ')';
            return days === 0 ? 'Vence hoy' : 'Vence el ' + date;
        },
        async openAction(uuid) {
            this.actionUuid = uuid;
            this.tab = 'reportes';
            const local = await db.get('actions', uuid);
            this.action = { local, remote: null, loading: true };
            this.actionForm = { text: '', photos: [] };
            const pending = (await db.all('uploads')).filter((u) => u.action_uuid === uuid && u.status !== 'done' && u.blob);
            const localPhotos = pending.map((u) => ({ url: URL.createObjectURL(u.blob), pending: true }));
            this.actionPhotos = localPhotos;
            if (!local) { this.action.loading = false; return; }
            try {
                const remote = await request('GET', '/actions/' + uuid);
                const remotePhotos = [];
                for (const f of remote.files.filter((x) => x.image)) {
                    try { remotePhotos.push({ url: await blobUrl(f.url.slice(f.url.indexOf('/api/v1') + 7)), pending: false, old: f.kind === 'evidencia' && f.cycle < local.cycle }); } catch (e) { /* sin foto */ }
                }
                this.actionPhotos = [...remotePhotos, ...localPhotos];
                this.action = { local, remote, loading: false };
            } catch (e) {
                this.action = { local, remote: null, loading: false };
            }
        },
        closeAction() { history.length > 1 ? history.back() : this.go('reportes'); },
        async addActionPhotos(event) {
            const files = [...event.target.files].slice(0, MAX_PHOTOS - this.actionForm.photos.length);
            event.target.value = '';
            for (const f of files) {
                const blob = await shrinkPhoto(f);
                this.actionForm.photos.push({ blob, url: URL.createObjectURL(blob), name: (f.name || 'evidencia').replace(/\.\w+$/, '') + '.jpg' });
            }
        },
        removeActionPhoto(i) {
            URL.revokeObjectURL(this.actionForm.photos[i].url);
            this.actionForm.photos.splice(i, 1);
        },
        async startAction() {
            const a = this.action.local;
            await enqueue('action.start', { uuid: a.uuid });
            await db.put('actions', { ...a, status: 'en_curso', status_label: 'En curso', pending: true, sync_error: null });
            this.flash('Acción tomada. Se envía al sincronizar.');
            await this.reloadLocal();
            this.action.local = await db.get('actions', a.uuid);
            syncNow('save');
        },
        /** Cierre offline: la evidencia queda en el celular y el cierre se manda después de subir las fotos. */
        async submitClose() {
            const a = this.action.local;
            const text = this.actionForm.text.trim();
            if (text.length < 10) { alert('Contá qué se hizo (mínimo 10 letras).'); return; }
            if (!this.actionForm.photos.length && !(a.evidence > 0)) { alert('Sacá al menos una foto de evidencia.'); return; }
            this.actionBusy = true;
            try {
                for (const p of this.actionForm.photos) {
                    await db.put('uploads', {
                        upload_uuid: crypto.randomUUID(), action_uuid: a.uuid, name: p.name, size: p.blob.size,
                        sha256: await sha256Hex(p.blob), blob: p.blob, status: 'pending', received: 0,
                    });
                    URL.revokeObjectURL(p.url);
                }
                await enqueue('action.close', { uuid: a.uuid, closure_text: text });
                await db.put('actions', { ...a, status: 'cerrada', status_label: 'Cerrada · a verificar', closure_text: text, pending: true, sync_error: null });
                this.actionForm = { text: '', photos: [] };
                await this.reloadLocal();
                this.flash((await isOnline()) ? 'Cierre guardado. Enviando fotos y cierre…' : 'Cierre guardado en el celular. Se envía cuando haya señal.');
                this.go('reportes');
                syncNow('save');
            } catch (e) {
                alert('No se pudo guardar: ' + e.message);
            } finally {
                this.actionBusy = false;
            }
        },

        // ── rondas ─────────────────────────────────────────────────────
        get activeRoute() { return this.activeRound?.route_uuid ? this.patrolRoutes.find((r) => r.uuid === this.activeRound.route_uuid) || null : null; },
        get roundScans() { return this.activeRound ? this.patrolScans.filter((x) => x.round_uuid === this.activeRound.uuid) : []; },
        /** Puntos de la ruta en orden, con si ya se registraron; el primero pendiente es el "próximo". */
        get roundProgress() {
            const route = this.activeRoute;
            if (!route) return [];
            const done = new Set(this.roundScans.map((x) => x.point_uuid));
            let nextMarked = false;
            return (route.points || []).map((uuid, i) => {
                const p = this.patrolPoints.find((x) => x.uuid === uuid) || { uuid, code: '?', name: 'Punto no sincronizado', critical: false };
                const isDone = done.has(uuid);
                const next = !isDone && !nextMarked;
                if (next) nextMarked = true;
                return { ...p, n: i + 1, done: isDone, next };
            });
        },
        get roundDoneCount() { return this.roundProgress.filter((p) => p.done).length; },
        get roundPointInRoute() { return !this.activeRoute || (this.activeRoute.points || []).includes(this.roundPointUuid); },
        get roundPointDone() { return this.roundScans.some((x) => x.point_uuid === this.roundPointUuid); },
        get canPatrol() { return !!this.meta?.permisos?.rondas?.acciones?.includes('crear'); },
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
                        const patrol = codes.map((c) => c.rawValue.match(/\/ronda\/punto\/([0-9a-f-]{36})/i)).find(Boolean);
                        if (patrol) { this.scanner.open = false; stream.getTracks().forEach((t) => t.stop()); location.hash = '#/ronda/punto/' + patrol[1].toLowerCase(); return; }
                        const m = codes.map((c) => c.rawValue.match(/\/q\/([0-9a-f-]{36})/i)).find(Boolean);
                        if (m) {
                            const eq = this.equipment.find((e) => e.uuid === m[1].toLowerCase());
                            if (eq && this.newMode === 'insp') {
                                this.scanner.open = false;
                                stream.getTracks().forEach((t) => t.stop());
                                this.chooseEquipment(eq);
                                return;
                            }
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

        async loadRoundPoint() {
            this.roundPoint = this.patrolPoints.find((p) => p.uuid === this.roundPointUuid) || null;
            if (!this.roundPoint && this.patrolPoints.length) this.flash('Punto no sincronizado todavía. Conectate y sincronizá.');
        },
        async startRound(routeUuid = null) {
            if (this.activeRound) { this.flash('Ya tenés una ronda en curso'); return; }
            const uuid = crypto.randomUUID();
            const gps = await this.currentPosition();
            this.activeRound = { uuid, route_uuid: routeUuid, status: 'en_curso', started_at: new Date().toISOString(), mine: true, local: true };
            await db.put('patrol_rounds', this.activeRound);
            await enqueue('round.start', { uuid, route_uuid: routeUuid, lat: gps?.lat, lng: gps?.lng });
            this.flash(routeUuid ? 'Ruta iniciada: escaneá el primer punto' : 'Ronda iniciada');
            syncNow('round');
        },
        async scanRoundPoint() {
            if (!this.roundPoint) return;
            if (!this.activeRound) await this.startRound();
            if (this.roundPointDone) { this.flash('Este punto ya está registrado en la ronda'); return; }
            if (!this.roundPointInRoute && !confirm('Este punto no es parte de la ruta en curso. ¿Registrarlo igual?')) return;
            const gps = await this.currentPosition();
            const scan = { uuid: crypto.randomUUID(), round_uuid: this.activeRound.uuid, point_uuid: this.roundPoint.uuid,
                lat: gps?.lat, lng: gps?.lng, accuracy_m: gps?.acc, scanned_at_device: new Date().toISOString() };
            await db.put('patrol_scans', { ...scan, local: true });
            await enqueue('round.scan', scan);
            this.patrolScans = await db.all('patrol_scans');
            const pending = this.roundProgress.filter((p) => !p.done).length;
            this.flash((gps ? 'Punto registrado con GPS' : 'Punto registrado sin GPS') + (this.activeRoute ? (pending ? ` · faltan ${pending}` : ' · ruta completa') : ''));
            syncNow('round');
            this.go('rondas');
        },
        async finishRound() {
            if (!this.activeRound) return;
            const missing = this.roundProgress.filter((p) => !p.done);
            if (missing.length && !confirm(`Faltan ${missing.length} punto(s) de la ruta. Si finalizás, quedan como salteados. ¿Finalizar igual?`)) return;
            await enqueue('round.finish', { round_uuid: this.activeRound.uuid });
            await db.put('patrol_rounds', { ...this.activeRound, status: missing.length ? 'incompleta' : 'completa' });
            this.activeRound = null;
            this.flash(missing.length ? 'Ronda finalizada con puntos salteados' : 'Ronda finalizada');
            syncNow('round');
        },
        currentPosition() {
            return new Promise((resolve) => {
                if (!navigator.geolocation) return resolve(null);
                navigator.geolocation.getCurrentPosition((p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, acc: Math.round(p.coords.accuracy) }), () => resolve(null), { enableHighAccuracy: true, timeout: 10000, maximumAge: 10000 });
            });
        },

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
            this.actionUuid = null;
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
            if (op.type.startsWith('action.')) { // vuelve a mostrar lo que diga el servidor en la próxima sync
                const local = await db.get('actions', op.data.uuid);
                if (local) await db.put('actions', { ...local, pending: false });
            }
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
