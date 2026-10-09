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
    }

    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        // Versiones futuras: migrar acá sin perder la cola de envío.
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
        }
    }

    private companion object {
        const val VERSION = 1
    }
}
