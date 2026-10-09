package ar.com.securityapp.campo.ui.rounds

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.os.PowerManager
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LocalLifecycleOwner
import androidx.lifecycle.repeatOnLifecycle
import ar.com.securityapp.campo.ui.theme.Danger
import ar.com.securityapp.campo.ui.theme.Muted
import ar.com.securityapp.campo.ui.theme.Ok

/** Lo que hace falta para que el recorrido y el pánico funcionen en un celular personal (Android corta lo que no está permitido). */
object Readiness {
    fun granted(ctx: Context, perm: String) = ContextCompat.checkSelfPermission(ctx, perm) == PackageManager.PERMISSION_GRANTED
    fun location(ctx: Context) = granted(ctx, Manifest.permission.ACCESS_FINE_LOCATION)
    fun notifications(ctx: Context) = Build.VERSION.SDK_INT < 33 || granted(ctx, Manifest.permission.POST_NOTIFICATIONS)
    fun sms(ctx: Context) = granted(ctx, Manifest.permission.SEND_SMS)
    fun battery(ctx: Context) = ctx.getSystemService(PowerManager::class.java).isIgnoringBatteryOptimizations(ctx.packageName)
    fun allGood(ctx: Context, needSms: Boolean) = location(ctx) && notifications(ctx) && battery(ctx) && (!needSms || sms(ctx))

    @SuppressLint("BatteryLife")
    fun batteryIntent(ctx: Context): Intent =
        Intent(Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS, Uri.parse("package:${ctx.packageName}"))

    /** Consejos por marca: algunas cierran las apps en segundo plano aunque tengan permiso. */
    fun brandTip(): String? = when (Build.MANUFACTURER.lowercase()) {
        "xiaomi", "redmi", "poco" -> "Xiaomi: Ajustes → Apps → Security App → Inicio automático: activado, y Ahorro de batería: Sin restricciones."
        "huawei", "honor" -> "Huawei: Ajustes → Batería → Inicio de aplicaciones → Security App: administrar manualmente y activar las tres opciones."
        "oppo", "realme", "oneplus" -> "${Build.MANUFACTURER}: Ajustes → Batería → Security App → Permitir actividad en segundo plano."
        "samsung" -> "Samsung: Ajustes → Batería → Límites de uso en segundo plano → que Security App NO esté en \"Apps en suspensión\"."
        "motorola" -> "Motorola: Ajustes → Batería → Optimización de batería → Security App: No optimizar."
        else -> null
    }
}

/**
 * Antes de iniciar la ronda: muestra qué falta y deja pedirlo. La ubicación es obligatoria (sin ella no hay escaneo
 * con GPS ni recorrido); el resto se recomienda y se puede seguir igual.
 */
@Composable
fun ReadyCheckDialog(needSms: Boolean, onStart: () -> Unit, onDismiss: () -> Unit) {
    val ctx = LocalContext.current
    var tick by remember { mutableIntStateOf(0) } // vuelve a leer los permisos al volver de Ajustes
    val owner = LocalLifecycleOwner.current
    LaunchedEffect(owner) { owner.lifecycle.repeatOnLifecycle(Lifecycle.State.RESUMED) { tick++ } }
    val permLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { tick++ }
    val activityLauncher = rememberLauncherForActivityResult(ActivityResultContracts.StartActivityForResult()) { tick++ }
    @Suppress("UNUSED_EXPRESSION") tick

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Antes de empezar") },
        text = {
            Column(Modifier.verticalScroll(rememberScrollState())) {
                Item("Ubicación", "Para registrar dónde escaneás y tu recorrido (solo durante la ronda).", Readiness.location(ctx), required = true) {
                    permLauncher.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
                }
                if (Build.VERSION.SDK_INT >= 33) Item("Notificaciones", "Para el aviso fijo \"Ronda en curso\" y las alertas.", Readiness.notifications(ctx)) {
                    permLauncher.launch(arrayOf(Manifest.permission.POST_NOTIFICATIONS))
                }
                Item("Sin ahorro de batería", "Para que Android no corte el recorrido con la pantalla apagada.", Readiness.battery(ctx)) {
                    runCatching { activityLauncher.launch(Readiness.batteryIntent(ctx)) }
                }
                if (needSms) Item("SMS de pánico", "Si no hay datos, el pánico avisa por SMS a la empresa.", Readiness.sms(ctx)) {
                    permLauncher.launch(arrayOf(Manifest.permission.SEND_SMS))
                }
                Readiness.brandTip()?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Muted, modifier = Modifier.padding(top = 8.dp)) }
            }
        },
        confirmButton = { TextButton(onStart, enabled = Readiness.location(ctx)) { Text("Iniciar ronda") } },
        dismissButton = { TextButton(onDismiss) { Text("Cancelar") } },
    )
}

@Composable
private fun Item(title: String, hint: String, ok: Boolean, required: Boolean = false, onFix: () -> Unit) {
    Row(Modifier.fillMaxWidth().padding(vertical = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text((if (ok) "✓ " else if (required) "✗ " else "• ") + title, fontWeight = FontWeight.SemiBold, color = if (ok) Ok else if (required) Danger else MaterialTheme.colorScheme.onSurface)
            Text(hint, style = MaterialTheme.typography.bodySmall, color = Muted)
        }
        if (!ok) TextButton(onFix) { Text("Permitir") }
    }
}
