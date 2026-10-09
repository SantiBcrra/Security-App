package ar.com.securityapp.campo.tracking

import android.Manifest
import android.annotation.SuppressLint
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.os.BatteryManager
import android.os.Build
import android.os.IBinder
import android.os.Looper
import androidx.core.app.NotificationCompat
import androidx.core.app.ServiceCompat
import androidx.core.content.ContextCompat
import ar.com.securityapp.campo.CampoApp
import ar.com.securityapp.campo.MainActivity
import ar.com.securityapp.campo.R
import ar.com.securityapp.campo.data.GuardSettings
import ar.com.securityapp.campo.data.LocalDb
import ar.com.securityapp.campo.sync.SyncWorker
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import java.time.Instant
import java.util.UUID

/**
 * Sigue al guardia SOLO mientras hay una ronda en curso (servicio en primer plano con notificación fija, que es lo que
 * Android respeta con la pantalla apagada). Guarda cada posición en el celular; la sincronización las manda en lote.
 * Fuera de la ronda no corre: es el celular personal del empleado.
 */
class RoundTrackingService : Service() {
    private var roundUuid: String? = null
    private var lastSyncRequest = 0L
    private val client by lazy { LocationServices.getFusedLocationProviderClient(this) }

    private val callback = object : LocationCallback() {
        override fun onLocationResult(result: LocationResult) {
            val round = roundUuid ?: return
            val db = (application as CampoApp).container.db
            for (loc in result.locations) {
                db.addTrack(LocalDb.TrackPoint(UUID.randomUUID().toString(), round, Instant.ofEpochMilli(loc.time).toString(), loc.latitude, loc.longitude,
                    if (loc.hasAccuracy()) loc.accuracy else null, battery()))
            }
            // Cada 3 minutos se intenta mandar (si no hay señal, WorkManager espera a que vuelva).
            val now = System.currentTimeMillis()
            if (now - lastSyncRequest > 3 * 60_000) {
                lastSyncRequest = now
                SyncWorker.now(this@RoundTrackingService)
            }
        }
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        val round = intent?.getStringExtra(EXTRA_ROUND) ?: (application as CampoApp).container.rounds.active()?.uuid
        if (round == null || !hasLocation(this)) {
            stopSelf()
            return START_NOT_STICKY
        }
        val routeName = intent?.getStringExtra(EXTRA_ROUTE) ?: "Ronda libre"
        startInForeground(routeName)
        if (round != roundUuid) {
            roundUuid = round
            requestUpdates()
        }
        return START_STICKY // si Android lo corta por memoria, lo vuelve a levantar
    }

    @SuppressLint("MissingPermission")
    private fun requestUpdates() {
        client.removeLocationUpdates(callback)
        val seconds = GuardSettings.from((application as CampoApp).container.prefs, (application as CampoApp).container.api).trackSeconds
        val req = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, seconds * 1000L)
            .setMinUpdateIntervalMillis(seconds * 500L)
            .setMinUpdateDistanceMeters(0f)
            .build()
        client.requestLocationUpdates(req, callback, Looper.getMainLooper())
    }

    private fun startInForeground(routeName: String) {
        val nm = getSystemService(NotificationManager::class.java)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && nm.getNotificationChannel(CHANNEL) == null) {
            nm.createNotificationChannel(NotificationChannel(CHANNEL, "Ronda en curso", NotificationManager.IMPORTANCE_LOW).apply {
                description = "Aviso fijo mientras la app registra el recorrido de la ronda"
                setShowBadge(false)
            })
        }
        val open = PendingIntent.getActivity(this, 0, Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
        val notification = NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(R.drawable.ic_round_notification)
            .setContentTitle("Ronda en curso")
            .setContentText("$routeName · se registra tu recorrido")
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setContentIntent(open)
            .build()
        val type = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) ServiceInfo.FOREGROUND_SERVICE_TYPE_LOCATION else 0
        ServiceCompat.startForeground(this, NOTIFICATION_ID, notification, type)
    }

    private fun battery(): Int? = runCatching {
        (getSystemService(Context.BATTERY_SERVICE) as BatteryManager).getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY).takeIf { it in 0..100 }
    }.getOrNull()

    override fun onDestroy() {
        client.removeLocationUpdates(callback)
        SyncWorker.now(this) // lo último que quedó
        super.onDestroy()
    }

    companion object {
        private const val CHANNEL = "ronda"
        private const val NOTIFICATION_ID = 41
        private const val EXTRA_ROUND = "round"
        private const val EXTRA_ROUTE = "route"

        fun hasLocation(context: Context) =
            ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED

        /** Llamar con la app en pantalla (Android no deja arrancar el GPS en segundo plano desde atrás). */
        fun start(context: Context, roundUuid: String, routeName: String?) {
            if (!hasLocation(context)) return
            val intent = Intent(context, RoundTrackingService::class.java).putExtra(EXTRA_ROUND, roundUuid).putExtra(EXTRA_ROUTE, routeName ?: "Ronda libre")
            runCatching { ContextCompat.startForegroundService(context, intent) }
        }

        fun stop(context: Context) {
            context.stopService(Intent(context, RoundTrackingService::class.java))
        }
    }
}
