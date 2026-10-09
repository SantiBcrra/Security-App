package ar.com.securityapp.campo.data

import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put
import java.time.Instant
import java.util.UUID

/** Punto de control con QR (y NFC más adelante). */
data class PatrolPoint(val uuid: String, val name: String, val code: String, val description: String?, val lat: Double?, val lng: Double?,
                       val radiusM: Int, val critical: Boolean)

/** Ruta asignada: puntos en orden. */
data class PatrolRoute(val uuid: String, val name: String, val description: String?, val frequency: String?, val expectedMinutes: Int?,
                       val points: List<String>)

data class PatrolRound(val uuid: String, val routeUuid: String?, val mine: Boolean, val status: String, val startedAt: String, val finishedAt: String?,
                       val local: Boolean)

data class PatrolScan(val uuid: String, val roundUuid: String, val pointUuid: String, val scannedAt: String, val withinRadius: Boolean?, val local: Boolean)

/** Lugar del celular al escanear (puede no haber GPS: el servidor lo marca como fuera de radio). */
data class Fix(val lat: Double, val lng: Double, val accuracyM: Float)

/**
 * Rondas sin señal: todo se guarda en la base local y se encola (`round.start`, `round.scan`, `round.finish`).
 * La ronda en curso sobrevive a cerrar la app (es la mía más reciente que no terminó).
 */
class RoundsRepository(private val db: LocalDb, private val outbox: Outbox, private val api: ApiClient) {

    sealed interface ScanResult {
        data class Ok(val point: PatrolPoint, val hasGps: Boolean, val remaining: Int) : ScanResult
        data class AlreadyScanned(val point: PatrolPoint) : ScanResult
        data class NotInRoute(val point: PatrolPoint) : ScanResult
        data object UnknownQr : ScanResult
        data object NoRound : ScanResult
    }

    fun points(): Map<String, PatrolPoint> = db.all("patrol_points").mapNotNull { parse(it)?.let(::point) }.associateBy { it.uuid }

    fun routes(): List<PatrolRoute> = db.all("patrol_routes").mapNotNull { parse(it)?.let(::route) }.sortedBy { it.name.lowercase() }

    fun rounds(): List<PatrolRound> = db.all("patrol_rounds").mapNotNull { parse(it)?.let(::round) }.filter { it.mine }.sortedByDescending { it.startedAt }

    fun active(): PatrolRound? = rounds().firstOrNull { it.status == "en_curso" }

    fun scans(roundUuid: String): List<PatrolScan> =
        db.all("patrol_scans").mapNotNull { parse(it)?.let(::scan) }.filter { it.roundUuid == roundUuid }.sortedBy { it.scannedAt }

    fun route(uuid: String?): PatrolRoute? = uuid?.let { u -> routes().firstOrNull { it.uuid == u } }

    fun start(routeUuid: String?, fix: Fix?): PatrolRound {
        active()?.let { return it }
        val uuid = UUID.randomUUID().toString()
        val now = Instant.now().toString()
        val row = buildJsonObject {
            put("uuid", uuid); put("route_uuid", routeUuid); put("mine", true); put("status", "en_curso"); put("started_at", now); put("local", true)
        }
        db.upsert("patrol_rounds", listOf(uuid to row.toString()))
        outbox.enqueue("round.start", buildJsonObject {
            put("uuid", uuid); put("route_uuid", routeUuid); put("started_at_device", now)
            fix?.let { put("lat", it.lat); put("lng", it.lng) }
        })
        return round(row)!!
    }

