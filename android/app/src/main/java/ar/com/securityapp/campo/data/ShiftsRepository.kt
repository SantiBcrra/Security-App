package ar.com.securityapp.campo.data

import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put
import java.time.Instant
import java.util.UUID
import kotlin.math.atan2
import kotlin.math.cos
import kotlin.math.sin
import kotlin.math.sqrt

/** Ajustes de turnos de la empresa (`sync/pull.meta.turnos`). */
data class ShiftSettings(val active: Boolean, val toleranceM: Int, val outMinutes: Int, val noReasonMinutes: Int, val earlyMinutes: Int,
                         val graceMinutes: Int, val reasons: List<Pair<String, String>>) {
    companion object {
        val DEFAULT_REASONS = listOf("robo" to "Persecución / robo", "comisaria" to "Comisaría / denuncia", "medica" to "Emergencia médica",
            "encargado" to "Me lo pidió el encargado", "servicio" to "Trámite del servicio", "otro" to "Otro")

        fun from(prefs: Prefs, api: ApiClient): ShiftSettings {
            val t = prefs.metaJson?.let { runCatching { api.json.parseToJsonElement(it).jsonObject.obj("turnos") }.getOrNull() }
            val reasons = t?.obj("motivos")?.map { (k, v) -> k to ((v as? JsonPrimitive)?.content ?: k) }?.takeIf { it.isNotEmpty() } ?: DEFAULT_REASONS
            return ShiftSettings(
                active = t?.let { it["activas"] == null || it.bool("activas") } ?: true,
                toleranceM = t?.int("tolerancia_m")?.takeIf { it >= 0 } ?: 100,
                outMinutes = t?.int("minutos_fuera")?.takeIf { it > 0 } ?: 3,
                noReasonMinutes = t?.int("minutos_sin_motivo")?.takeIf { it > 0 } ?: 10,
                earlyMinutes = t?.int("inicio_anticipado_minutos")?.takeIf { it > 0 } ?: 60,
                graceMinutes = t?.int("gracia_minutos")?.takeIf { it > 0 } ?: 15,
                reasons = reasons,
            )
        }
    }
}

data class Site(val uuid: String, val name: String, val lat: Double?, val lng: Double?, val radiusM: Int)

/** Turno de trabajo del guardia (lo genera el servidor según la programación; o uno "no programado" que empezó él). */
data class GuardShift(val uuid: String, val status: String, val siteUuid: String, val siteName: String, val startAt: Instant, val endAt: Instant,
                      val graceMinutes: Int, val startedAt: Instant?, val unscheduled: Boolean, val name: String?) {
    val running get() = status == "en_curso"
    /** Se puede empezar desde `early` min antes hasta el fin (también si tenía ausencia aprobada y vino igual). */
    fun canStart(earlyMinutes: Int, now: Instant = Instant.now()) =
        (status == "programado" || status == "justificado") && !now.isBefore(startAt.minusSeconds(earlyMinutes * 60L)) && now.isBefore(endAt)
    fun late(now: Instant = Instant.now()) = status == "programado" && now.isAfter(startAt.plusSeconds(graceMinutes * 60L))
}

/** Salida del predio durante el turno (local hasta que el servidor la confirma). */
data class ShiftExit(val uuid: String, val slotUuid: String, val leftAt: Instant, val reason: String?, val note: String?, val returnedAt: Instant?,
                     val announced: Boolean, val notifiedAt: Long)

data class Absence(val uuid: String, val dateFrom: String, val dateTo: String, val type: String, val typeLabel: String, val reason: String?,
                   val status: String, val decisionNote: String?, val local: Boolean)

/**
 * Turnos sin señal: empezar, terminar, salidas del predio y pedidos de ausencia se guardan en el celular y se encolan
 * (`shift.*`, `absence.request`). El área del predio se toma de la entidad `sites` (el administrador la puede corregir).
 */
