// Sincronización offline: todo se guarda primero en el celular (outbox) y se manda cuando hay red.
// Orden: 1) operaciones (altas, comentarios, cambios de estado) en lotes chicos, 2) fotos por partes
// (reanudables), 3) operaciones que esperaban sus fotos (el cierre de una acción necesita la evidencia
// ya subida), 4) bajar cambios del servidor con el cursor. Reintento con espera creciente.
import * as db from './db.js';
import { request, isOnline, OfflineError, ApiError } from './api.js';

const OPS_PER_BATCH = 20;
const CHUNK = 512 * 1024;
const BACKOFF = [5, 15, 60, 300]; // segundos

let running = null;

function emit(detail) {
    window.dispatchEvent(new CustomEvent('sync-state', { detail }));
}

export async function pendingCounts() {
    const ops = (await db.all('outbox')).filter((o) => o.status !== 'failed');
    const failed = (await db.all('outbox')).filter((o) => o.status === 'failed');
    const uploads = (await db.all('uploads')).filter((u) => u.status !== 'done' && u.status !== 'rejected');
    return { ops: ops.length, failed: failed.length, photos: uploads.length };
}

/** Agrega una operación a la cola (se manda en la próxima sincronización). */
export async function enqueue(type, data) {
    const op = { op_id: crypto.randomUUID(), type, data, status: 'pending', attempts: 0, created_at: Date.now() };
    await db.put('outbox', op);
    return op;
}

/** Sincroniza ahora (si ya hay una en curso, devuelve esa). */
export function syncNow(reason = 'manual') {
    if (!running) {
        running = run(reason).finally(() => { running = null; });
    }
    return running;
}

async function run(reason) {
    if (!(await isOnline())) {
        emit({ state: 'offline', ...(await pendingCounts()) });
        return { offline: true };
    }
    const wait = await db.getKv('syncRetryAt', 0);
    if (reason === 'auto' && wait > Date.now()) {
        return { waiting: true };
    }
    emit({ state: 'syncing', ...(await pendingCounts()) });
    try {
        await pushOps();
        await pushUploads();
        await pushOps();
        await pull();
        await db.setKv('lastSync', Date.now());
        await db.setKv('syncFailures', 0);
        await db.setKv('syncRetryAt', 0);
        emit({ state: 'ok', ...(await pendingCounts()), lastSync: Date.now() });
        return { ok: true };
    } catch (e) {
        const failures = (await db.getKv('syncFailures', 0)) + 1;
        await db.setKv('syncFailures', failures);
        await db.setKv('syncRetryAt', Date.now() + BACKOFF[Math.min(failures - 1, BACKOFF.length - 1)] * 1000);
        const state = e instanceof OfflineError ? 'offline' : 'error';
        emit({ state, message: e.message, ...(await pendingCounts()) });
        if (e instanceof ApiError && e.status === 401) throw e;
        return { error: e.message };
    }
}

/** Cierre de acción cuyas fotos todavía no terminaron de subir: se manda en la segunda pasada. */
async function waitsForPhotos(op) {
    if (op.type !== 'action.close') return false;
    return (await db.all('uploads')).some((u) => u.action_uuid === op.data.uuid && u.status !== 'done' && u.status !== 'rejected');
}

/** Se llama antes y después de subir las fotos: lo que espera fotos sale en la segunda pasada (o en la próxima sync). */
async function pushOps() {
    const pending = [];
    for (const op of (await db.all('outbox')).filter((o) => o.status === 'pending').sort((a, b) => a.created_at - b.created_at)) {
        if (!(await waitsForPhotos(op))) pending.push(op);
    }
    for (let i = 0; i < pending.length; i += OPS_PER_BATCH) {
        const batch = pending.slice(i, i + OPS_PER_BATCH);
        const res = await request('POST', '/sync/push', { json: { operations: batch.map(({ op_id, type, data }) => ({ op_id, type, data })) } });
        for (const r of res.results) {
            const op = batch.find((o) => o.op_id === r.op_id);
            if (!op) continue;
            if (r.status === 'ok') {
                await db.del('outbox', op.op_id);
                if (op.type === 'observation.create') {
                    const local = await db.get('observations', op.data.uuid);
                    if (local) {
                        await db.put('observations', { ...local, local: false, number: r.data.number, status: r.data.status || local.status, sync_error: null });
                    }
                }
                if (op.type.startsWith('action.')) {
                    const local = await db.get('actions', op.data.uuid);
                    if (local) await db.put('actions', { ...local, status: r.data.status, status_label: r.data.status_label, pending: false, sync_error: null });
                }
            } else {
                // Error de validación: no se reintenta solo (el servidor explicó por qué); queda visible.
                await db.put('outbox', { ...op, status: 'failed', error: r.error });
                if (op.type === 'observation.create') {
                    const local = await db.get('observations', op.data.uuid);
                    if (local) await db.put('observations', { ...local, sync_error: r.error });
                }
                if (op.type.startsWith('action.')) {
                    const local = await db.get('actions', op.data.uuid);
                    if (local) await db.put('actions', { ...local, pending: false, sync_error: r.error });
                }
            }
        }
    }
}

