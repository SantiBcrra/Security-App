package ar.com.securityapp.campo.data

import android.net.Uri
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.doubleOrNull
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import kotlinx.serialization.json.put
import kotlinx.serialization.json.putJsonObject
import java.time.Instant
import java.util.UUID

/** Ítem de un checklist (misma estructura que `InspectionStructure` del servidor). */
data class CheckItem(val key: String, val text: String, val help: String?, val type: String, val okWhen: String?, val min: Double?, val max: Double?,
                     val unit: String?, val critical: Boolean, val photo: String)

data class CheckSection(val title: String, val items: List<CheckItem>)

data class Checklist(val uuid: String, val name: String, val description: String?, val scope: String, val typeUuid: String?, val versionUuid: String,
                     val sections: List<CheckSection>) {
    val items get() = sections.flatMap { it.items }
}

/** Inspección programada a mi cargo (pendiente). */
data class Scheduled(val uuid: String, val templateUuid: String, val equipmentUuid: String?, val sectorUuid: String?, val program: String,
                     val dueFrom: String, val dueOn: String)

data class Answer(val value: String? = null, val comment: String = "", val photos: List<Uri> = emptyList())

/** Lo que el celular calcula para avisar antes de enviar (el resultado oficial lo calcula SIEMPRE el servidor). */
data class Evaluation(val errors: Map<String, String>, val ok: Map<String, Boolean?>, val fail: Int, val criticalFail: Int) {
    val result get() = if (criticalFail > 0) "No conforme (crítico)" else if (fail > 0) "Con observaciones" else "Conforme"
}

data class MyInspection(val uuid: String, val template: String, val target: String, val doneAt: String, val local: Boolean, val code: String?,
                        val resultLabel: String?, val score: Int?, val actions: Int, val failure: String?)

/**
 * Inspecciones y checklists sin señal: la app baja las plantillas que el usuario puede hacer y sus programadas; la
 * inspección se guarda en el celular y sale como `inspection.create` (uuid del celular, idempotente) con
 * `photo_counts`; las fotos de cada ítem se suben después por partes (`item_key`).
 */
class InspectionsRepository(private val db: LocalDb, private val outbox: Outbox, private val uploads: Uploads, private val api: ApiClient) {

    fun checklists(): List<Checklist> = db.all("inspection_templates").mapNotNull { parse(it) }.mapNotNull { t ->
        val version = t.strOrNull("version_uuid") ?: return@mapNotNull null
        val sections = t.obj("structure")?.arr("sections").orEmpty().mapNotNull { it as? JsonObject }.map { s ->
            CheckSection(s.str("title"), s.arr("items").mapNotNull { it as? JsonObject }.map { i ->
                CheckItem(i.str("key"), i.str("text"), i.strOrNull("help"), i.str("type"), i.strOrNull("ok_when"), i.num("min"), i.num("max"),
                    i.strOrNull("unit"), i.bool("critical"), i.strOrNull("photo") ?: "nunca")
            })
        }
        Checklist(t.str("uuid"), t.str("name"), t.strOrNull("description"), t.str("scope"), t.strOrNull("type_uuid"), version, sections)
    }.sortedBy { it.name.lowercase() }

    /** Checklists que aplican a un equipo (por su tipo). */
    fun forEquipment(eq: Equipment, typeUuid: String?): List<Checklist> = checklists().filter { it.scope == "equipo" && it.typeUuid == typeUuid }

    fun equipmentType(uuid: String): String? = db.all("equipment").mapNotNull { parse(it) }.firstOrNull { it.str("uuid") == uuid }?.strOrNull("type_uuid")

    fun scheduled(): List<Scheduled> = db.all("inspection_schedule").mapNotNull { parse(it) }.map { s ->
        Scheduled(s.str("uuid"), s.str("template_uuid"), s.strOrNull("equipment_uuid"), s.strOrNull("sector_uuid"), s.str("program"),
            s.str("due_from"), s.str("due_on"))
    }.sortedWith(compareBy({ it.dueOn }, { it.program }))

    fun mine(): List<MyInspection> {
        val failed = db.ops("failed").filter { it.type == "inspection.create" }
        return db.all(LOCAL).mapNotNull { parse(it) }.map { i ->
            MyInspection(i.str("uuid"), i.str("template"), i.str("target"), i.str("done_at"), i.bool("local"), i.strOrNull("code"),
                i.strOrNull("result_label"), i.strOrNull("score")?.toIntOrNull(), i.int("actions"),
                failed.firstOrNull { it.data.contains(i.str("uuid")) }?.error)
        }.sortedByDescending { it.doneAt }
    }

