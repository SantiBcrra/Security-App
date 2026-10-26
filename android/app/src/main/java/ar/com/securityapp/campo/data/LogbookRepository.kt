package ar.com.securityapp.campo.data

import android.net.Uri
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put
import java.time.Instant
import java.util.UUID

/** Anotación del libro de guardia (misma forma que `Logbook::shape` del servidor). `local` = todavía no llegó al servidor. */
data class LogEntry(val uuid: String, val siteUuid: String, val folio: Int?, val kind: String, val category: String?, val text: String?, val important: Boolean,
                    val label: String, val personName: String?, val personDoc: String?, val company: String?, val visiting: String?, val plate: String?,
                    val occurredAt: Instant, val userName: String?, val exitedAt: Instant?, val refUuid: String?, val received: Boolean, val mine: Boolean,
                    val local: Boolean, val data: JsonObject?, val authStatus: String? = null) {
    val isEntry get() = kind == "persona" || kind == "vehiculo"
}

/** Lo que ya vino antes (padrón del servidor), para autocompletar por DNI o patente. */
data class KnownVisitor(val type: String, val key: String, val name: String?, val doc: String?, val company: String?, val plate: String?, val vehicleType: String?,
                        val driver: String?, val visits: Int)

/** Autorización vigente o histórica que bajó la sincronización para el predio. */
data class AuthorizedVisitor(val uuid: String, val type: String, val siteUuid: String?, val name: String?, val doc: String?,
                             val company: String?, val plate: String?, val reason: String?, val validFrom: String?, val validUntil: String?,
                             val days: List<Int>, val timeFrom: String?, val timeTo: String?) {
    fun status(now: java.time.ZonedDateTime = java.time.ZonedDateTime.now()): String {
        val day = now.toLocalDate().toString()
        val wd = now.dayOfWeek.value
        val hm = "%02d:%02d".format(now.hour, now.minute)
        return when {
            (validFrom != null && day < validFrom) || (validUntil != null && day > validUntil) -> "vencido"
            days.isNotEmpty() && wd !in days -> "fuera_horario"
            timeFrom != null && timeTo != null && !(if (timeFrom <= timeTo) hm in timeFrom..timeTo else hm >= timeFrom || hm <= timeTo) -> "fuera_horario"
            else -> "autorizado"
        }
    }

    val label: String
        get() = if (type == "vehiculo") listOfNotNull(plate, company).joinToString(" · ")
        else listOfNotNull(name, doc?.let { "DNI $it" }, company).joinToString(" · ")
}

/**
 * Libro de guardia sin señal: cada anotación se guarda en el celular (entidad `logbook_entries`, `local: true`) y se encola
 * (`logbook.create|exit|close|receive`); el pull la reemplaza por la del servidor (con folio). Las fotos se suben después de
 * que la anotación llegó (`uploads` con target `logbook_entry`).
 */
class LogbookRepository(private val db: LocalDb, private val outbox: Outbox, private val uploads: Uploads, private val api: ApiClient, private val prefs: Prefs) {

    companion object {
        const val ENTITY = "logbook_entries"
        /** "riesgo" = condición insegura (cable pelado, fuga de gas…): el servidor avisa al instante y abre una observación. */
        val CATEGORIES = listOf("riesgo" to "⚠ Condición insegura / riesgo", "rutina" to "Rutina / sin novedad", "incidente" to "Incidente", "alarma" to "Alarma", "mantenimiento" to "Mantenimiento / falla",
            "llamada" to "Llamada / aviso", "entrega" to "Entrega / paquete", "otro" to "Otro")
        val VEHICLE_TYPES = listOf("auto" to "Auto", "camioneta" to "Camioneta", "camion" to "Camión", "utilitario" to "Utilitario", "moto" to "Moto", "otro" to "Otro")
        val KINDS = mapOf("novedad" to "Novedad", "persona" to "Ingreso de persona", "vehiculo" to "Ingreso de vehículo", "salida" to "Salida",
            "correccion" to "Corrección", "cierre" to "Cierre de turno", "recepcion" to "Recepción del puesto")

        val AUTH_LABELS = mapOf("autorizado" to "Autorizado", "fuera_horario" to "Fuera del día u horario autorizado",
            "vencido" to "Autorización vencida o todavía no vigente", "no_autorizado" to "No está en la lista de autorizados")
        fun plate(v: String?): String? = v?.uppercase()?.filter { it.isLetterOrDigit() }?.take(15)?.ifEmpty { null }
        fun doc(v: String?): String? = v?.uppercase()?.filter { it.isLetterOrDigit() }?.take(30)?.ifEmpty { null }
    }

