package ar.com.securityapp.campo.panic

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager
import android.telephony.SmsManager
import androidx.core.content.ContextCompat
import ar.com.securityapp.campo.data.AppContainer
import ar.com.securityapp.campo.data.Fix
import ar.com.securityapp.campo.data.GuardSettings
import ar.com.securityapp.campo.location.Locator
import ar.com.securityapp.campo.sync.SyncWorker
import kotlinx.coroutines.withTimeoutOrNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put
import java.time.Instant
import java.util.UUID

/**
 * Botón de pánico. Orden pensado para que nunca se pierda:
 * 1. se guarda en la cola del celular (sale sola cuando haya datos);
 * 2. se manda directo al servidor (aviso CRÍTICO a SyH, supervisores y admins);
 * 3. si no hay datos o el servidor no confirma en 15 s, SMS con la ubicación a los teléfonos de pánico de la empresa.
 */
class PanicManager(private val context: Context, private val c: AppContainer) {

    data class Result(val delivered: Boolean, val smsTo: List<String>, val hasLocation: Boolean, val smsError: String?, val phones: List<String>)

    fun canSendSms(): Boolean = ContextCompat.checkSelfPermission(context, Manifest.permission.SEND_SMS) == PackageManager.PERMISSION_GRANTED

    fun phones(): List<String> = GuardSettings.from(c.prefs, c.api).panicPhones

    suspend fun trigger(): Result {
        vibrate()
        val fix = Locator(context).current(timeoutMs = 5_000)
        val uuid = UUID.randomUUID().toString()
        val data = buildJsonObject {
            put("uuid", uuid); put("at", Instant.now().toString())
            fix?.let { put("lat", it.lat); put("lng", it.lng); put("accuracy_m", it.accuracyM) }
            c.rounds.active()?.let { put("round_uuid", it.uuid) }
        }
        val opId = c.outbox.enqueue("guard.panic", data)
        val delivered = withTimeoutOrNull(15_000) { runCatching { c.api.post("/panic", data) }.isSuccess } == true
        if (delivered) {
            c.outbox.discard(c.db.ops("pending").first { it.opId == opId }) // ya llegó: la cola no hace falta
            return Result(true, emptyList(), fix != null, null, phones())
        }
        // Sin datos: SMS desde el celular del guardia + queda en la cola (con la marca del SMS) para cuando vuelva la señal.
        val phones = phones()
        var smsError: String? = null
        val sent = if (phones.isEmpty()) {
            smsError = "La empresa no cargó teléfonos de pánico."
            emptyList()
        } else if (!canSendSms()) {
            smsError = "La app no tiene permiso para enviar SMS."
            emptyList()
        } else {
            runCatching { sendSms(phones, smsText(fix)) }.onFailure { smsError = "No se pudo enviar el SMS: ${it.message}" }.getOrDefault(emptyList())
        }
        if (sent.isNotEmpty()) {
            c.db.updateOpData(opId, JsonObject(data + ("sms_sent" to kotlinx.serialization.json.JsonPrimitive(1))).toString())
        }
        SyncWorker.now(context)
        return Result(false, sent, fix != null, smsError, phones)
    }

    private fun smsText(fix: Fix?): String {
        val me = c.auth.cachedMe()
        val time = java.text.SimpleDateFormat("dd/MM HH:mm", java.util.Locale("es", "AR")).format(java.util.Date())
        val where = fix?.let { "https://maps.google.com/?q=%.6f,%.6f".format(java.util.Locale.US, it.lat, it.lng) } ?: "sin ubicacion"
        return "PANICO - ${me?.name ?: "Guardia"} (${me?.companyName ?: ""}) $time. $where"
    }

    @Suppress("DEPRECATION")
    private fun sendSms(phones: List<String>, text: String): List<String> {
        val sms = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) context.getSystemService(SmsManager::class.java) else SmsManager.getDefault()
        val parts = sms.divideMessage(text)
        return phones.mapNotNull { phone ->
            runCatching { sms.sendMultipartTextMessage(phone, null, parts, null, null); phone }.getOrNull()
        }
    }

    @Suppress("DEPRECATION")
    private fun vibrate() {
        val v = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) context.getSystemService(VibratorManager::class.java).defaultVibrator
        else context.getSystemService(Context.VIBRATOR_SERVICE) as Vibrator
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) v.vibrate(VibrationEffect.createWaveform(longArrayOf(0, 400, 150, 400, 150, 600), -1))
        else v.vibrate(longArrayOf(0, 400, 150, 400, 150, 600), -1)
    }
}
