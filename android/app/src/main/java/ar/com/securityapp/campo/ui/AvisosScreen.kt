package ar.com.securityapp.campo.ui

import android.app.Application
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import ar.com.securityapp.campo.CampoApp
import ar.com.securityapp.campo.data.AppNotification
import ar.com.securityapp.campo.data.OfflineException
import ar.com.securityapp.campo.ui.observations.TopBar
import ar.com.securityapp.campo.ui.observations.hour
import ar.com.securityapp.campo.ui.theme.Accent
import ar.com.securityapp.campo.ui.theme.BrandDark
import ar.com.securityapp.campo.ui.theme.Danger
import ar.com.securityapp.campo.ui.theme.Muted
import ar.com.securityapp.campo.ui.theme.Ok
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch

data class AvisosUi(val items: List<AppNotification> = emptyList(), val loading: Boolean = true, val error: String? = null,
                    val acked: Set<String> = emptySet(), val busy: String? = null)

class AvisosViewModel(app: Application) : AndroidViewModel(app) {
    private val c = (app as CampoApp).container
    private val _ui = MutableStateFlow(AvisosUi())
    val ui: StateFlow<AvisosUi> = _ui

    init { load() }

    fun load() = viewModelScope.launch {
        _ui.value = _ui.value.copy(loading = true, error = null)
        _ui.value = try {
            val (items, unread) = c.notifications.list()
            if (unread > 0) runCatching { c.notifications.markAllRead() } // abrir la lista = leídos (como la campanita de la web)
            _ui.value.copy(items = items, loading = false)
        } catch (e: OfflineException) {
            _ui.value.copy(loading = false, error = "Sin señal: los avisos se ven con conexión.")
        } catch (e: Exception) {
            _ui.value.copy(loading = false, error = e.message)
        }
    }

    /** "Recibido" (riesgo inminente) o "Atendido" (pánico, con comentario). Necesita señal: es lo que corta el escalamiento. */
    fun ack(n: AppNotification, comment: String = "") = viewModelScope.launch {
        _ui.value = _ui.value.copy(busy = n.uuid, error = null)
        _ui.value = try {
            if (n.panicUuid != null) c.notifications.ackPanic(n.panicUuid, comment) else n.alertUuid?.let { c.notifications.ackAlert(it) }
            _ui.value.copy(busy = null, acked = _ui.value.acked + n.uuid)
        } catch (e: OfflineException) {
            _ui.value.copy(busy = null, error = "Sin señal: no se pudo avisar. Probá de nuevo o llamá por teléfono.")
        } catch (e: Exception) {
            _ui.value.copy(busy = null, error = e.message)
        }
    }
}

@Composable
fun AvisosScreen(onBack: () -> Unit, vm: AvisosViewModel = viewModel()) {
    BackHandler(onBack = onBack)
    val ui by vm.ui.collectAsStateWithLifecycle()
    var attending by remember { mutableStateOf<AppNotification?>(null) }
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        TopBar("Avisos", onBack) { TextButton({ vm.load() }) { Text("Actualizar", color = Color.White) } }
        LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            ui.error?.let { item { Text(it, color = Danger) } }
            if (ui.loading && ui.items.isEmpty()) item { CircularProgressIndicator() }
            if (!ui.loading && ui.items.isEmpty() && ui.error == null) item { Text("No tenés avisos.", color = Muted) }
            items(ui.items, key = { it.uuid }) { n ->
                AvisoCard(n, acked = n.alertAcked || n.uuid in ui.acked, busy = ui.busy == n.uuid,
                    onAck = { if (n.panicUuid != null) attending = n else vm.ack(n) })
            }
        }
    }
    attending?.let { n ->
        var comment by remember { mutableStateOf("") }
        AlertDialog(onDismissRequest = { attending = null }, title = { Text("Alerta atendida") }, text = {
            Column {
                Text("Contá qué se hizo (llamada, se fue al lugar…).", style = MaterialTheme.typography.bodySmall, color = Muted)
                OutlinedTextField(comment, { comment = it.take(500) }, Modifier.fillMaxWidth().padding(top = 8.dp))
            }
        }, confirmButton = { TextButton({ attending = null; vm.ack(n, comment) }) { Text("Marcar atendida") } },
            dismissButton = { TextButton({ attending = null }) { Text("Cancelar") } })
    }
}

@Composable
private fun AvisoCard(n: AppNotification, acked: Boolean, busy: Boolean, onAck: () -> Unit) {
    val bg = when {
        n.critical && !acked && (n.alertUuid != null || n.panicUuid != null) -> Color(0xFFFDE3E5)
        !n.read -> Color(0xFFFDF3CF)
        else -> Color.White
    }
    Card(colors = CardDefaults.cardColors(containerColor = bg)) {
        Column(Modifier.padding(14.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(n.title, fontWeight = FontWeight.Bold, color = if (n.critical) Danger else BrandDark, modifier = Modifier.weight(1f))
                Text(hour(n.at), style = MaterialTheme.typography.labelSmall, color = Muted)
            }
            if (n.body.isNotBlank()) Text(n.body, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.padding(top = 2.dp))
            if (n.alertUuid != null || n.panicUuid != null) {
                if (acked) Text((if (n.panicUuid != null) "Atendida" else "Recibido") + (n.alertAckedBy?.let { " por $it" } ?: ""), color = Ok,
                    fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 6.dp))
                else Button(onAck, Modifier.padding(top = 8.dp), enabled = !busy,
                    colors = ButtonDefaults.buttonColors(containerColor = Accent, contentColor = BrandDark)) {
                    Text(if (busy) "Enviando…" else if (n.panicUuid != null) "Atendido" else "Recibido", fontWeight = FontWeight.Bold)
                }
            }
        }
    }
}