    private fun parse(raw: String): JsonObject? = runCatching { api.json.parseToJsonElement(raw).jsonObject }.getOrNull()
    private fun instant(o: JsonObject, k: String): Instant? = o.strOrNull(k)?.let { runCatching { Instant.parse(it) }.getOrNull() }

    private fun entry(o: JsonObject): LogEntry? = runCatching {
        LogEntry(o.str("uuid"), o.str("site_uuid"), (o["folio"] as? JsonPrimitive)?.content?.toIntOrNull(), o.str("kind"), o.strOrNull("category"), o.strOrNull("text"),
            o.bool("important"), o.strOrNull("label") ?: "", o.strOrNull("person_name"), o.strOrNull("person_doc"), o.strOrNull("company"), o.strOrNull("visiting"),
            o.strOrNull("plate"), instant(o, "occurred_at")!!, o.strOrNull("user_name"), instant(o, "exited_at"), o.strOrNull("ref_uuid"), o.bool("received"),
            o.bool("mine"), o.bool("local"), o.obj("data"), o.strOrNull("auth_status"))
    }.getOrNull()

    // ── Lista de autorizados (baja con la sincronización; el control es el mismo del servidor) ──

    data class AuthResult(val status: String, val who: String?, val window: String?) {
        val ok get() = status == "autorizado"
        val label get() = AUTH_LABELS[status] ?: status
    }

    /**
     * ¿Está autorizado este ingreso ahora? null = el predio no tiene a nadie de ese tipo en la lista (no se controla) o todavía
     * no se escribió nada. Mismas reglas que `LogbookAuthorized::check` (DNI, o nombre exacto si no tiene DNI; patente; predio o
     * todos; vigencia; días; horario).
     */
    fun checkAuth(siteUuid: String, kind: String, doc: String?, name: String?, plate: String?, now: java.time.ZonedDateTime = java.time.ZonedDateTime.now()): AuthResult? {
        val list = db.all("logbook_authorized").mapNotNull { parse(it) }
            .filter { it.str("kind") == kind && (it.strOrNull("site_uuid") == null || it.strOrNull("site_uuid") == siteUuid) }
        if (list.isEmpty()) return null
        val d = doc(doc); val p = plate(plate); val n = name?.trim()?.takeIf { it.isNotEmpty() }
        if ((kind == "vehiculo" && p == null) || (kind != "vehiculo" && d == null && n == null)) return null
        val cand = if (kind == "vehiculo") list.filter { it.strOrNull("plate") == p }
            else list.filter { d != null && it.strOrNull("person_doc") == d }
                .ifEmpty { list.filter { n != null && it.strOrNull("person_doc") == null && it.strOrNull("person_name").equals(n, ignoreCase = true) } }
        if (cand.isEmpty()) return AuthResult("no_autorizado", null, null)
        val day = now.toLocalDate().toString()
        val wd = now.dayOfWeek.value
        val hm = "%02d:%02d".format(now.hour, now.minute)
        var best: AuthResult? = null
        for (a in cand) {
            val days = a.arr("days").mapNotNull { (it as? JsonPrimitive)?.content?.toIntOrNull() }
            val from = a.strOrNull("valid_from"); val until = a.strOrNull("valid_until")
            val tf = a.strOrNull("time_from"); val tt = a.strOrNull("time_to")
            val status = when {
                (from != null && day < from) || (until != null && day > until) -> "vencido"
                days.isNotEmpty() && wd !in days -> "fuera_horario"
                tf != null && tt != null && !(if (tf <= tt) hm in tf..tt else hm >= tf || hm <= tt) -> "fuera_horario"
                else -> "autorizado"
            }
            val who = listOfNotNull(a.strOrNull("person_name") ?: a.strOrNull("plate"), a.strOrNull("company")).joinToString(" · ")
            val window = listOfNotNull(
                days.takeIf { it.isNotEmpty() && it.size < 7 }?.joinToString(", ") { listOf("Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom")[it - 1] },
                tf?.let { "$it a $tt" }, until?.let { "hasta ${it.substring(8, 10)}/${it.substring(5, 7)}/${it.substring(0, 4)}" }).joinToString(" · ").ifEmpty { "siempre" }
            val r = AuthResult(status, who, window)
            if (r.ok) return r
            if (best == null) best = r
        }
        return best
    }