    /** Misma regla que el servidor: qué cumple, qué falta (comentario si no cumple, fotos obligatorias). */
    fun evaluate(c: Checklist, answers: Map<String, Answer>): Evaluation {
        val errors = linkedMapOf<String, String>()
        val ok = mutableMapOf<String, Boolean?>()
        var fail = 0
        var critical = 0
        for (item in c.items) {
            val a = answers[item.key] ?: Answer()
            val value = a.value?.trim()?.ifEmpty { null }
            var res: Boolean? = null
            when (item.type) {
                "si_no", "si_no_na" -> if (value == null) errors[item.key] = "Respondé: ${item.text}" else res = if (value == "na") null else value == item.okWhen
                "numero" -> {
                    val n = value?.replace(',', '.')?.toDoubleOrNull()
                    if (n == null) errors[item.key] = "Cargá un número: ${item.text}"
                    else res = !((item.min != null && n < item.min) || (item.max != null && n > item.max))
                }
            }
            ok[item.key] = res
            if (res == false && a.comment.isBlank()) errors[item.key] = "Contá qué pasa en «${item.text}» (no cumple)."
            if ((item.photo == "siempre" || (item.photo == "si_no_cumple" && res == false)) && a.photos.isEmpty() && item.key !in errors) {
                errors[item.key] = "Falta la foto de «${item.text}»."
            }
            if (res == false) { fail++; if (item.critical) critical++ }
        }
        return Evaluation(errors, ok, fail, critical)
    }

    /** Guarda en el celular y encola. @return uuid */
    suspend fun create(c: Checklist, equipment: String?, sector: String?, schedule: String?, answers: Map<String, Answer>, target: String): String {
        val uuid = UUID.randomUUID().toString()
        val doneAt = Instant.now().toString()
        for (item in c.items) for (photo in answers[item.key]?.photos.orEmpty()) uploads.add(photo, "inspection", uuid, item.key)
        outbox.enqueue("inspection.create", buildJsonObject {
            put("uuid", uuid); put("template", c.uuid); put("version", c.versionUuid); put("done_at", doneAt)
            equipment?.let { put("equipment", it) }; sector?.let { put("sector", it) }; schedule?.let { put("schedule", it) }
            putJsonObject("answers") {
                for (item in c.items) {
                    val a = answers[item.key] ?: continue
                    putJsonObject(item.key) { a.value?.let { put("value", it) }; if (a.comment.isNotBlank()) put("comment", a.comment.trim()) }
                }
            }
            putJsonObject("photo_counts") { for (item in c.items) answers[item.key]?.photos?.size?.takeIf { it > 0 }?.let { put(item.key, it) } }
        })
        db.upsert(LOCAL, listOf(uuid to buildJsonObject {
            put("uuid", uuid); put("template", c.name); put("target", target); put("done_at", doneAt); put("local", true)
        }.toString()))
        schedule?.let { db.delete("inspection_schedule", listOf(it)) } // ya no está "para hacer"
        return uuid
    }

    /** Respuesta del servidor a `inspection.create`: número y resultado oficial. */
    fun onPushed(op: LocalDb.Op, res: JsonObject) {
        if (res.str("status") != "ok") return
        val d = res.obj("data") ?: return
        val uuid = d.str("uuid")
        val row = db.all(LOCAL).mapNotNull { parse(it) }.firstOrNull { it.str("uuid") == uuid } ?: return
        db.upsert(LOCAL, listOf(uuid to JsonObject(row + buildJsonObject {
            put("local", false); put("code", d.str("code")); put("result_label", d.str("result_label"))
            d.strOrNull("score")?.let { put("score", it) }; put("actions", d.int("actions"))
        }).toString()))
    }

    suspend fun detail(uuid: String): JsonObject = api.get("/inspections/$uuid").jsonObject

    private fun JsonObject.num(key: String): Double? = runCatching { this[key]?.jsonPrimitive?.doubleOrNull }.getOrNull()

    private fun parse(s: String): JsonObject? = runCatching { api.json.parseToJsonElement(s).jsonObject }.getOrNull()

    private companion object {
        /** Inspecciones hechas desde este celular (el pull no las baja: el historial está en la web). */
        const val LOCAL = "my_inspections"
    }
}
