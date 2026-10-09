package ar.com.securityapp.campo.data

import android.net.Uri
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put
import java.time.Instant
import java.util.UUID

/** Tipos (mismas claves que `IncidentService::TYPES`). `injured` = hay que indicar quién se lastimó. */
data class IncidentType(val key: String, val label: String, val hint: String, val injured: Boolean, val serious: Boolean)

val INCIDENT_TYPES = listOf(
    IncidentType("accidente_con_baja", "Accidente con baja", "La persona no puede seguir trabajando", true, true),
    IncidentType("accidente_sin_baja", "Accidente sin baja", "Se lastimó pero sigue trabajando", true, false),
    IncidentType("in_itinere", "Accidente in itinere", "Yendo o volviendo del trabajo", true, true),
    IncidentType("enfermedad_profesional", "Enfermedad profesional", "Causada por el trabajo", true, false),
    IncidentType("incidente", "Incidente (daño material)", "Se rompió algo, nadie se lastimó", false, false),
    IncidentType("casi_accidente", "Casi-accidente", "Pudo pasar algo y no pasó", false, false),
)

val PERSON_ROLES = linkedMapOf("lesionado" to "Lesionado", "involucrado" to "Involucrado", "testigo" to "Testigo")

data class Employee(val uuid: String, val name: String, val sectorUuid: String?)

/** Persona del reporte: empleado (uuid) o externo (nombre, DNI, empresa). */
data class IncidentPerson(val role: String, val employee: Employee? = null, val externalName: String = "", val externalDni: String = "",
                          val externalCompany: String = "", val injury: String = "", val statement: String = "") {
    val label get() = employee?.name ?: externalName
}

data class IncidentDraft(val type: String, val occurredAt: Instant, val sector: String?, val equipment: String?, val location: String,
                         val description: String, val immediateActions: String, val severity: String?, val fix: Fix?,
                         val people: List<IncidentPerson>, val photos: List<Uri>)

data class Incident(val uuid: String, val code: String?, val type: String, val typeLabel: String, val status: String, val statusLabel: String,
                    val occurredAt: String, val description: String, val sectorUuid: String?, val local: Boolean, val failure: String?)

/**
 * Incidentes y accidentes reportados desde el celular, sin señal: `incident.create` (uuid del celular, idempotente) y fotos
 * por partes después. Al celular solo bajan los que reportó el usuario y nunca datos de salud.
 */
class IncidentsRepository(private val db: LocalDb, private val outbox: Outbox, private val uploads: Uploads, private val api: ApiClient) {

    fun employees(): List<Employee> = db.all("employees").mapNotNull { parse(it) }.map { Employee(it.str("uuid"), it.str("name"), it.strOrNull("sector_uuid")) }
        .sortedBy { it.name.lowercase() }

    fun list(): List<Incident> {
        val failed = db.ops("failed").filter { it.type == "incident.create" }
        return db.all("incidents").mapNotNull { parse(it) }.map { i ->
            val uuid = i.str("uuid")
            Incident(uuid, i.strOrNull("code"), i.str("type"), i.strOrNull("type_label") ?: INCIDENT_TYPES.firstOrNull { it.key == i.str("type") }?.label.orEmpty(),
                i.str("status"), i.str("status_label"), i.str("occurred_at"), i.str("description"), i.strOrNull("sector_uuid"), i.bool("local"),
                failed.firstOrNull { it.data.contains(uuid) }?.error)
        }.sortedByDescending { it.occurredAt }
    }

    suspend fun create(d: IncidentDraft): String {
        val uuid = UUID.randomUUID().toString()
        val at = d.occurredAt.toString()
        for (photo in d.photos) uploads.add(photo, "incident", uuid)
        outbox.enqueue("incident.create", buildJsonObject {
            put("uuid", uuid); put("type", d.type); put("occurred_at", at); put("description", d.description.trim())
            d.sector?.let { put("sector", it) }; d.equipment?.let { put("equipment", it) }; d.severity?.let { put("potential_severity", it) }
            if (d.location.isNotBlank()) put("location_text", d.location.trim())
            if (d.immediateActions.isNotBlank()) put("immediate_actions", d.immediateActions.trim())
            d.fix?.let { put("lat", "%.7f".format(java.util.Locale.US, it.lat)); put("lng", "%.7f".format(java.util.Locale.US, it.lng)) }
            put("people", buildJsonArray {
                for (p in d.people) add(buildJsonObject {
                    put("role", p.role)
                    if (p.employee != null) put("employee", p.employee.uuid)
                    else { put("external_name", p.externalName.trim()); put("external_dni", p.externalDni.trim()); put("external_company", p.externalCompany.trim()) }
                    if (p.role == "lesionado" && p.injury.isNotBlank()) put("injury_description", p.injury.trim())
                    if (p.role == "testigo" && p.statement.isNotBlank()) put("statement", p.statement.trim())
                })
            })
        })
        db.upsert("incidents", listOf(uuid to buildJsonObject {
            put("uuid", uuid); put("type", d.type); put("status", "pendiente"); put("status_label", "Pendiente de enviar"); put("occurred_at", at)
            put("description", d.description.trim()); d.sector?.let { put("sector_uuid", it) }; put("local", true)
        }.toString()))
        return uuid
    }

    /** Detalle con conexión (sin datos de salud). */
    suspend fun detail(uuid: String): JsonObject = api.get("/incidents/$uuid").jsonObject

    private fun parse(s: String): JsonObject? = runCatching { api.json.parseToJsonElement(s).jsonObject }.getOrNull()
}
