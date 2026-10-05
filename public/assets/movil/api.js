// Cliente de la API v1 con JWT: renueva el token solo cuando vence y avisa si la sesión se perdió.
import { getKv, setKv } from './db.js';

const BASE = document.querySelector('meta[name="app-base"]').content;
export const API = BASE + '/api/v1';

export class OfflineError extends Error {}
export class ApiError extends Error {
    constructor(message, status, code, data) { super(message); this.status = status; this.code = code; this.data = data; }
}

let refreshing = null;

/** ¿Hay red? (respeta "Simular sin conexión" de Ajustes) */
export async function isOnline() {
    return navigator.onLine && !(await getKv('simOffline', false));
}

export async function request(method, path, { json, raw, query, auth = true, retry = true } = {}) {
    if (!(await isOnline())) throw new OfflineError('Sin conexión');
    const headers = { Accept: 'application/json' };
    if (auth) {
        const token = await getKv('accessToken');
        if (token) headers.Authorization = 'Bearer ' + token;
    }
    let body;
    if (json !== undefined) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(json); }
    if (raw !== undefined) { headers['Content-Type'] = 'application/octet-stream'; body = raw; }
    const url = API + path + (query ? '?' + new URLSearchParams(query) : '');
    let res;
    try {
        res = await fetch(url, { method, headers, body, cache: 'no-store' });
    } catch (e) {
        throw new OfflineError('No se pudo conectar con el servidor');
    }
    let payload = null;
    try { payload = await res.json(); } catch (e) { /* respuesta sin JSON */ }

    if (res.status === 401 && auth && retry && payload?.error?.code === 'token_expired') {
        await refresh();
        return request(method, path, { json, raw, query, auth, retry: false });
    }
    if (!res.ok || !payload?.ok) {
        const err = payload?.error || {};
        if (res.status === 401 && auth) window.dispatchEvent(new CustomEvent('session-lost', { detail: err.message }));
        throw new ApiError(err.message || 'Error ' + res.status, res.status, err.code, payload?.data);
    }
    return payload.data;
}

/** Renovación del token (una sola a la vez aunque haya varios pedidos esperando). */
export async function refresh() {
    if (!refreshing) {
        refreshing = (async () => {
            const refreshToken = await getKv('refreshToken');
            if (!refreshToken) throw new ApiError('Sesión vencida', 401, 'no_refresh');
            const data = await request('POST', '/auth/refresh', { json: { refresh_token: refreshToken }, auth: false });
            await setKv('accessToken', data.access_token);
            await setKv('refreshToken', data.refresh_token);
        })().finally(() => { refreshing = null; });
    }
    return refreshing;
}

/** Descarga con token (fotos protegidas) → URL local para <img>. */
export async function blobUrl(path) {
    const token = await getKv('accessToken');
    const res = await fetch(API + path, { headers: { Authorization: 'Bearer ' + token } });
    if (res.status === 401) { await refresh(); return blobUrl(path); }
    if (!res.ok) throw new ApiError('No se pudo bajar la foto', res.status);
    return URL.createObjectURL(await res.blob());
}
