package ar.com.securityapp.campo.tracking

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import ar.com.securityapp.campo.data.AppContainer
import ar.com.securityapp.campo.push.Notifs
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/**
 * Recordatorios del turno con alarmas del sistema (sin señal y con la app cerrada también): a la hora de entrada
 * ("Es hora de empezar tu turno") y al terminar la tolerancia si no empezó ("Todavía no empezaste…"). El aviso de fin lo
 * da el servicio "En turno". Se reprograman con cada sincronización y al prender el celular (junto con las de rondas).
 */
object ShiftAlarms {
    const val ACTION = "ar.com.securityapp.campo.SHIFT_ALARM"
    private const val PREFS = "shift_alarms"
    private val HOUR = DateTimeFormatter.ofPattern("HH:mm").withZone(ZoneId.systemDefault())

    fun schedule(context: Context, c: AppContainer, now: Instant = Instant.now()) {
        val am = context.getSystemService(AlarmManager::class.java) ?: return
        val sp = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val wanted = mutableSetOf<String>()
        if (c.shifts.settings().active && c.api.previewToken == null) {
            for (s in c.shifts.upcoming(now).filter { it.status == "programado" && it.startAt.isBefore(now.plusSeconds(36 * 3600)) }) {
                for ((kind, at) in listOf("due" to s.startAt, "late" to s.startAt.plusSeconds(s.graceMinutes * 60L))) {
                    if (!at.isAfter(now)) continue
                    wanted += "${s.uuid}:$kind"
                    runCatching { am.setAndAllowWhileIdle(AlarmManager.RTC_WAKEUP, at.toEpochMilli(), pending(context, s.uuid, kind)) }
                }
            }
        }
        for (old in sp.getStringSet("set", emptySet())!! - wanted) {
            val (uuid, kind) = old.split(":").let { it[0] to it.getOrElse(1) { "due" } }
            am.cancel(pending(context, uuid, kind))
        }
        sp.edit().putStringSet("set", wanted).apply()
    }

    private fun pending(context: Context, shift: String, kind: String): PendingIntent =
        PendingIntent.getBroadcast(context, "shift:$shift:$kind".hashCode(),
            Intent(context, SlotAlarmReceiver::class.java).setAction(ACTION).putExtra("shift", shift).putExtra("kind", kind),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)

    fun fire(context: Context, c: AppContainer, shiftUuid: String, kind: String) {
        val s = c.shifts.upcoming().firstOrNull { it.uuid == shiftUuid && it.status == "programado" } ?: return
        if (c.shifts.current() != null) return
        if (kind == "late") {
            Notifs.show(context, "shift:$shiftUuid:late", "Todavía no empezaste tu turno",
                "Tu turno en ${s.siteName} empezaba a las ${HOUR.format(s.startAt)}. Abrí la app y tocá Empezar turno: si no, se avisa a tu supervisor.",
                critical = false, openAvisos = false, open = "turno")
        } else {
            Notifs.show(context, "shift:$shiftUuid:due", "Es hora de empezar tu turno",
                "${s.siteName}, de ${HOUR.format(s.startAt)} a ${HOUR.format(s.endAt)}. Cuando llegues, abrí la app y tocá Empezar turno.",
                critical = false, openAvisos = false, open = "turno")
        }
    }
}