    /** Autorizaciones del predio actual, también disponibles sin señal. */
    fun authorized(siteUuid: String, kind: String): List<AuthorizedVisitor> = db.all("logbook_authorized").mapNotNull { raw ->
        parse(raw)?.takeIf { it.str("kind") == kind && (it.strOrNull("site_uuid") == null || it.strOrNull("site_uuid") == siteUuid) }?.let { o ->
            AuthorizedVisitor(o.str("uuid"), kind, o.strOrNull("site_uuid"), o.strOrNull("person_name"), o.strOrNull("person_doc"),
                o.strOrNull("company"), o.strOrNull("plate"), o.strOrNull("reason"), o.strOrNull("valid_from"), o.strOrNull("valid_until"),
                o.arr("days").mapNotNull { (it as? JsonPrimitive)?.content?.toIntOrNull() }, o.strOrNull("time_from"), o.strOrNull("time_to"))
        }
    }.sortedWith(compareBy<AuthorizedVisitor> { it.status() != "autorizado" }.thenBy { it.label.lowercase() })

    fun all(siteUuid: String): List<LogEntry> = db.all(ENTITY).mapNotNull { parse(it)?.let(::entry) }.filter { it.siteUuid == siteUuid }
        .sortedByDescending { it.occurredAt }

    fun find(uuid: String): LogEntry? = db.get(ENTITY, uuid)?.let(::parse)?.let(::entry)

    /** Personas y vehículos que entraron y no salieron. */
    fun inside(siteUuid: String): List<LogEntry> = all(siteUuid).filter { it.isEntry && it.exitedAt == null }.sortedBy { it.occurredAt }

    /** Último cierre del predio que nadie recibió (de otro guardia, últimas 24 h). */
    fun pendingClose(siteUuid: String): LogEntry? = all(siteUuid).firstOrNull { it.kind == "cierre" && !it.received && !it.mine
        && it.occurredAt.isAfter(Instant.now().minusSeconds(24 * 3600)) }

    // ── predio ───────────────────────────────────────────────────

    /** Predio del libro: el del turno en curso, el último elegido o el único. */
    fun site(sites: List<Site>, shiftSite: String?): Site? =
        sites.firstOrNull { it.uuid == shiftSite } ?: sites.firstOrNull { it.uuid == prefs.logbookSite } ?: sites.singleOrNull()

    fun chooseSite(uuid: String) { prefs.logbookSite = uuid }

    // ── autocompletar ────────────────────────────────────────────

    fun lookup(type: String, prefix: String): List<KnownVisitor> {
        val key = (if (type == "vehiculo") plate(prefix) else doc(prefix)) ?: return emptyList()
        if (key.length < 2) return emptyList()
        return db.all("logbook_known").mapNotNull { parse(it) }.filter { it.str("type") == type && it.str("key").startsWith(key) }
            .sortedByDescending { it.str("last_seen_at") }.take(6).map {
                KnownVisitor(it.str("type"), it.str("key"), it.strOrNull("name"), it.strOrNull("doc"), it.strOrNull("company"), it.strOrNull("plate"),
                    it.strOrNull("vehicle_type"), it.strOrNull("driver"),
                    (it["visits"] as? JsonPrimitive)?.content?.toIntOrNull() ?: 1)
            }
    }

    // ── anotar ───────────────────────────────────────────────────

    private fun label(kind: String, f: Map<String, String?>): String = when (kind) {
        "vehiculo" -> listOfNotNull(f["plate"], VEHICLE_TYPES.toMap()[f["vehicle_type"]], f["driver"], f["company"]).joinToString(" · ")
        "persona" -> listOfNotNull(listOfNotNull(f["person_name"], f["person_doc"]?.let { "(DNI $it)" }).joinToString(" ").ifEmpty { null }, f["company"]).joinToString(" · ")
        else -> ""
    }

    private fun saveLocal(uuid: String, siteUuid: String, kind: String, fields: Map<String, String?>, extra: Map<String, JsonElement> = emptyMap()) {
        val me = runCatching { prefs.meJson?.let { api.json.parseToJsonElement(it).jsonObject.obj("usuario")?.str("nombre") } }.getOrNull()
        val row = buildJsonObject {
            put("uuid", uuid); put("site_uuid", siteUuid); put("kind", kind); put("occurred_at", Instant.now().toString()); put("mine", true); put("local", true)
            put("received", false); put("important", fields["important"] == "1"); put("label", label(kind, fields)); me?.let { put("user_name", it) }
            fields.forEach { (k, v) -> if (v != null && k != "important") put(k, v) }
            extra.forEach { (k, v) -> put(k, v) }
        }
        db.upsert(ENTITY, listOf(uuid to row.toString()))
    }

