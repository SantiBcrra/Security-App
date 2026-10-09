package ar.com.securityapp.campo.ui.rounds

import android.content.Context
import android.media.AudioManager
import android.media.ToneGenerator
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.os.VibrationEffect
import android.os.Vibrator
import android.os.VibratorManager

/**
 * Sonido + vibración al marcar un punto, para que el guardia sepa sin mirar la pantalla si quedó registrado (sobre todo
 * con NFC, donde el "clic" del sistema solo indica que leyó la etiqueta). Con el celular en silencio vibra igual.
 */
object ScanFeedback {
    enum class Kind { OK, ROUND_DONE, WARNING, ERROR }

    fun play(context: Context, kind: Kind) {
        val (tone, toneMs, pattern) = when (kind) {
            Kind.OK -> Triple(ToneGenerator.TONE_PROP_ACK, 250, longArrayOf(0, 120))
            Kind.ROUND_DONE -> Triple(ToneGenerator.TONE_PROP_ACK, 600, longArrayOf(0, 120, 100, 120, 100, 300))
            Kind.WARNING -> Triple(ToneGenerator.TONE_PROP_BEEP2, 300, longArrayOf(0, 200, 150, 200))
            Kind.ERROR -> Triple(ToneGenerator.TONE_SUP_ERROR, 700, longArrayOf(0, 500, 150, 500))
        }
        runCatching {
            val gen = ToneGenerator(AudioManager.STREAM_NOTIFICATION, 100)
            gen.startTone(tone, toneMs)
            Handler(Looper.getMainLooper()).postDelayed({ gen.release() }, toneMs + 200L)
        }
        runCatching {
            val vibrator = if (Build.VERSION.SDK_INT >= 31) context.getSystemService(VibratorManager::class.java).defaultVibrator
            else @Suppress("DEPRECATION") context.getSystemService(Vibrator::class.java)
            vibrator.vibrate(VibrationEffect.createWaveform(pattern, -1))
        }
    }

    fun forResult(result: ar.com.securityapp.campo.data.RoundsRepository.ScanResult): Kind = when (result) {
        is ar.com.securityapp.campo.data.RoundsRepository.ScanResult.Ok -> if (result.remaining == 0) Kind.ROUND_DONE else Kind.OK // ruta completa
        is ar.com.securityapp.campo.data.RoundsRepository.ScanResult.AlreadyScanned -> Kind.WARNING
        else -> Kind.ERROR
    }
}