class ShiftsRepository(private val db: LocalDb, private val outbox: Outbox, private val api: ApiClient, private val prefs: Prefs) {

    fun settings() = ShiftSettings.from(prefs, api)

    private fun parse(raw: String): JsonObject? = runCatching { api.json.parseToJsonElement(raw).jsonObject }.getOrNull()
    private fun dbl(o: JsonObject, k: String): Double? = (o[k] as? JsonPrimitive)?.content?.toDoubleOrNull()
    private fun instant(o: JsonObject, k: String): Instant? = o.strOrNull(k)?.let { runCatching { Instant.parse(it) }.getOrNull() }

    fun sites(): List<Site> = db.all("sites").mapNotNull { parse(it) }.map {
        Site(it.str("uuid"), it.str("name"), dbl(it, "lat"), dbl(it, "lng"), it.int("radius_m").takeIf { r -> r > 0 } ?: 200)
    }.sortedBy { it.name.lowercase() }

    /** Área del predio: la de `sites` (vigente); si no llegó, la que vino con el turno. */
    fun site(uuid: String, fallback: JsonObject? = null): Site? = sites().firstOrNull { it.uuid == uuid }
        ?: fallback?.let { Site(uuid, it.str("site_name"), dbl(it, "site_lat"), dbl(it, "site_lng"), it.int("site_radius_m").takeIf { r -> r > 0 } ?: 200) }

    private fun shift(o: JsonObject): GuardShift? = runCatching {
        GuardShift(o.str("uuid"), o.str("status"), o.str("site_uuid"), o.str("site_name"), Instant.parse(o.str("start_at")), Instant.parse(o.str("end_at")),
            o.int("grace_minutes").takeIf { it > 0 } ?: 15, instant(o, "started_at"), o.bool("unscheduled"), o.strOrNull("name"))
    }.getOrNull()

    fun shifts(): List<GuardShift> = db.all("guard_shift_slots").mapNotNull { parse(it)?.let(::shift) }
        .filter { it.status in setOf("programado", "en_curso", "justificado") }.sortedBy { it.startAt }

    fun current(): GuardShift? = shifts().firstOrNull { it.running }

    /** Próximos turnos (los que todavía no terminaron), del más cercano al más lejano. */
    fun upcoming(now: Instant = Instant.now()): List<GuardShift> = shifts().filter { !it.running && it.endAt.isAfter(now) }

    fun siteOf(s: GuardShift): Site? = site(s.siteUuid, db.get("guard_shift_slots", s.uuid)?.let { parse(it) })

    /**
     * ¿Está en el predio? true = adentro, false = afuera, null = no se sabe (sin área cargada).
     * Con precisión mala no se lo acusa: afuera solo si aun restando el error queda fuera del radio + margen.
     */
    fun inside(site: Site?, fix: Fix): Boolean? {
        if (site?.lat == null || site.lng == null) return null
        val limit = site.radiusM + settings().toleranceM
        return distanceM(fix.lat, fix.lng, site.lat, site.lng) - fix.accuracyM <= limit
    }

    fun distanceTo(site: Site?, fix: Fix): Double? = if (site?.lat == null || site.lng == null) null else distanceM(fix.lat, fix.lng, site.lat, site.lng)

    // ── empezar / terminar ────────────────────────────────────────

