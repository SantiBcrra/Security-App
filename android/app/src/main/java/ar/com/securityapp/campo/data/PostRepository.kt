package ar.com.securityapp.campo.data

import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put
import java.time.Instant
import java.util.UUID
import javax.crypto.SecretKeyFactory
import javax.crypto.spec.PBEKeySpec

/** Guardia del grupo del puesto (de `sync/pull.meta.puesto.guardias`). */
data class PostGuard(val uuid: String, val name: String, val dniEnd: String?, val consent: Boolean,
                     val pinSalt: String?, val pinIterations: Int, val pinHash: String?) {
    val hasPin: Boolean get() = pinHash != null && pinSalt != null
}

data class PostInfo(val shiftUuid: String, val name: String, val siteUuid: String, val siteName: String, val describe: String)

/** Franja a cubrir del puesto. */
data class PostSpan(val uuid: String, val shiftUuid: String, val siteUuid: String, val startAt: Instant, val endAt: Instant, val graceMinutes: Int) {
    fun contains(t: Instant, earlyMinutes: Int) = !t.isBefore(startAt.minusSeconds(earlyMinutes * 60L)) && t.isBefore(endAt)
}

/** Quién está de turno ahora en el celular. */
data class OnDuty(val guardUuid: String, val guardName: String, val slotUuid: String?, val since: Instant?)

/**
 * Celular compartido del puesto: la empresa de seguridad tiene UN celular para varios guardias. La app queda abierta con la
 * cuenta del puesto; al empezar el turno cada guardia elige su nombre y pone su PIN (se verifica acá, también sin señal,
 * contra el PBKDF2 que baja la sincronización). Desde ese momento todo lo que se hace en la app se encola a su nombre
 * (`Outbox` agrega `as_user`). El que llega releva al que estaba (el servidor cierra el tramo anterior); el que se va puede
 * dejar el puesto "sin persona" con un motivo (viene el encargado, el dueño…).
 */
