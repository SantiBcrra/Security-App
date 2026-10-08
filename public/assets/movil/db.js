// Base de datos local del celular (IndexedDB), sin librerías. Todo lo que se carga offline vive acá
// hasta que se sincroniza: catálogos, sectores, equipos, empleados, observaciones, cola de envío
// (outbox) y fotos pendientes.
const NAME = 'secapp-movil';
const VERSION = 4;
const STORES = {
    kv: { keyPath: 'key' },
    catalog_items: { keyPath: 'uuid', indexes: ['catalog'] },
    sites: { keyPath: 'uuid' },
    sectors: { keyPath: 'uuid' },
    equipment: { keyPath: 'uuid', indexes: ['code'] },
    employees: { keyPath: 'uuid' },
    observations: { keyPath: 'uuid' },
    patrol_points: { keyPath: 'uuid' },
    patrol_routes: { keyPath: 'uuid' },
    patrol_rounds: { keyPath: 'uuid' },
    patrol_scans: { keyPath: 'uuid' },
    actions: { keyPath: 'uuid' },
    inspection_templates: { keyPath: 'uuid' },
    inspection_schedule: { keyPath: 'uuid' },
    inspections: { keyPath: 'uuid' },   // las hechas en este celular (pendientes de enviar y enviadas)
    outbox: { keyPath: 'op_id' },
    uploads: { keyPath: 'upload_uuid', indexes: ['observation_uuid'] },
};

let dbPromise = null;

export function open() {
    if (dbPromise) return dbPromise;
    dbPromise = new Promise((resolve, reject) => {
        const req = indexedDB.open(NAME, VERSION);
        req.onupgradeneeded = () => {
            const db = req.result;
            for (const [name, def] of Object.entries(STORES)) {
                if (db.objectStoreNames.contains(name)) continue;
                const store = db.createObjectStore(name, { keyPath: def.keyPath });
                for (const idx of def.indexes || []) store.createIndex(idx, idx);
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
    return dbPromise;
}

function wrap(req) {
    return new Promise((resolve, reject) => {
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

async function store(name, mode = 'readonly') {
    const db = await open();
    return db.transaction(name, mode).objectStore(name);
}

export async function get(name, key) {
    return wrap((await store(name)).get(key));
}

export async function all(name) {
    return wrap((await store(name)).getAll());
}

export async function byIndex(name, index, value) {
    return wrap((await store(name)).index(index).getAll(value));
}

export async function put(name, value) {
    return wrap((await store(name, 'readwrite')).put(value));
}

export async function putMany(name, values) {
    if (!values.length) return;
    const db = await open();
    const tx = db.transaction(name, 'readwrite');
    const s = tx.objectStore(name);
    for (const v of values) s.put(v);
    return new Promise((resolve, reject) => { tx.oncomplete = resolve; tx.onerror = () => reject(tx.error); });
}

export async function del(name, key) {
    return wrap((await store(name, 'readwrite')).delete(key));
}

export async function delMany(name, keys) {
    if (!keys.length) return;
    const db = await open();
    const tx = db.transaction(name, 'readwrite');
    for (const k of keys) tx.objectStore(name).delete(k);
    return new Promise((resolve, reject) => { tx.oncomplete = resolve; tx.onerror = () => reject(tx.error); });
}

export async function count(name) {
    return wrap((await store(name)).count());
}

export async function clearAll() {
    const db = await open();
    const tx = db.transaction(Object.keys(STORES), 'readwrite');
    for (const name of Object.keys(STORES)) tx.objectStore(name).clear();
    return new Promise((resolve, reject) => { tx.oncomplete = resolve; tx.onerror = () => reject(tx.error); });
}

// Clave/valor (tokens, cursor de sync, ajustes)
export async function getKv(key, fallback = null) {
    const row = await get('kv', key);
    return row ? row.value : fallback;
}

export async function setKv(key, value) {
    return put('kv', { key, value });
}
