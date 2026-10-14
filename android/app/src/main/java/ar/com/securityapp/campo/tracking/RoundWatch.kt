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
}