    /** @param raw contenido del QR: la URL `/ronda/punto/{uuid}` (o el uuid solo) */
    fun scan(raw: String, fix: Fix?, allowOutsideRoute: Boolean = false, method: String = "qr"): ScanResult {
        val round = active() ?: return ScanResult.NoRound
        val pointUuid = pointUuidFrom(raw) ?: return ScanResult.UnknownQr
        val point = points()[pointUuid] ?: return ScanResult.UnknownQr
        val done = scans(round.uuid)
        if (done.any { it.pointUuid == pointUuid }) return ScanResult.AlreadyScanned(point)
        val route = route(round.routeUuid)
        if (route != null && pointUuid !in route.points && !allowOutsideRoute) return ScanResult.NotInRoute(point)
        val uuid = UUID.randomUUID().toString()
        val now = Instant.now().toString()
        val data = buildJsonObject {
            put("uuid", uuid); put("round_uuid", round.uuid); put("point_uuid", pointUuid); put("scanned_at_device", now); put("method", method)
            fix?.let { put("lat", it.lat); put("lng", it.lng); put("accuracy_m", it.accuracyM) }
        }
        db.upsert("patrol_scans", listOf(uuid to buildJsonObject {
            data.forEach { (k, v) -> put(k, v) }
            put("local", true)
        }.toString()))
        outbox.enqueue("round.scan", data)
        val remaining = route?.points?.count { p -> p != pointUuid && done.none { it.pointUuid == p } } ?: 0
        return ScanResult.Ok(point, fix != null, remaining)
    }

    /** Los puntos de la ruta que faltan quedan como salteados (el servidor marca la ronda "incompleta"). */
    fun finish(round: PatrolRound): Int {
        val route = route(round.routeUuid)
        val done = scans(round.uuid).map { it.pointUuid }.toSet()
        val missing = route?.points?.count { it !in done } ?: 0
        val now = Instant.now().toString()
        val stored = db.get("patrol_rounds", round.uuid)?.let { parse(it) } ?: JsonObject(emptyMap())
        db.upsert("patrol_rounds", listOf(round.uuid to buildJsonObject {
            stored.forEach { (k, v) -> put(k, v) }
            put("status", if (missing > 0) "incompleta" else "completa"); put("finished_at", now); put("local", true)
        }.toString()))
        outbox.enqueue("round.finish", buildJsonObject { put("round_uuid", round.uuid); put("finished_at_device", now) })
        return missing
    }

    // ── lectura del JSON guardado por la sincronización ────────────

    private fun parse(s: String): JsonObject? = runCatching { api.json.parseToJsonElement(s).jsonObject }.getOrNull()

    private fun point(o: JsonObject) = PatrolPoint(o.str("uuid"), o.str("name"), o.str("code"), o.strOrNull("description"),
        o.strOrNull("lat")?.toDoubleOrNull(), o.strOrNull("lng")?.toDoubleOrNull(), o.int("radius_m"), o.bool("critical"))

    private fun route(o: JsonObject) = PatrolRoute(o.str("uuid"), o.str("name"), o.strOrNull("description"), o.strOrNull("frequency"),
        o.int("expected_minutes").takeIf { it > 0 }, (o["points"] as? JsonArray)?.mapNotNull { (it as? JsonPrimitive)?.content }.orEmpty())

    private fun round(o: JsonObject) = PatrolRound(o.str("uuid"), o.strOrNull("route_uuid"), o.bool("mine"), o.str("status"), o.str("started_at"),
        o.strOrNull("finished_at"), o.bool("local"))

    private fun scan(o: JsonObject) = PatrolScan(o.str("uuid"), o.str("round_uuid"), o.str("point_uuid"), o.str("scanned_at_device"),
        (o["within_radius"] as? JsonPrimitive)?.content?.toBooleanStrictOrNull(), o.bool("local"))

    companion object {
        private val UUID_RE = Regex("([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12})")

        /** QR de punto de ronda: URL `.../ronda/punto/{uuid}` o el uuid solo. Otros QR (equipos `/q/…`) no valen. */
        fun pointUuidFrom(raw: String): String? {
            val text = raw.trim()
            Regex("/ronda/punto/([0-9a-fA-F-]{36})").find(text)?.let { return it.groupValues[1].lowercase() }
            return UUID_RE.matchEntire(text)?.groupValues?.get(1)?.lowercase()
        }
    }
}
