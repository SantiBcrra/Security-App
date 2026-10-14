package ar.com.securityapp.campo.tracking

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.pm.PackageManager
import android.location.LocationManager
import android.os.BatteryManager
import androidx.core.content.ContextCompat
import androidx.core.location.LocationManagerCompat
import ar.com.securityapp.campo.BuildConfig
import ar.com.securityapp.campo.data.LocalDb
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import java.time.Instant

/**
 * Estado del celular (automatizaciones fase 5): batería, GPS, lo que espera señal. Se manda al servidor en cada
 * sincronización (`device.status`, reemplaza al anterior en la cola) y lo usa `RoundWatch` para avisarle al guardia.
 */
data class DeviceState(val battery: Int?, val charging: Boolean, val gpsOn: Boolean, val permission: Boolean, val gpsOffSince: Instant?,
                       val pending: Int, val oldestPendingAt: Instant?) {

    fun toJson(): JsonObject = buildJsonObject {
        battery?.let { put("battery", it) }; put("charging", charging); put("gps", gpsOn); put("permission", permission)
        gpsOffSince?.let { put("gps_off_since", it.toString()) }; put("pending", pending)
        oldestPendingAt?.let { put("oldest_pending_at", it.toString()) }; put("app_version", BuildConfig.VERSION_NAME); put("at", Instant.now().toString())
    }

    companion object {
        private const val PREFS = "device_state"

        fun read(context: Context, db: LocalDb): DeviceState {
            val bat = context.registerReceiver(null, IntentFilter(Intent.ACTION_BATTERY_CHANGED))
            val level = bat?.getIntExtra(BatteryManager.EXTRA_LEVEL, -1) ?: -1
            val scale = bat?.getIntExtra(BatteryManager.EXTRA_SCALE, -1) ?: -1
            val plugged = (bat?.getIntExtra(BatteryManager.EXTRA_PLUGGED, 0) ?: 0) != 0
            val permission = ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED
            val gpsOn = LocationManagerCompat.isLocationEnabled(context.getSystemService(LocationManager::class.java))
            // Desde cuándo está apagado (para que el servidor avise si sigue así varios minutos).
            val sp = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            val off = !gpsOn || !permission
            var since = sp.getLong("gps_off_since", 0L).takeIf { it > 0 }
            if (off && since == null) { since = System.currentTimeMillis(); sp.edit().putLong("gps_off_since", since).apply() }
            if (!off && since != null) { since = null; sp.edit().remove("gps_off_since").apply() }
            return DeviceState(
                battery = if (level >= 0 && scale > 0) level * 100 / scale else null, charging = plugged, gpsOn = gpsOn, permission = permission,
                gpsOffSince = since?.let { Instant.ofEpochMilli(it) }, pending = db.pendingOps(), oldestPendingAt = db.oldestPendingAt()?.let { Instant.ofEpochMilli(it) },
            )
        }
    }
}
