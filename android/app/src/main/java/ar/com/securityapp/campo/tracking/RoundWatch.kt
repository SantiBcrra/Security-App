package ar.com.securityapp.campo.tracking

import android.content.Context
import ar.com.securityapp.campo.data.AppContainer
import ar.com.securityapp.campo.data.RoundAutomationSettings
import ar.com.securityapp.campo.push.Notifs
import java.time.Duration
import java.time.Instant

/**
 * Avisos al guardia durante la ronda, calculados en el celular (funcionan sin señal). Es el primer aviso: si sigue igual
 * después de otro plazo igual, el servidor avisa a SyH y supervisores (`RoundAutomation`).
 * - Sin progreso: N minutos sin marcar ningún punto (se repite por cada hueco nuevo).
 * - Atrasada: la ruta tiene duración esperada E y pasaron E + T minutos (una vez por ronda).
 * - Celular (fase 5): batería baja sin cargar (una vez por ronda), GPS apagado o sin permiso (por cada vez que se apaga),
 *   el GPS no da posiciones aunque esté prendido, y registros que esperan señal hace más de `sin_sync_minutos`.
 */
object RoundWatch {
    private const val PREFS = "round_watch"

    fun check(context: Context, c: AppContainer, now: Instant = Instant.now()) {
        val cfg = RoundAutomationSettings.from(c.prefs, c.api)
        if (!cfg.active) return
        val round = c.rounds.active() ?: return
        val started = runCatching { Instant.parse(round.startedAt) }.getOrNull() ?: return
        val scans = c.rounds.scans(round.uuid)
        val last = scans.mapNotNull { runCatching { Instant.parse(it.scannedAt) }.getOrNull() }.maxOrNull() ?: started
        val sp = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        checkDevice(context, c, cfg, round.uuid, now, sp)

        val idle = Duration.between(last, now).toMinutes()
        val gapKey = "noprog:${round.uuid}:${last.epochSecond}"
        if (idle >= cfg.noProgressMinutes && !sp.getBoolean(gapKey, false)) {
            sp.edit().putBoolean(gapKey, true).apply()
            Notifs.show(context, gapKey, "Hace $idle min que no marcás ningún punto",
                "Seguí la ronda y marcá el próximo punto. Si no podés seguir, avisá a tu supervisor. Si no marcás, en ${cfg.noProgressMinutes} min más se le avisa a tu supervisor.",
                critical = false, openAvisos = false)
        }

        val route = c.rounds.route(round.routeUuid) ?: return
        val expected = route.expectedMinutes?.takeIf { it > 0 } ?: return
        val elapsed = Duration.between(started, now).toMinutes()
        val lateKey = "late:${round.uuid}"
        if (elapsed >= expected + cfg.lateMinutes && !sp.getBoolean(lateKey, false)) {
            sp.edit().putBoolean(lateKey, true).apply()
            val missing = route.points.count { p -> scans.none { it.pointUuid == p } }
            Notifs.show(context, lateKey, "Tu ronda está atrasada",
                "Llevás $elapsed min (se esperan $expected). " + (if (missing > 0) "Faltan $missing punto(s). " else "Ya marcaste todos: finalizala. ") +
                    "Si no la terminás en ${cfg.lateMinutes} min se avisa a tu supervisor.",
                critical = false, openAvisos = false)
        }
    }

    private fun checkDevice(context: Context, c: AppContainer, cfg: RoundAutomationSettings, round: String, now: Instant, sp: android.content.SharedPreferences) {
        val state = DeviceState.read(context, c.db)
        fun once(key: String, title: String, body: String) {
            if (sp.getBoolean(key, false)) return
            sp.edit().putBoolean(key, true).apply()
            Notifs.show(context, key, title, body, critical = false, openAvisos = false)
        }
        if (state.battery != null && state.battery <= cfg.batteryPercent && !state.charging) {
            once("battery:$round", "Batería baja (${state.battery} %)",
                "Cargá el celular o avisá a tu supervisor: si se apaga no funciona el botón de pánico ni se registra la ronda.")
        }
        if (!state.permission || !state.gpsOn) {
            once("gps:$round:${state.gpsOffSince?.epochSecond}", if (!state.permission) "La app no tiene permiso de ubicación" else "La ubicación está apagada",
                "Sin ubicación no se registra tu recorrido ni dónde marcás los puntos. Activala ahora: en ${GPS_OFF_MINUTES} min se avisa a tu supervisor.")
        } else {
            // GPS prendido pero sin posiciones nuevas (adentro de un edificio, GPS trabado).
            val seconds = ar.com.securityapp.campo.data.GuardSettings.from(c.prefs, c.api).trackSeconds
            val last = c.db.lastTrackAt(round)?.let { runCatching { Instant.parse(it) }.getOrNull() }
            val staleMinutes = maxOf(5L, 3L * seconds / 60)
            if (last != null && Duration.between(last, now).toMinutes() >= staleMinutes) {
                once("stale:$round:${last.epochSecond}", "El GPS no está dando tu ubicación",
                    "Hace ${Duration.between(last, now).toMinutes()} min que no se registra tu posición. Salí a un lugar abierto unos segundos o reiniciá la ubicación.")
            }
        }
        val oldest = state.oldestPendingAt
        if (oldest != null && Duration.between(oldest, now).toMinutes() >= cfg.syncMinutes) {
            once("sync:${oldest.epochSecond}", "Hay ${state.pending} registro(s) sin enviar",
                "Están guardados en el celular desde hace ${Duration.between(oldest, now).toMinutes()} min. Buscá señal (Wi-Fi o datos): se envían solos al reconectar.")
        }
    }

    /** Igual que `DeviceStatus::GPS_OFF_MINUTES` en el servidor. */
    private const val GPS_OFF_MINUTES = 5
}
