package ar.com.securityapp.campo.data

import android.net.Uri
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put
import java.time.Instant
import java.util.UUID

data class CatalogItem(val uuid: String, val catalog: String, val name: String, val color: String?, val level: Int, val sort: Int)
data class Sector(val uuid: String, val label: String)
data class Equipment(val uuid: String, val code: String, val name: String, val sectorUuid: String?)

data class Observation(val uuid: String, val number: Int, val status: String, val statusLabel: String, val description: String, val imminent: Boolean,
                       val mine: Boolean, val local: Boolean, val createdAt: String, val sectorUuid: String?, val severityUuid: String?, val photos: Int)

/** Lo que se carga en el formulario de "Reportar". */
data class ObservationDraft(val category: String, val severity: String, val riskType: String?, val sector: String, val equipment: String?,
                            val description: String, val locationText: String, val occurredAt: Instant, val imminent: Boolean, val anonymous: Boolean,
                            val fix: Fix?, val photos: List<Uri>)

/**
 * Observaciones en la app: se guardan primero en el celular y se encolan (`observation.create`, misma validación que la
 * web en el servidor); las fotos se suben por partes después de que la observación llegó.
 */
class ObservationsRepository(private val db: LocalDb, private val outbox: Outbox, private val uploads: Uploads, private val api: ApiClient) {

    fun catalog(catalog: String): List<CatalogItem> = db.all("catalog_items").mapNotNull { parse(it) }.filter { it.str("catalog") == catalog }
        .map { CatalogItem(it.str("uuid"), it.str("catalog"), it.str("name"), it.strOrNull("color"), it.int("level"), it.int("sort")) }
        .sortedWith(compareBy({ it.sort }, { it.name }))

    fun sectors(): List<Sector> = db.all("sectors").mapNotNull { parse(it) }.map { Sector(it.str("uuid"), it.str("label")) }.sortedBy { it.label.lowercase() }

    fun equipment(): List<Equipment> = db.all("equipment").mapNotNull { parse(it) }
        .map { Equipment(it.str("uuid"), it.str("code"), it.str("name"), it.strOrNull("sector_uuid")) }.sortedBy { it.code }

    fun anonymousAllowed(prefs: Prefs): Boolean =
        prefs.metaJson?.let { runCatching { api.json.parseToJsonElement(it).jsonObject.bool("anonimo_habilitado") }.getOrNull() } ?: false

    fun list(): List<Observation> = db.all("observations").mapNotNull { parse(it) }.map { o ->
        Observation(o.str("uuid"), o.int("number"), o.str("status"), o.str("status_label"), o.str("description"), o.bool("imminent"), o.bool("mine"),
            o.bool("local"), o.str("created_at_device"), o.strOrNull("sector_uuid"), o.strOrNull("severity_uuid"), db.uploads(targetUuid = o.str("uuid")).size)
    }.sortedByDescending { it.createdAt }

    /** Rechazo del servidor para una observación local (validación): se muestra en la lista. */
    fun failure(uuid: String): String? = outbox.failed().firstOrNull { it.type == "observation.create" && it.data.contains(uuid) }?.error

    /** La observación ya existe en el servidor: sus fotos se pueden subir. */
    fun onServer(uuid: String): Boolean =
        (db.ops("pending") + db.ops("failed")).none { it.type == "observation.create" && it.data.contains(uuid) }

    suspend fun create(d: ObservationDraft): String {
        val uuid = UUID.randomUUID().toString()
        val at = d.occurredAt.toString()
        val data = buildJsonObject {
            put("uuid", uuid); put("category", d.category); put("severity", d.severity); d.riskType?.let { put("risk_type", it) }
            put("sector", d.sector); d.equipment?.let { put("equipment", it) }; put("description", d.description.trim())
            if (d.locationText.isNotBlank()) put("location_text", d.locationText.trim())
            d.fix?.let { put("lat", "%.7f".format(java.util.Locale.US, it.lat)); put("lng", "%.7f".format(java.util.Locale.US, it.lng)); put("gps_accuracy", it.accuracyM.toInt()) }
            put("imminent", if (d.imminent) 1 else 0); put("anonymous", if (d.anonymous) 1 else 0); put("created_at_device", at)
        }
        for (photo in d.photos) uploads.add(photo, "observation", uuid)
        db.upsert("observations", listOf(uuid to buildJsonObject {
            put("uuid", uuid); put("number", 0); put("status", "pendiente"); put("status_label", "Pendiente de enviar")
            put("description", d.description.trim()); put("imminent", d.imminent); put("mine", !d.anonymous); put("local", true)
            put("created_at_device", at); put("sector_uuid", d.sector); put("severity_uuid", d.severity)
        }.toString()))
        outbox.enqueue("observation.create", data)
        return uuid
    }

    /** Detalle con conexión (línea de tiempo, estado actual). */
    suspend fun detail(uuid: String): JsonObject = api.get("/observations/$uuid").jsonObject

    private fun parse(s: String): JsonObject? = runCatching { api.json.parseToJsonElement(s).jsonObject }.getOrNull()
}
