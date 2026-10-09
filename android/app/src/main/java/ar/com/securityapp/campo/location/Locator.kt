package ar.com.securityapp.campo.location

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import androidx.core.content.ContextCompat
import ar.com.securityapp.campo.data.Fix
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.android.gms.tasks.CancellationTokenSource
import kotlinx.coroutines.tasks.await
import kotlinx.coroutines.withTimeoutOrNull

/**
 * Ubicación puntual (al iniciar la ronda y en cada escaneo). El seguimiento continuo durante la ronda llega en la
 * entrega 3 (servicio en primer plano). Sin permiso o sin GPS devuelve null: el escaneo se guarda igual.
 */
class Locator(private val context: Context) {
    fun hasPermission(): Boolean =
        ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED

    @SuppressLint("MissingPermission")
    suspend fun current(timeoutMs: Long = 10_000): Fix? {
        if (!hasPermission()) return null
        val client = LocationServices.getFusedLocationProviderClient(context)
        val cts = CancellationTokenSource()
        val loc = runCatching {
            withTimeoutOrNull(timeoutMs) { client.getCurrentLocation(Priority.PRIORITY_HIGH_ACCURACY, cts.token).await() }
                ?: client.lastLocation.await()
        }.getOrNull().also { cts.cancel() }
        return loc?.let { Fix(it.latitude, it.longitude, it.accuracy) }
    }
}
