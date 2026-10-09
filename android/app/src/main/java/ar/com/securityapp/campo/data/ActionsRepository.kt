package ar.com.securityapp.campo.data

import android.net.Uri
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put
import java.time.LocalDate

data class CapaAction(
    val uuid: String, val code: String, val title: String, val description: String, val status: String, val statusLabel: String,
    val priority: String, val dueOn: String?, val responsible: String, val mine: Boolean, val sectorUuid: String?, val origin: String,
    val observationCode: String?, val evidence: Int, val canStart: Boolean, val canClose: Boolean, val closureText: String?,
    /** Lo hecho en el celular que todavía no confirmó el servidor ("start" / "close"), o el rechazo. */
    val pending: String?, val failure: String?,
) {
    val open get() = status == "abierta" || status == "en_curso"
    fun overdue(today: LocalDate = LocalDate.now()): Boolean = open && dueOn != null && runCatching { LocalDate.parse(dueOn) < today }.getOrDefault(false)
}

/**
 * Acciones CAPA en el celular: el responsable las toma y las cierra con texto + fotos aunque no haya señal. El cierre
 * (`action.close`) sale recién cuando subieron sus fotos (ver `SyncRepository`); verificar se hace en la web.
 */
class ActionsRepository(private val db: LocalDb, private val outbox: Outbox, private val uploads: Uploads, private val api: ApiClient) {

    fun list(): List<CapaAction> {
        val ops = db.ops("pending") + db.ops("failed")
        return db.all("actions").mapNotNull { parse(it) }.map { a ->
            val uuid = a.str("uuid")
            val mineOps = ops.filter { (it.type == "action.start" || it.type == "action.close") && it.data.contains(uuid) }
            val pendingOp = mineOps.lastOrNull { it.status == "pending" }
            CapaAction(uuid, a.str("code"), a.str("title"), a.str("description"), a.str("status"), a.str("status_label"), a.str("priority"),
                a.strOrNull("due_on"), a.str("responsible"), a.bool("mine"), a.strOrNull("sector_uuid"), a.str("origin"), a.strOrNull("observation_code"),
                a.int("evidence"), a.bool("can_start"), a.bool("can_close"), a.strOrNull("closure_text"),
                pendingOp?.type?.substringAfter('.'), mineOps.firstOrNull { it.status == "failed" }?.error)
        }.sortedWith(compareBy<CapaAction>({ !it.open }, { it.dueOn ?: "9999" }, { it.code }))
    }

    fun find(uuid: String): CapaAction? = list().firstOrNull { it.uuid == uuid }

    /** "La tomo": pasa a en curso. */
    fun start(uuid: String) {
        outbox.enqueue("action.start", buildJsonObject { put("uuid", uuid) })
        patch(uuid) { put("status", "en_curso"); put("status_label", "En curso"); put("can_start", false) }
    }

    /** Cierre con lo que se hizo + fotos de evidencia (el servidor exige al menos una del ciclo actual). */
    suspend fun close(uuid: String, text: String, photos: List<Uri>) {
        for (photo in photos) uploads.add(photo, "action", uuid)
        outbox.enqueue("action.close", buildJsonObject { put("uuid", uuid); put("closure_text", text.trim()) })
        patch(uuid) { put("status", "cerrada"); put("status_label", "Cerrada"); put("can_start", false); put("can_close", false); put("closure_text", text.trim()) }
    }

    fun pendingPhotos(uuid: String): Int = db.uploads("pending", uuid).size

    /** Detalle con conexión: línea de tiempo y evidencia. */
    suspend fun detail(uuid: String): JsonObject = api.get("/actions/$uuid").jsonObject

    /** Cambio local hasta que la próxima sincronización traiga el estado real. */
    private fun patch(uuid: String, change: kotlinx.serialization.json.JsonObjectBuilder.() -> Unit) {
        val row = db.all("actions").mapNotNull { parse(it) }.firstOrNull { it.str("uuid") == uuid } ?: return
        val merged = JsonObject(row + buildJsonObject(change))
        db.upsert("actions", listOf(uuid to merged.toString()))
    }

    private fun parse(s: String): JsonObject? = runCatching { api.json.parseToJsonElement(s).jsonObject }.getOrNull()
}