class PostRepository(private val db: LocalDb, private val outbox: Outbox, private val api: ApiClient, private val prefs: Prefs,
                     private val shifts: ShiftsRepository) {

    private fun meta(): JsonObject? = prefs.metaJson?.let { runCatching { api.json.parseToJsonElement(it).jsonObject.obj("puesto") }.getOrNull() }

    fun isConfigured(): Boolean = meta() != null

    fun posts(): List<PostInfo> = meta()?.arr("puestos")?.mapNotNull { (it as? JsonObject) }?.map {
        PostInfo(it.str("shift_uuid"), it.str("name"), it.str("site_uuid"), it.str("site_name"), it.str("describe"))
    }.orEmpty()

    fun guards(): List<PostGuard> = meta()?.arr("guardias")?.mapNotNull { (it as? JsonObject) }?.map {
        val pin = it.obj("pin")
        PostGuard(it.str("uuid"), it.str("name"), it.strOrNull("dni_end"), it.bool("consentimiento") || it.str("uuid") in prefs.consentedGuards,
            pin?.strOrNull("salt"), pin?.int("iterations") ?: 0, pin?.strOrNull("hash"))
    }.orEmpty()

    fun spans(): List<PostSpan> = meta()?.arr("franjas")?.mapNotNull { (it as? JsonObject) }?.mapNotNull {
        runCatching { PostSpan(it.str("uuid"), it.str("shift_uuid"), it.str("site_uuid"), Instant.parse(it.str("start_at")), Instant.parse(it.str("end_at")),
            it.int("grace_minutes").takeIf { g -> g > 0 } ?: 15) }.getOrNull()
    }.orEmpty().sortedBy { it.startAt }

    /** Franja vigente (o la que empieza dentro de la hora) del puesto. */
    fun currentSpan(siteUuid: String? = null, now: Instant = Instant.now()): PostSpan? =
        spans().firstOrNull { (siteUuid == null || it.siteUuid == siteUuid) && it.contains(now, shifts.settings().earlyMinutes) }

    fun nextSpan(now: Instant = Instant.now()): PostSpan? = spans().firstOrNull { it.startAt.isAfter(now) }

    /**
     * Guardia de turno en este celular: lo que se hizo acá manda (aunque todavía no se haya enviado); si no hay nada local
     * (celular reinstalado), lo que dice el servidor.
     */
    fun onDuty(): OnDuty? {
        prefs.dutyGuardUuid?.let { return OnDuty(it, prefs.dutyGuardName ?: "", prefs.dutySlotUuid, prefs.dutySlotUuid?.let { s -> shifts.shifts().firstOrNull { x -> x.uuid == s }?.startedAt }) }
        // Lo del servidor puede estar viejo: un turno que este celular ya terminó (sin señal todavía) no se vuelve a tomar.
        val endedHere = { slot: String? -> slot != null && db.get("guard_shift_slots", slot)?.let { runCatching { api.json.parseToJsonElement(it).jsonObject.str("status") }.getOrNull() } == "cumplido" }
        val d = meta()?.arr("de_turno")?.mapNotNull { it as? JsonObject }?.firstOrNull { !endedHere(it.strOrNull("slot_uuid")) } ?: return null
        val since = d.strOrNull("started_at")?.let { runCatching { Instant.parse(it) }.getOrNull() }
        // Se adopta como local para que lo que haga a partir de ahora vaya a su nombre.
        prefs.dutyGuardUuid = d.str("user_uuid"); prefs.dutyGuardName = d.str("user_name"); prefs.dutySlotUuid = d.strOrNull("slot_uuid")
        return OnDuty(d.str("user_uuid"), d.str("user_name"), d.strOrNull("slot_uuid"), since)
    }

    // ── PIN ───────────────────────────────────────────────────────

    /** Mismo cálculo que el servidor (`ActingGuard::hash`): PBKDF2-HMAC-SHA256, sal en hex, 32 bytes en hex. */
    fun verifyPin(g: PostGuard, pin: String): Boolean {
        if (!g.hasPin || pin.length !in 4..6) return false
        val salt = g.pinSalt!!.chunked(2).map { it.toInt(16).toByte() }.toByteArray()
        val key = SecretKeyFactory.getInstance("PBKDF2WithHmacSHA256").generateSecret(PBEKeySpec(pin.toCharArray(), salt, g.pinIterations, 256)).encoded
        val hex = key.joinToString("") { "%02x".format(it) }
        return java.security.MessageDigest.isEqual(hex.toByteArray(), g.pinHash!!.toByteArray())
    }

    /** Consentimiento de ubicación del guardia (Ley 25.326): se encola a su nombre (llega cuando haya señal). */
    fun acceptConsent(g: PostGuard, version: String) {
        outbox.enqueue("consent.accept", buildJsonObject { put("version", version) }, asUser = g.uuid)
        prefs.consentedGuards = prefs.consentedGuards + g.uuid
    }

    // ── empezar / relevo / terminar ───────────────────────────────

    /**
     * El guardia empieza su turno en el puesto. Si había otro de turno en este celular, lo releva (el servidor cierra su
     * tramo con "relevo" al recibir este inicio).
     */
    fun startDuty(g: PostGuard, site: Site?, fix: Fix?): GuardShift {
        val now = Instant.now()
        shifts.current()?.let { shifts.markEnded(it, now) } // relevo: el turno del anterior termina acá
        val span = currentSpan(site?.uuid)
        val post = posts().firstOrNull { it.siteUuid == (site?.uuid ?: span?.siteUuid) } ?: posts().firstOrNull()
        val siteUuid = site?.uuid ?: span?.siteUuid ?: post?.siteUuid ?: ""
        val target = site ?: shifts.site(siteUuid)
        prefs.dutyGuardUuid = g.uuid
        prefs.dutyGuardName = g.name
        val uuid = UUID.randomUUID().toString()
        prefs.dutySlotUuid = uuid
        return shifts.startLocal(uuid, target, span?.endAt, fix, span?.uuid, g.uuid, g.name)
    }

    /**
     * Terminar el turno del guardia de este celular.
     * @param leaveEmpty "Dejar el puesto sin persona" (con motivo): no avisa "relevo que no llegó".
     */
    fun endDuty(leaveEmpty: Boolean, note: String?) {
        val now = Instant.now()
        val current = shifts.current()
        val slotUuid = current?.uuid ?: prefs.dutySlotUuid
        if (slotUuid != null) {
            current?.let { shifts.markEnded(it, now) }
            outbox.enqueue("shift.end", buildJsonObject {
                put("slot_uuid", slotUuid); put("ended_at_device", now.toString())
                if (leaveEmpty) { put("leave_empty", true); put("note", note.orEmpty()) }
            })
        }
        prefs.clearDuty()
    }
}