    /** @param shift turno programado; null = turno no programado en `site` */
    fun start(shift: GuardShift?, site: Site?, fix: Fix?): GuardShift {
        current()?.let { return it }
        val now = Instant.now()
        val uuid = shift?.uuid ?: UUID.randomUUID().toString()
        val base = shift?.let { db.get("guard_shift_slots", it.uuid)?.let(::parse) }
        val row = buildJsonObject {
            base?.forEach { (k, v) -> put(k, v) }
            put("uuid", uuid); put("status", "en_curso"); put("started_at", now.toString()); put("local", true)
            if (shift == null && site != null) {
                put("site_uuid", site.uuid); put("site_name", site.name); put("unscheduled", true); put("grace_minutes", settings().graceMinutes)
                put("start_at", now.toString()); put("end_at", now.plusSeconds(12 * 3600L).toString())
                site.lat?.let { put("site_lat", it) }; site.lng?.let { put("site_lng", it) }; put("site_radius_m", site.radiusM)
            }
        }
        db.upsert("guard_shift_slots", listOf(uuid to row.toString()))
        outbox.enqueue("shift.start", buildJsonObject {
            put("uuid", uuid); put("started_at_device", now.toString())
            if (shift == null) site?.let { put("site_uuid", it.uuid) }
            fix?.let { put("lat", it.lat); put("lng", it.lng); put("accuracy_m", it.accuracyM.toDouble()); if (it.mock) put("mock", 1) }
        })
        return shift(row)!!
    }

    fun end(shift: GuardShift) {
        val now = Instant.now()
        openExit()?.let { returned(it, now) }
        db.get("guard_shift_slots", shift.uuid)?.let(::parse)?.let { o ->
            db.upsert("guard_shift_slots", listOf(shift.uuid to JsonObject(o + mapOf("status" to JsonPrimitive("cumplido"), "ended_at" to JsonPrimitive(now.toString()))).toString()))
        }
        outbox.enqueue("shift.end", buildJsonObject { put("slot_uuid", shift.uuid); put("ended_at_device", now.toString()) })
    }

    // ── salidas del predio ────────────────────────────────────────

    private fun exit(o: JsonObject): ShiftExit? = runCatching {
        ShiftExit(o.str("uuid"), o.str("slot_uuid"), Instant.parse(o.str("left_at")), o.strOrNull("reason"), o.strOrNull("note"), instant(o, "returned_at"),
            o.bool("announced"), (o["notified_at"] as? JsonPrimitive)?.content?.toLongOrNull() ?: 0L)
    }.getOrNull()

    fun exits(): List<ShiftExit> = db.all(EXITS).mapNotNull { parse(it)?.let(::exit) }.sortedBy { it.leftAt }

    fun openExit(): ShiftExit? = current()?.let { s -> exits().lastOrNull { it.slotUuid == s.uuid && it.returnedAt == null } }

    private fun saveExit(e: ShiftExit) {
        db.upsert(EXITS, listOf(e.uuid to buildJsonObject {
            put("uuid", e.uuid); put("slot_uuid", e.slotUuid); put("left_at", e.leftAt.toString()); e.reason?.let { put("reason", it) }
            e.note?.let { put("note", it) }; e.returnedAt?.let { put("returned_at", it.toString()) }; put("announced", e.announced); put("notified_at", e.notifiedAt.toString())
        }.toString()))
    }

    /** Salió (lo detectó el servicio, o el guardia avisó "Salgo por una emergencia" con motivo). */
    fun exit(shift: GuardShift, leftAt: Instant, fix: Fix?, reason: String? = null, note: String? = null, announced: Boolean = false): ShiftExit {
        openExit()?.let { return it }
        val e = ShiftExit(UUID.randomUUID().toString(), shift.uuid, leftAt, reason, note?.takeIf { it.isNotBlank() }, null, announced, 0L)
        saveExit(e)
        outbox.enqueue("shift.exit", buildJsonObject {
            put("uuid", e.uuid); put("slot_uuid", shift.uuid); put("left_at_device", leftAt.toString())
            fix?.let { put("lat", it.lat); put("lng", it.lng); put("accuracy_m", it.accuracyM.toDouble()) }
            reason?.let { put("reason", it) }; e.note?.let { put("note", it) }; if (announced) put("announced", 1)
        })
        return e
    }

