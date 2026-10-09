package ar.com.securityapp.campo.push

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.media.AudioAttributes
import android.media.RingtoneManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import ar.com.securityapp.campo.MainActivity
import ar.com.securityapp.campo.R

/**
 * Notificaciones del celular. Dos canales: "Alertas críticas" (pánico, riesgo inminente: sonido de alarma, vibración,
 * se muestra encima de lo que esté abierto) y "Avisos" (lo demás). El usuario puede ajustar cada canal en Android.
 */
object Notifs {
    const val CRITICAL = "alertas"
    const val NORMAL = "avisos"
    const val EXTRA_OPEN = "abrir"

    fun ensureChannels(context: Context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val nm = context.getSystemService(NotificationManager::class.java)
        if (nm.getNotificationChannel(CRITICAL) == null) {
            nm.createNotificationChannel(NotificationChannel(CRITICAL, "Alertas críticas", NotificationManager.IMPORTANCE_HIGH).apply {
                description = "Pánico de un guardia, riesgo inminente, gases fuera de rango"
                enableVibration(true)
                vibrationPattern = longArrayOf(0, 600, 200, 600, 200, 900)
                setSound(RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM),
                    AudioAttributes.Builder().setUsage(AudioAttributes.USAGE_ALARM).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build())
                lockscreenVisibility = NotificationCompat.VISIBILITY_PUBLIC
            })
        }
        if (nm.getNotificationChannel(NORMAL) == null) {
            nm.createNotificationChannel(NotificationChannel(NORMAL, "Avisos", NotificationManager.IMPORTANCE_DEFAULT).apply {
                description = "Acciones asignadas, vencimientos, permisos y demás avisos"
            })
        }
    }

    fun show(context: Context, key: String, title: String, body: String, critical: Boolean) {
        ensureChannels(context)
        val open = PendingIntent.getActivity(context, key.hashCode(),
            Intent(context, MainActivity::class.java).putExtra(EXTRA_OPEN, "avisos").addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
        val n = NotificationCompat.Builder(context, if (critical) CRITICAL else NORMAL)
            .setSmallIcon(R.drawable.ic_notification)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setPriority(if (critical) NotificationCompat.PRIORITY_MAX else NotificationCompat.PRIORITY_DEFAULT)
            .setCategory(if (critical) NotificationCompat.CATEGORY_ALARM else NotificationCompat.CATEGORY_MESSAGE)
            .setAutoCancel(true)
            .setContentIntent(open)
            .build()
        runCatching { NotificationManagerCompat.from(context).notify(key.hashCode(), n) } // sin permiso de notificaciones: no se muestra
    }
}
