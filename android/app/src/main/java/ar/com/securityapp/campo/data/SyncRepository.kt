package ar.com.securityapp.campo.data

import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.jsonObject

/**
 * Sincronización con el servidor: primero manda la cola de lo hecho offline (outbox) y después baja los cambios desde el
 * último cursor (datos maestros, rutas, lo visible para el usuario) a la base local.
 */
class SyncRepository(private val api: ApiClient, private val prefs: Prefs, private val db: LocalDb, private val outbox: Outbox) {

    data class Full(val sent: Int, val failed: Int, val pulled: Result)

    suspend fun syncAll(): Full {
        val pushed = outbox.push()
        return Full(pushed.sent, pushed.failed, pull())
    }

    data class Result(val pages: Int, val changes: Int, val deleted: Int)

    suspend fun pull(): Result {
        var cursor = prefs.cursor
        var pages = 0
        var changes = 0
        var deleted = 0
        while (pages < MAX_PAGES) {
            val query = buildMap { put("limit", "500"); cursor?.let { put("cursor", it) } }
            val data = api.get("/sync/pull", query).jsonObject
            data.obj("changes")?.forEach { (entity, rows) ->
                val list = (rows as? JsonArray)?.mapNotNull { it as? JsonObject }.orEmpty()
                db.upsert(entity, list.mapNotNull { row -> row.strOrNull("uuid")?.let { it to row.toString() } })
                changes += list.size
            }
            data.obj("deleted")?.forEach { (entity, uuids) ->
                val list = (uuids as? JsonArray)?.mapNotNull { (it as? JsonPrimitive)?.content }.orEmpty()
                db.delete(entity, list)
                deleted += list.size
            }
            cursor = data.strOrNull("cursor")
            prefs.cursor = cursor
            pages++
            if (!data.bool("has_more")) break
        }
        prefs.lastSync = System.currentTimeMillis()
        return Result(pages, changes, deleted)
    }

    private companion object {
        const val MAX_PAGES = 50
    }
}
