package ar.com.securityapp.campo.tracking

import android.annotation.SuppressLint
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.os.Build
import android.os.IBinder
import android.os.Looper
import androidx.core.app.NotificationCompat
import androidx.core.app.ServiceCompat
import androidx.core.content.ContextCompat
import ar.com.securityapp.campo.CampoApp
import ar.com.securityapp.campo.MainActivity
import ar.com.securityapp.campo.R
import ar.com.securityapp.campo.data.Fix
import ar.com.securityapp.campo.location.Locator
import ar.com.securityapp.campo.push.Notifs
import ar.com.securityapp.campo.sync.SyncWorker
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/**
 * "En turno": corre SOLO mientras el guardia está en turno (de "Empezar turno" a "Terminar turno"), con notificación fija.
 * Controla si está dentro del predio (ubicación cada minuto con precisión equilibrada; una lectura afuera se confirma con
 * GPS preciso). Fuera más de `minutos_fuera` → registra la salida y le pregunta el motivo; mientras está afuera guarda su
 * recorrido (lo único que se guarda); al volver cierra la salida. Adentro no se guarda ninguna posición.
 */
class ShiftTrackingService : Service() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    private val client by lazy { LocationServices.getFusedLocationProviderClient(this) }
    private val c get() = (application as CampoApp).container
    private var precise = false
    private var checking = false
    private val sp by lazy { getSharedPreferences("shift_watch", Context.MODE_PRIVATE) }
    private val tick = android.os.Handler(Looper.getMainLooper())
    private val minute = object : Runnable {
        override fun run() {
            runCatching { everyMinute() }
            tick.postDelayed(this, 60_000)
        }
    }

    private val callback = object : LocationCallback() {
        override fun onLocationResult(result: LocationResult) {
            val loc = result.lastLocation ?: return
            onFix(Fix(loc.latitude, loc.longitude, if (loc.hasAccuracy()) loc.accuracy else 50f, isMock(loc)), Instant.ofEpochMilli(loc.time))
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val shift = c.shifts.current()
        if (shift == null || !RoundTrackingService.hasLocation(this)) {
            stopSelf()
            return START_NOT_STICKY
        }
        foreground(shift.siteName, shift.startedAt ?: Instant.now(), outside = c.shifts.openExit() != null)
        request(precise = c.shifts.openExit() != null)
        tick.removeCallbacks(minute)
        tick.postDelayed(minute, 60_000)
        return START_STICKY
    }

    @SuppressLint("MissingPermission")
    private fun request(precise: Boolean) {
        this.precise = precise
        client.removeLocationUpdates(callback)
        val req = LocationRequest.Builder(if (precise) Priority.PRIORITY_HIGH_ACCURACY else Priority.PRIORITY_BALANCED_POWER_ACCURACY, 60_000L)
            .setMinUpdateIntervalMillis(30_000L)
            .build()
        client.requestLocationUpdates(req, callback, Looper.getMainLooper())
    }

    private fun onFix(fix: Fix, at: Instant) {
        val repo = c.shifts
        val shift = repo.current() ?: return stopSelf()
        val cfg = repo.settings()
        if (!cfg.active) return
        val site = repo.siteOf(shift)
        when (repo.inside(site, fix)) {
            null -> return // predio sin ubicación cargada: no se controla
            true -> {
                sp.edit().remove("outside_since").apply()
                repo.openExit()?.let { e ->
                    repo.returned(e, at)
                    Notifs.cancel(this, "shift-exit")
                    foreground(shift.siteName, shift.startedAt ?: at, outside = false)
                    SyncWorker.now(this)
                }
                if (precise) request(precise = false)
            }
            false -> {
                // Una lectura de precisión equilibrada puede engañar: se confirma con el GPS preciso antes de contar.
                if (!precise && !checking && repo.openExit() == null) {
                    checking = true
                    scope.launch {
                        val sure = Locator(this@ShiftTrackingService).current(timeoutMs = 20_000)
                        checking = false
                        if (sure != null && repo.inside(site, sure) == false) { request(precise = true); outside(sure, Instant.now()) }
                    }
                    return
                }
                outside(fix, at)
            }
        }
    }

    private fun outside(fix: Fix, at: Instant) {
        val repo = c.shifts
        val shift = repo.current() ?: return
        val cfg = repo.settings()
        val open = repo.openExit()
        if (open != null) {
            repo.position(open, fix, at)
            if (open.reason == null) askReason(open.leftAt, again = true)
            return
        }
        val since = sp.getLong("outside_since", 0L).takeIf { it > 0 }?.let { Instant.ofEpochMilli(it) }
            ?: at.also { sp.edit().putLong("outside_since", it.toEpochMilli()).apply() }
        if (at.isBefore(since.plusSeconds(cfg.outMinutes * 60L))) return
        val e = repo.exit(shift, since, fix)
        repo.position(e, fix, at)
        sp.edit().remove("outside_since").apply()
        foreground(shift.siteName, shift.startedAt ?: at, outside = true)
        askReason(since, again = false)
        SyncWorker.now(this)
    }

    /** Notificación que abre la pregunta del motivo (se repite cada 3 min hasta que conteste). */
    private fun askReason(leftAt: Instant, again: Boolean) {
        val repo = c.shifts
        val e = repo.openExit() ?: return
        if (e.reason != null) return
        if (again && System.currentTimeMillis() - e.notifiedAt < 3 * 60_000) return
        repo.markNotified(e)
        val minutes = repo.settings().noReasonMinutes
        Notifs.show(this, "shift-exit", "Saliste del predio: ¿por qué?",
            "Saliste a las ${HOUR.format(leftAt)}. Tocá acá e indicá el motivo. Si no lo indicás en $minutes min se avisa a tu supervisor.",
            critical = true, openAvisos = false, open = "turno")
    }

    private fun everyMinute() {
        val repo = c.shifts
        val shift = repo.current() ?: return stopSelf()
        repo.openExit()?.let { if (it.reason == null) askReason(it.leftAt, again = true) }
        // Terminó el horario: recordarle que marque el fin (una vez).
        if (!shift.unscheduled && Instant.now().isAfter(shift.endAt) && sp.getString("end_reminded", null) != shift.uuid) {
            sp.edit().putString("end_reminded", shift.uuid).apply()
            Notifs.show(this, "shift-end", "Terminó tu turno", "Tu turno en ${shift.siteName} terminó a las ${HOUR.format(shift.endAt)}. Abrí la app y tocá Terminar turno.",
                critical = false, openAvisos = false, open = "turno")
        }
    }

    private fun foreground(site: String, since: Instant, outside: Boolean) {
        val nm = getSystemService(NotificationManager::class.java)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && nm.getNotificationChannel(CHANNEL) == null) {
            nm.createNotificationChannel(NotificationChannel(CHANNEL, "En turno", NotificationManager.IMPORTANCE_LOW).apply {
                description = "Aviso fijo mientras estás en tu turno de trabajo (la app controla si estás en el predio)"
                setShowBadge(false)
            })
        }
        val open = PendingIntent.getActivity(this, 7, Intent(this, MainActivity::class.java).putExtra(Notifs.EXTRA_OPEN, "turno")
            .addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP), PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
        val n = NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(R.drawable.ic_round_notification)
            .setContentTitle(if (outside) "En turno · FUERA del predio" else "En turno · $site")
            .setContentText("Desde las ${HOUR.format(since)} · la ubicación se usa solo durante el turno")
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setContentIntent(open)
            .build()
        val type = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION else 0
        ServiceCompat.startForeground(this, NOTIFICATION_ID, n, type)
    }

    override fun onDestroy() {
        tick.removeCallbacks(minute)
        client.removeLocationUpdates(callback)
        scope.cancel()
        SyncWorker.now(this)
        super.onDestroy()
    }

    companion object {
        private const val CHANNEL = "turno"
        private const val NOTIFICATION_ID = 42
        private val HOUR = DateTimeFormatter.ofPattern("HH:mm").withZone(ZoneId.systemDefault())

        @Suppress("DEPRECATION")
        private fun isMock(l: android.location.Location): Boolean = if (Build.VERSION.SDK_INT >= 31) l.isMock else l.isFromMockProvider

        /** Llamar con la app en pantalla (Android no deja arrancar la ubicación en segundo plano desde atrás). */
        fun start(context: Context) {
            if (!RoundTrackingService.hasLocation(context)) return
            runCatching { ContextCompat.startForegroundService(context, Intent(context, ShiftTrackingService::class.java)) }
        }

        fun hasLocation(context: Context) = RoundTrackingService.hasLocation(context)

        fun stop(context: Context) {
            context.getSharedPreferences("shift_watch", Context.MODE_PRIVATE).edit().remove("outside_since").apply()
            context.stopService(Intent(context, ShiftTrackingService::class.java))
        }
    }
}
