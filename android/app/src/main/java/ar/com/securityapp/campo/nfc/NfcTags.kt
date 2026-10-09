package ar.com.securityapp.campo.nfc

import android.app.Activity
import android.content.Context
import android.content.ContextWrapper
import android.nfc.NdefMessage
import android.nfc.NdefRecord
import android.nfc.NfcAdapter
import android.nfc.Tag
import android.nfc.tech.Ndef
import android.nfc.tech.NdefFormatable
import android.os.Handler
import android.os.Looper
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.platform.LocalContext

/**
 * Etiquetas NFC de los puntos de ronda. La etiqueta guarda la MISMA dirección que el QR del punto
 * (`{servidor}/ronda/punto/{uuid}`, registro NDEF tipo URI): la app la lee igual que un QR y un celular sin la app abre
 * esa página. Se leen en "reader mode" solo mientras la pantalla de la ronda está abierta.
 */
object NfcTags {
    enum class State { NONE, OFF, ON }

    fun state(context: Context): State {
        val adapter = NfcAdapter.getDefaultAdapter(context) ?: return State.NONE
        return if (adapter.isEnabled) State.ON else State.OFF
    }

    /** Contenido de la etiqueta (URI o texto), o null si está vacía / no es NDEF. */
    fun read(tag: Tag): String? {
        val ndef = Ndef.get(tag) ?: return null
        val message = ndef.cachedNdefMessage ?: runCatching { ndef.connect(); ndef.ndefMessage.also { ndef.close() } }.getOrNull() ?: return null
        for (record in message.records) {
            record.toUri()?.let { return it.toString() }
            if (record.tnf == NdefRecord.TNF_WELL_KNOWN && record.type.contentEquals(NdefRecord.RTD_TEXT)) {
                val payload = record.payload
                val langLength = payload[0].toInt() and 0x3F
                return String(payload, 1 + langLength, payload.size - 1 - langLength, Charsets.UTF_8)
            }
        }
        return null
    }

    /** Graba la dirección del punto. @param lock bloquea la etiqueta (no se puede volver a grabar ni borrar). @return error o null */
    fun write(tag: Tag, uri: String, lock: Boolean): String? {
        val message = NdefMessage(arrayOf(NdefRecord.createUri(uri)))
        return try {
            val ndef = Ndef.get(tag)
            if (ndef != null) {
                ndef.connect()
                try {
                    if (!ndef.isWritable) return "La etiqueta está bloqueada: no se puede volver a grabar."
                    if (ndef.maxSize < message.byteArrayLength) return "La etiqueta es muy chica (${ndef.maxSize} bytes)."
                    ndef.writeNdefMessage(message)
                    if (lock && !ndef.makeReadOnly()) return "Se grabó, pero esta etiqueta no se puede bloquear."
                } finally { runCatching { ndef.close() } }
                null
            } else {
                val formatable = NdefFormatable.get(tag) ?: return "Etiqueta no compatible (tiene que ser NDEF, por ejemplo NTAG213/215/216)."
                formatable.connect()
                try { if (lock) formatable.formatReadOnly(message) else formatable.format(message) } finally { runCatching { formatable.close() } }
                null
            }
        } catch (e: Exception) {
            "No se pudo grabar: mantené el celular quieto sobre la etiqueta y probá de nuevo."
        }
    }
}

private fun Context.activity(): Activity? = when (this) {
    is Activity -> this
    is ContextWrapper -> baseContext.activity()
    else -> null
}

/**
 * Mientras está en pantalla, las etiquetas que se acercan llegan a [onTag] (en el hilo principal) en vez de abrir otra app.
 * Sin NFC o con NFC apagado no hace nada.
 */
@Composable
fun NfcListener(enabled: Boolean = true, onTag: (Tag) -> Unit) {
    val context = LocalContext.current
    val current = rememberUpdatedState(onTag)
    DisposableEffect(enabled) {
        val activity = context.activity()
        val adapter = NfcAdapter.getDefaultAdapter(context)
        if (!enabled || activity == null || adapter == null || !adapter.isEnabled) return@DisposableEffect onDispose { }
        val main = Handler(Looper.getMainLooper())
        val flags = NfcAdapter.FLAG_READER_NFC_A or NfcAdapter.FLAG_READER_NFC_B or NfcAdapter.FLAG_READER_NFC_F or NfcAdapter.FLAG_READER_NFC_V
        adapter.enableReaderMode(activity, { tag -> main.post { current.value(tag) } }, flags, null)
        onDispose { runCatching { adapter.disableReaderMode(activity) } }
    }
}