async function pushUploads() {
    const uploads = (await db.all('uploads')).filter((u) => u.status !== 'done');
    for (const up of uploads) {
        if (up.status === 'rejected') continue;
        if (!up.action_uuid) {
            const obs = await db.get('observations', up.observation_uuid);
            if (!obs || obs.local) continue; // la observación todavía no llegó al servidor: esperar
        }
        let state;
        try {
            state = await request('POST', '/uploads', { json: {
                upload_uuid: up.upload_uuid, observation_uuid: up.observation_uuid, action_uuid: up.action_uuid, name: up.name, size: up.size, sha256: up.sha256,
            } });
        } catch (e) {
            // La acción ya no admite evidencia (la cerraron/cancelaron desde la web): no se reintenta.
            if (up.action_uuid && e instanceof ApiError && e.status === 404) { await db.put('uploads', { ...up, status: 'rejected', error: e.message }); continue; }
            throw e;
        }
        let offset = state.received_bytes;
        while (state.status === 'receiving' && offset < up.size) {
            try {
                state = await request('PUT', '/uploads/' + up.upload_uuid, { raw: up.blob.slice(offset, offset + CHUNK), query: { offset } });
            } catch (e) {
                if (e instanceof ApiError && e.status === 409 && e.data) { state = e.data; } else { throw e; }
            }
            offset = state.received_bytes;
            await db.put('uploads', { ...up, received: offset });
        }
        if (state.status === 'receiving') {
            try {
                state = await request('POST', '/uploads/' + up.upload_uuid + '/complete');
            } catch (e) {
                if (e instanceof ApiError && e.status === 422 && (e.message.includes('hash') || e.message.includes('dañada'))) continue; // se reintenta entera
                if (e instanceof ApiError && e.status === 422) { await db.put('uploads', { ...up, status: 'rejected', error: e.message }); continue; }
                throw e;
            }
        }
        if (state.status === 'completed') {
            await db.put('uploads', { ...up, status: 'done', blob: null, attachment_uuid: state.attachment_uuid });
        }
    }
}

async function pull() {
    let cursor = await db.getKv('cursor', null);
    for (let page = 0; page < 50; page++) {
        const data = await request('GET', '/sync/pull', { query: cursor ? { cursor, limit: 500 } : { limit: 500 } });
        for (const [entity, rows] of Object.entries(data.changes || {})) {
            if (entity === 'actions') {
                // Lo que el responsable hizo en el celular y todavía no se envió no se pisa.
                const merged = [];
                for (const row of rows) {
                    const local = await db.get('actions', row.uuid);
                    merged.push(local?.pending ? { ...row, status: local.status, status_label: local.status_label, closure_text: local.closure_text, pending: true } : row);
                }
                await db.putMany('actions', merged);
            } else if (entity === 'observations') {
                // No pisar lo que todavía no se envió
                const merged = [];
                for (const row of rows) {
                    const local = await db.get('observations', row.uuid);
                    merged.push({ ...(local || {}), ...row, local: false, server: true });
                }
                await db.putMany('observations', merged);
            } else {
                await db.putMany(entity, rows);
            }
        }
        for (const [entity, uuids] of Object.entries(data.deleted || {})) {
            await db.delMany(entity, uuids);
        }
        await db.setKv('meta', data.meta);
        cursor = data.cursor;
        await db.setKv('cursor', cursor);
        if (!data.has_more) break;
    }
}

/** Disparadores automáticos: al volver la red, al volver a la app y cada 60 s en primer plano. */
export function startAutoSync() {
    window.addEventListener('online', () => syncNow('online'));
    document.addEventListener('visibilitychange', () => { if (!document.hidden) syncNow('auto'); });
    setInterval(() => { if (!document.hidden) syncNow('auto'); }, 60000);
}

/** Reintenta una operación que el servidor rechazó (después de corregirla o por si era temporal). */
export async function retryFailed(opId) {
    // Con otro op_id: el servidor guarda el resultado de cada op_id (también los errores), así que
    // reenviar el mismo devolvería el mismo rechazo. Las altas igual no se duplican (uuid del celular).
    const op = await db.get('outbox', opId);
    if (!op) return;
    await db.del('outbox', opId);
    await db.put('outbox', { ...op, op_id: crypto.randomUUID(), status: 'pending', error: null });
}
