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

    fun pendingOps(): Int =
        readableDatabase.rawQuery("SELECT COUNT(*) FROM outbox WHERE status = 'pending'", null).use { c -> if (c.moveToFirst()) c.getInt(0) else 0 }

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
