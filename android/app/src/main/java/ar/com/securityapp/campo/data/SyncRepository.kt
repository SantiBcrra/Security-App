package ar.com.securityapp.campo.data

import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put

/**
 * Sincronización con el servidor: primero manda la cola de lo hecho offline (outbox) y después baja los cambios desde el
 * último cursor (datos maestros, rutas, lo visible para el usuario) a la base local.
 */
class SyncRepository(private val api: ApiClient, private val prefs: Prefs, private val db: LocalDb, private val outbox: Outbox,
                     private val uploads: Uploads, private val inspections: InspectionsRepository) {

    data class Full(val sent: Int, val failed: Int, val pulled: Result)

    suspend fun syncAll(): Full {
        queueTracks()
        // El cierre de una acción espera a que suban sus fotos (el servidor exige evidencia para cerrar).
        val waiting = { op: LocalDb.Op -> op.type == "action.close" && db.uploads("pending").any { it.target == "action" && op.data.contains(it.targetUuid) } }
        val first = outbox.push(hold = waiting, onResult = ::onResult)
        // Fotos: solo las de registros que ya llegaron al servidor (si no, el servidor las rechaza).
        uploads.push { up -> up.target == "action" || onServer(up.targetUuid) }
        val second = outbox.push(hold = waiting, onResult = ::onResult)
        return Full(first.sent + second.sent, first.failed + second.failed, pull())
    }

    /** El alta (observación, inspección, incidente) ya llegó al servidor: sus fotos se pueden subir. */
    private fun onServer(uuid: String): Boolean =
        (db.ops("pending") + db.ops("failed")).none { it.type.endsWith(".create") && it.data.contains(uuid) }

    private fun onResult(op: LocalDb.Op, res: JsonObject) {
        if (op.type == "inspection.create") inspections.onPushed(op, res)
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
            data.obj("meta")?.let { prefs.metaJson = it.toString() }
            cursor = data.strOrNull("cursor")
            prefs.cursor = cursor
            pages++
            if (!data.bool("has_more")) break
        }
        prefs.lastSync = System.currentTimeMillis()
        return Result(pages, changes, deleted)
    }

    /** Las posiciones juntadas pasan a la cola en lotes de 100 por ronda (después de su `round.start`, que ya está en la cola). */
    fun queueTracks() {
        val pending = db.unqueuedTracks()
        for ((round, points) in pending.groupBy { it.roundUuid }) {
            for (chunk in points.chunked(100)) {
                outbox.enqueue("round.track", buildJsonObject {
                    put("round_uuid", round)
                    put("points", buildJsonArray {
                        for (p in chunk) add(buildJsonObject {
                            put("uuid", p.uuid); put("at", p.at); put("lat", p.lat); put("lng", p.lng)
                            p.accuracy?.let { put("accuracy_m", it) }; p.battery?.let { put("battery", it) }
                        })
                    })
                })
                db.markTracksQueued(chunk.map { it.uuid })
            }
        }
    }

    private companion object {
        const val MAX_PAGES = 50
    }
}
