package ar.com.securityapp.campo.tracking

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import ar.com.securityapp.campo.CampoApp
import ar.com.securityapp.campo.data.AppContainer
import ar.com.securityapp.campo.data.RoundAutomationSettings
import ar.com.securityapp.campo.push.Notifs
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/**
 * Recordatorios de las rondas programadas, sin señal también (alarmas del sistema, aunque la app esté cerrada):
 * cinco minutos antes, a la hora ("Es hora de la ronda…") y al terminar la gracia si no empezó ("Todavía no empezaste…"). Se reprograman en
 * cada sincronización y al prender el celular. Al sonar se revisa si ya empezó: si empezó, no avisa.
 */
object SlotAlarms {
    private const val ACTION = "ar.com.securityapp.campo.SLOT_ALARM"
    private const val PREFS = "slot_alarms"
    private val HOUR = DateTimeFormatter.ofPattern("HH:mm").withZone(ZoneId.systemDefault())

    fun schedule(context: Context, c: AppContainer, now: Instant = Instant.now()) {
        val am = context.getSystemService(AlarmManager::class.java) ?: return
        val sp = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        // Las que ya no corresponden (hecha, anulada, otra programación) se cancelan.
        val wanted = mutableSetOf<String>()
        val cfg = RoundAutomationSettings.from(c.prefs, c.api)
        if (cfg.guidance) {
            for (slot in c.rounds.slots(now).filter { it.dueAt.isBefore(now.plusSeconds(36 * 3600)) }) {
                for ((kind, at) in listOf(
                    "pre" to slot.dueAt.minusSeconds(5 * 60L),
                    "due" to slot.dueAt,
                    "late" to slot.dueAt.plusSeconds(slot.graceMinutes * 60L),
                )) {
                    if (!at.isAfter(now)) continue
                    val key = "${slot.uuid}:$kind"
                    wanted += key
                    runCatching { am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, at.toEpochMilli(), pending(context, slot.uuid, kind)) }
                }
            }
        }
        for (old in sp.getStringSet("set", emptySet())!! - wanted) {
            val (uuid, kind) = old.split(":").let { it[0] to it.getOrElse(1) { "due" } }
            am.cancel(pending(context, uuid, kind))
        }
        sp.edit().putStringSet("set", wanted).apply()
        runCatching { ShiftAlarms.schedule(context, c, now) } // y los del turno de trabajo
    }

    private fun pending(context: Context, slot: String, kind: String): PendingIntent =
        PendingIntent.getBroadcast(context, "$slot:$kind".hashCode(),
            Intent(context, SlotAlarmReceiver::class.java).setAction(ACTION).putExtra("slot", slot).putExtra("kind", kind),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)

    /** Al sonar: si la ronda programada sigue pendiente y no hay una ronda de esa ruta en curso, avisa. */
    fun fire(context: Context, c: AppContainer, slotUuid: String, kind: String) {
        val slot = c.rounds.slots().firstOrNull { it.uuid == slotUuid } ?: return
        if (c.rounds.active()?.routeUuid == slot.routeUuid) return
        val route = c.rounds.route(slot.routeUuid)?.name ?: "la ronda"
        val at = HOUR.format(slot.dueAt)
        if (kind == "pre") {
            Notifs.show(context, "slot:$slotUuid:pre", "En 5 minutos: ronda $route",
                "Prepará el celular y acercate al primer punto. Podés tocar la ronda desde el inicio o escanear directamente el primer QR/NFC.", critical = false, openAvisos = false)
        } else if (kind == "late") {
            Notifs.show(context, "slot:$slotUuid:late", "Todavía no empezaste la ronda de las $at",
                "$route: empezala ya. Si no empieza antes de las ${HOUR.format(slot.deadlineAt)} queda como no realizada y se avisa a tu supervisor.",
                critical = false, openAvisos = false)
        } else {
            Notifs.show(context, "slot:$slotUuid:due", "Es hora de la ronda: $route",
                "Ronda de las $at" + (slot.schedule?.let { " ($it)" } ?: "") + ". Abrí la app y tocá Iniciar.", critical = false, openAvisos = false)
        }
    }
}

class SlotAlarmReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val c = (context.applicationContext as CampoApp).container
        when (intent.action) {
            Intent.ACTION_BOOT_COMPLETED, Intent.ACTION_MY_PACKAGE_REPLACED -> SlotAlarms.schedule(context, c)
            ShiftAlarms.ACTION -> runCatching { ShiftAlarms.fire(context, c, intent.getStringExtra("shift") ?: return, intent.getStringExtra("kind") ?: "due") }
            else -> runCatching { SlotAlarms.fire(context, c, intent.getStringExtra("slot") ?: return, intent.getStringExtra("kind") ?: "due") }
        }
    }
}
