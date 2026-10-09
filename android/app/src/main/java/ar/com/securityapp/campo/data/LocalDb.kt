package ar.com.securityapp.campo.data

import android.content.ContentValues
import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper

/**
 * Base local del celular (SQLite). Todo lo que baja la sincronización se guarda por entidad + uuid como JSON:
 * así la app funciona sin señal. La cola de envío (outbox) guarda lo hecho offline hasta que el servidor lo confirma.
 */
class LocalDb(context: Context) : SQLiteOpenHelper(context, "campo.db", null, VERSION) {

    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL("CREATE TABLE records (entity TEXT NOT NULL, uuid TEXT NOT NULL, data TEXT NOT NULL, PRIMARY KEY (entity, uuid))")
        db.execSQL("CREATE TABLE outbox (op_id TEXT PRIMARY KEY, type TEXT NOT NULL, data TEXT NOT NULL, status TEXT NOT NULL, " +
            "attempts INTEGER NOT NULL DEFAULT 0, error TEXT, created_at INTEGER NOT NULL)")
        createTracks(db)
        createUploads(db)
    }

    /** Fotos esperando subir (el archivo vive en el celular hasta que el servidor confirma el hash). */
    private fun createUploads(db: SQLiteDatabase) {
        db.execSQL("CREATE TABLE IF NOT EXISTS uploads (upload_uuid TEXT PRIMARY KEY, target TEXT NOT NULL, target_uuid TEXT NOT NULL, path TEXT NOT NULL, " +
            "name TEXT NOT NULL, size INTEGER NOT NULL, sha256 TEXT NOT NULL, received INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL, error TEXT, created_at INTEGER NOT NULL)")
    }

    data class Upload(val uploadUuid: String, val target: String, val targetUuid: String, val path: String, val name: String, val size: Long, val sha256: String,
                      val received: Long, val status: String, val error: String?)

    fun addUpload(u: Upload) {
        writableDatabase.insertWithOnConflict("uploads", null, ContentValues().apply {
            put("upload_uuid", u.uploadUuid); put("target", u.target); put("target_uuid", u.targetUuid); put("path", u.path); put("name", u.name)
            put("size", u.size); put("sha256", u.sha256); put("received", u.received); put("status", u.status); put("created_at", System.currentTimeMillis())
        }, SQLiteDatabase.CONFLICT_IGNORE)
    }

    fun uploads(status: String? = null, targetUuid: String? = null): List<Upload> {
        val where = mutableListOf<String>()
        val args = mutableListOf<String>()
        status?.let { where += "status = ?"; args += it }
        targetUuid?.let { where += "target_uuid = ?"; args += it }
        val sql = "SELECT upload_uuid, target, target_uuid, path, name, size, sha256, received, status, error FROM uploads" +
            (if (where.isEmpty()) "" else " WHERE " + where.joinToString(" AND ")) + " ORDER BY created_at"
        return readableDatabase.rawQuery(sql, args.toTypedArray()).use { c ->
            buildList { while (c.moveToNext()) add(Upload(c.getString(0), c.getString(1), c.getString(2), c.getString(3), c.getString(4), c.getLong(5), c.getString(6),
                c.getLong(7), c.getString(8), c.getString(9))) }
        }
    }

    fun updateUpload(uuid: String, status: String, received: Long, error: String? = null) {
        writableDatabase.update("uploads", ContentValues().apply { put("status", status); put("received", received); put("error", error) }, "upload_uuid = ?", arrayOf(uuid))
    }

    /** Posiciones de la ronda en curso: se juntan acá y se mandan en lote (`round.track`). */
    private fun createTracks(db: SQLiteDatabase) {
        db.execSQL("CREATE TABLE IF NOT EXISTS tracks (uuid TEXT PRIMARY KEY, round_uuid TEXT NOT NULL, at TEXT NOT NULL, lat REAL NOT NULL, lng REAL NOT NULL, " +
            "accuracy REAL, battery INTEGER, queued INTEGER NOT NULL DEFAULT 0)")
    }

    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        // Nunca se borra la cola de envío: solo se agregan tablas.
        if (oldVersion < 2) createTracks(db)
        if (oldVersion < 3) createUploads(db)
    }

    data class TrackPoint(val uuid: String, val roundUuid: String, val at: String, val lat: Double, val lng: Double, val accuracy: Float?, val battery: Int?)

    fun addTrack(p: TrackPoint) {
        writableDatabase.insertWithOnConflict("tracks", null, ContentValues().apply {
            put("uuid", p.uuid); put("round_uuid", p.roundUuid); put("at", p.at); put("lat", p.lat); put("lng", p.lng)
            put("accuracy", p.accuracy); put("battery", p.battery); put("queued", 0)
        }, SQLiteDatabase.CONFLICT_IGNORE)
    }

    fun unqueuedTracks(): List<TrackPoint> =
        readableDatabase.rawQuery("SELECT uuid, round_uuid, at, lat, lng, accuracy, battery FROM tracks WHERE queued = 0 ORDER BY at", null).use { c ->
            buildList {
                while (c.moveToNext()) add(TrackPoint(c.getString(0), c.getString(1), c.getString(2), c.getDouble(3), c.getDouble(4),
                    if (c.isNull(5)) null else c.getFloat(5), if (c.isNull(6)) null else c.getInt(6)))
            }
        }

    fun markTracksQueued(uuids: List<String>) {
        val db = writableDatabase
        db.beginTransaction()
        try {
            for (u in uuids) db.execSQL("UPDATE tracks SET queued = 1 WHERE uuid = ?", arrayOf(u))
            db.execSQL("DELETE FROM tracks WHERE queued = 1 AND at < ?", arrayOf(java.time.Instant.now().minusSeconds(3 * 86400).toString()))
            db.setTransactionSuccessful()
        } finally {
            db.endTransaction()
        }
    }

    fun trackCount(roundUuid: String): Int =
        readableDatabase.rawQuery("SELECT COUNT(*) FROM tracks WHERE round_uuid = ?", arrayOf(roundUuid)).use { c -> if (c.moveToFirst()) c.getInt(0) else 0 }

    fun updateOpData(opId: String, data: String) {
        writableDatabase.update("outbox", ContentValues().apply { put("data", data) }, "op_id = ?", arrayOf(opId))
    }

    fun upsert(entity: String, rows: List<Pair<String, String>>) {
        if (rows.isEmpty()) return
        val db = writableDatabase
        db.beginTransaction()
        try {
            for ((uuid, json) in rows) {
                db.insertWithOnConflict("records", null, ContentValues().apply {
                    put("entity", entity); put("uuid", uuid); put("data", json)
                }, SQLiteDatabase.CONFLICT_REPLACE)
            }
            db.setTransactionSuccessful()
        } finally {
            db.endTransaction()
        }
    }

    fun delete(entity: String, uuids: List<String>) {
        if (uuids.isEmpty()) return
        val db = writableDatabase
        db.beginTransaction()
        try {
            for (uuid in uuids) db.delete("records", "entity = ? AND uuid = ?", arrayOf(entity, uuid))
            db.setTransactionSuccessful()
        } finally {
            db.endTransaction()
        }
    }

    fun all(entity: String): List<String> =
        readableDatabase.rawQuery("SELECT data FROM records WHERE entity = ?", arrayOf(entity)).use { c ->
            buildList { while (c.moveToNext()) add(c.getString(0)) }
        }

    fun count(entity: String): Int =
        readableDatabase.rawQuery("SELECT COUNT(*) FROM records WHERE entity = ?", arrayOf(entity)).use { c -> if (c.moveToFirst()) c.getInt(0) else 0 }

    fun get(entity: String, uuid: String): String? =
        readableDatabase.rawQuery("SELECT data FROM records WHERE entity = ? AND uuid = ?", arrayOf(entity, uuid)).use { c -> if (c.moveToFirst()) c.getString(0) else null }

    fun pendingOps(): Int =
        readableDatabase.rawQuery("SELECT COUNT(*) FROM outbox WHERE status = 'pending'", null).use { c -> if (c.moveToFirst()) c.getInt(0) else 0 }

    // ── cola de envío ──────────────────────────────────────────────

    data class Op(val opId: String, val type: String, val data: String, val status: String, val attempts: Int, val error: String?, val createdAt: Long)

    fun addOp(op: Op) {
        writableDatabase.insert("outbox", null, ContentValues().apply {
            put("op_id", op.opId); put("type", op.type); put("data", op.data); put("status", op.status)
            put("attempts", op.attempts); put("error", op.error); put("created_at", op.createdAt)
        })
    }

    /** En orden de creación: el servidor tiene que recibir "iniciar ronda" antes que sus escaneos. */
    fun ops(status: String): List<Op> =
        readableDatabase.rawQuery("SELECT op_id, type, data, status, attempts, error, created_at FROM outbox WHERE status = ? ORDER BY created_at, rowid", arrayOf(status)).use { c ->
            buildList { while (c.moveToNext()) add(Op(c.getString(0), c.getString(1), c.getString(2), c.getString(3), c.getInt(4), c.getString(5), c.getLong(6))) }
        }

    fun deleteOp(opId: String) {
        writableDatabase.delete("outbox", "op_id = ?", arrayOf(opId))
    }

    fun failOp(opId: String, error: String) {
        writableDatabase.update("outbox", ContentValues().apply { put("status", "failed"); put("error", error) }, "op_id = ?", arrayOf(opId))
    }

    fun touchOp(opId: String) {
        writableDatabase.execSQL("UPDATE outbox SET attempts = attempts + 1 WHERE op_id = ?", arrayOf(opId))
    }

    /** Al salir o al cambiar de usuario: se borra todo lo descargado (la cola se avisa antes en la pantalla). */
    fun clearAll() {
        writableDatabase.apply {
            delete("records", null, null)
            delete("outbox", null, null)
            delete("tracks", null, null)
            delete("uploads", null, null)
        }
    }

    private companion object {
        const val VERSION = 3
    }
}