    /**
     * Novedad, persona o vehículo. Valida igual que el servidor (persona: nombre, DNI o empresa; vehículo: patente).
     * @return null si está bien, o el error para mostrar
     */
    suspend fun create(siteUuid: String, kind: String, input: Map<String, String?>, photos: List<Uri>, lat: Double? = null, lng: Double? = null): String? {
        val f = input.mapValues { (_, v) -> v?.trim()?.ifEmpty { null } }.toMutableMap()
        f["plate"] = plate(f["plate"])
        f["person_doc"] = doc(f["person_doc"])
        when (kind) {
            "novedad" -> if (f["text"] == null) return "Escribí la novedad."
            "persona" -> if (f["person_name"] == null && f["person_doc"] == null && f["company"] == null) return "Anotá al menos el nombre, el DNI o la empresa."
            "vehiculo" -> if (f["plate"] == null) return "Anotá la patente."
        }
        val uuid = UUID.randomUUID().toString()
        saveLocal(uuid, siteUuid, kind, f)
        outbox.enqueue("logbook.create", buildJsonObject {
            put("uuid", uuid); put("site_uuid", siteUuid); put("kind", kind); put("occurred_at", Instant.now().toString())
            f.forEach { (k, v) -> if (v != null) put(k, v) }
            lat?.let { put("lat", it) }; lng?.let { put("lng", it) }
        })
        for (p in photos) uploads.add(p, "logbook_entry", uuid)
        return null
    }

    fun exit(e: LogEntry, note: String? = null) {
        if (e.exitedAt != null) return
        val now = Instant.now()
        db.get(ENTITY, e.uuid)?.let(::parse)?.let { o ->
            db.upsert(ENTITY, listOf(e.uuid to JsonObject(o + ("exited_at" to JsonPrimitive(now.toString()))).toString()))
        }
        val uuid = UUID.randomUUID().toString()
        saveLocal(uuid, e.siteUuid, "salida", mapOf("text" to note), mapOf("ref_uuid" to JsonPrimitive(e.uuid)))
        outbox.enqueue("logbook.exit", buildJsonObject {
            put("uuid", uuid); put("entry_uuid", e.uuid); put("occurred_at", now.toString()); note?.takeIf { it.isNotBlank() }?.let { put("text", it) }
        })
    }

    /** Resumen local del tramo: desde mi último cierre (o el turno) — el definitivo lo calcula el servidor. */
    fun summary(siteUuid: String, since: Instant?): Pair<Map<String, Int>, List<LogEntry>> {
        val entries = all(siteUuid)
        val lastClose = entries.firstOrNull { it.kind == "cierre" && it.mine }?.occurredAt
        val from = listOfNotNull(lastClose, since).maxOrNull() ?: Instant.now().minusSeconds(24 * 3600)
        val mineSince = entries.filter { it.occurredAt.isAfter(from) }
        return mapOf("novedades" to mineSince.count { it.kind == "novedad" }, "importantes" to mineSince.count { it.kind == "novedad" && it.important },
            "personas" to mineSince.count { it.kind == "persona" }, "vehiculos" to mineSince.count { it.kind == "vehiculo" }) to inside(siteUuid)
    }

    fun close(siteUuid: String, text: String?, signature: String) {
        val uuid = UUID.randomUUID().toString()
        saveLocal(uuid, siteUuid, "cierre", mapOf("text" to (text?.trim()?.ifEmpty { null } ?: "Sin novedades.")))
        outbox.enqueue("logbook.close", buildJsonObject {
            put("uuid", uuid); put("site_uuid", siteUuid); put("occurred_at", Instant.now().toString()); text?.takeIf { it.isNotBlank() }?.let { put("text", it) }
            put("signature", signature)
        })
    }

    fun receive(close: LogEntry, text: String?, signature: String) {
        db.get(ENTITY, close.uuid)?.let(::parse)?.let { o -> db.upsert(ENTITY, listOf(close.uuid to JsonObject(o + ("received" to JsonPrimitive(true))).toString())) }
        val uuid = UUID.randomUUID().toString()
        saveLocal(uuid, close.siteUuid, "recepcion", mapOf("text" to (text?.trim()?.ifEmpty { null } ?: "Recibo el puesto de conformidad.")),
            mapOf("ref_uuid" to JsonPrimitive(close.uuid)))
        outbox.enqueue("logbook.receive", buildJsonObject {
            put("uuid", uuid); put("close_uuid", close.uuid); put("occurred_at", Instant.now().toString()); text?.takeIf { it.isNotBlank() }?.let { put("text", it) }
            put("signature", signature)
        })
    }

    /** ¿Está todavía en la cola (sin llegar al servidor)? */
    fun pending(uuid: String): Boolean = outbox.isPending(uuid)
}
