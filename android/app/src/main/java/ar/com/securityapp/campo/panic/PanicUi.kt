package ar.com.securityapp.campo.panic

import android.content.Intent
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.systemBarsPadding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import ar.com.securityapp.campo.ui.theme.Danger
import kotlinx.coroutines.launch

/** Botón grande: hay que mantenerlo 2 segundos (evita activarlo sin querer en el bolsillo). */
@Composable
fun PanicButton(onTrigger: () -> Unit) {
    val progress = remember { Animatable(0f) }
    val scope = rememberCoroutineScope()
    Box(
        Modifier.fillMaxWidth().height(76.dp).clip(RoundedCornerShape(18.dp)).background(Color(0xFFB02A37))
            .pointerInput(Unit) {
                detectTapGestures(onPress = {
                    val job = scope.launch {
                        progress.snapTo(0f)
                        progress.animateTo(1f, tween(2000, easing = LinearEasing))
                        onTrigger()
                        progress.snapTo(0f)
                    }
                    tryAwaitRelease()
                    if (progress.value < 1f) {
                        job.cancel()
                        scope.launch { progress.animateTo(0f, tween(200)) }
                    }
                })
            },
        contentAlignment = Alignment.Center,
    ) {
        Box(Modifier.fillMaxHeight().fillMaxWidth(progress.value).background(Danger).align(Alignment.CenterStart))
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Text("PÁNICO", color = Color.White, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Black)
            Text(if (progress.value > 0f) "Seguí apretando…" else "Mantené apretado 2 segundos", color = Color.White, style = MaterialTheme.typography.bodySmall)
        }
    }
}

/** Pantalla después de activar el pánico: cómo salió el aviso y botones para llamar. */
@Composable
fun PanicScreen(result: PanicManager.Result?, onClose: () -> Unit) {
    BackHandler(enabled = result != null, onBack = onClose)
    val context = LocalContext.current
    Column(Modifier.fillMaxSize().background(Color(0xFF7A1420)).systemBarsPadding().padding(24.dp), verticalArrangement = Arrangement.Center,
        horizontalAlignment = Alignment.CenterHorizontally) {
        if (result == null) {
            CircularProgressIndicator(color = Color.White)
            Text("Enviando alerta…", color = Color.White, style = MaterialTheme.typography.titleLarge, modifier = Modifier.padding(top = 16.dp))
            Text("Buscando tu ubicación y avisando a la empresa.", color = Color(0xFFF5C2C7), textAlign = TextAlign.Center)
            return@Column
        }
        val (title, detail) = when {
            result.delivered -> "Alerta enviada" to "La empresa recibió tu alerta" + (if (result.hasLocation) " con tu ubicación." else " (sin ubicación: el GPS no respondió).")
            result.smsTo.isNotEmpty() -> "Alerta enviada por SMS" to "No había datos: se mandó un SMS con tu ubicación a ${result.smsTo.size} número(s). La alerta también se envía apenas vuelva la señal."
            else -> "No se pudo avisar" to "Sin datos y sin SMS (${result.smsError ?: "error"}). La alerta queda guardada y sale apenas haya señal. LLAMÁ YA."
        }
        Text(title, color = Color.White, style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
        Text(detail, color = Color(0xFFF5C2C7), textAlign = TextAlign.Center, modifier = Modifier.padding(top = 8.dp))
        for (phone in result.phones) {
            Button({ context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:$phone")).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) },
                Modifier.fillMaxWidth().padding(top = 12.dp).height(52.dp), colors = ButtonDefaults.buttonColors(containerColor = Color.White, contentColor = Color(0xFF7A1420))) {
                Text("Llamar a $phone", fontWeight = FontWeight.Bold)
            }
        }
        Button({ context.startActivity(Intent(Intent.ACTION_DIAL, Uri.parse("tel:911")).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) },
            Modifier.fillMaxWidth().padding(top = 12.dp).height(52.dp), colors = ButtonDefaults.buttonColors(containerColor = Color.White, contentColor = Color(0xFF7A1420))) {
            Text("Llamar al 911", fontWeight = FontWeight.Bold)
        }
        OutlinedButton(onClose, Modifier.padding(top = 20.dp)) { Text("Cerrar", color = Color.White) }
    }
}