    fun setReason(e: ShiftExit, reason: String, note: String?) {
        if (e.reason != null) return
        saveExit(e.copy(reason = reason, note = note?.takeIf { it.isNotBlank() }))
        outbox.enqueue("shift.exit_reason", buildJsonObject { put("uuid", e.uuid); put("reason", reason); note?.takeIf { it.isNotBlank() }?.let { put("note", it) } })
    }

    fun returned(e: ShiftExit, at: Instant = Instant.now()) {
        if (e.returnedAt != null) return
        saveExit(e.copy(returnedAt = at))
        outbox.enqueue("shift.return", buildJsonObject { put("uuid", e.uuid); put("returned_at_device", at.toString()) })
    }

    fun markNotified(e: ShiftExit) = saveExit(e.copy(notifiedAt = System.currentTimeMillis()))

    /** Posición mientras está fuera del predio (lo único que se guarda del recorrido). */
    fun position(e: ShiftExit, fix: Fix, at: Instant) {
        outbox.enqueue("shift.track", buildJsonObject {
            put("slot_uuid", e.slotUuid); put("exit_uuid", e.uuid)
            put("points", kotlinx.serialization.json.buildJsonArray {
                add(buildJsonObject { put("uuid", UUID.randomUUID().toString()); put("at", at.toString()); put("lat", fix.lat); put("lng", fix.lng); put("accuracy_m", fix.accuracyM.toDouble()) })
            })
        })
    }

    // ── ausencias ────────────────────────────────────────────────

    fun absences(): List<Absence> = db.all("guard_absences").mapNotNull { parse(it) }.map {
        Absence(it.str("uuid"), it.str("date_from"), it.str("date_to"), it.str("type"), it.strOrNull("type_label") ?: it.str("type"), it.strOrNull("reason"),
            it.str("status"), it.strOrNull("decision_note"), it.bool("local"))
    }.sortedByDescending { it.dateFrom }

    fun requestAbsence(dateFrom: String, dateTo: String, type: String, typeLabel: String, reason: String?) {
        val uuid = UUID.randomUUID().toString()
        db.upsert("guard_absences", listOf(uuid to buildJsonObject {
            put("uuid", uuid); put("date_from", dateFrom); put("date_to", dateTo); put("type", type); put("type_label", typeLabel)
            reason?.takeIf { it.isNotBlank() }?.let { put("reason", it) }; put("status", "solicitada"); put("local", true)
        }.toString()))
        outbox.enqueue("absence.request", buildJsonObject {
            put("uuid", uuid); put("date_from", dateFrom); put("date_to", dateTo); put("type", type); reason?.takeIf { it.isNotBlank() }?.let { put("reason", it) }
        })
    }

    suspend fun cancelAbsence(uuid: String) {
        api.post("/absences/$uuid/cancel")
        db.get("guard_absences", uuid)?.let(::parse)?.let { o ->
            db.upsert("guard_absences", listOf(uuid to JsonObject(o + ("status" to JsonPrimitive("cancelada"))).toString()))
        }
    }

    /** Historial de los últimos días con horas (necesita señal). */
    suspend fun history(): JsonObject = api.get("/shifts/mine").jsonObject

    companion object {
        const val EXITS = "shift_exits_local"
        val ABSENCE_TYPES = listOf("franco" to "Franco / cambio de turno", "enfermedad" to "Enfermedad", "vacaciones" to "Vacaciones",
            "licencia" to "Licencia", "personal" to "Motivo personal", "otro" to "Otro")

        fun distanceM(lat1: Double, lng1: Double, lat2: Double, lng2: Double): Double {
            val r = 6_371_000.0
            val dLat = Math.toRadians(lat2 - lat1)
            val dLng = Math.toRadians(lng2 - lng1)
            val a = sin(dLat / 2) * sin(dLat / 2) + cos(Math.toRadians(lat1)) * cos(Math.toRadians(lat2)) * sin(dLng / 2) * sin(dLng / 2)
            return 2 * r * atan2(sqrt(a), sqrt(1 - a))
        }
    }
}
