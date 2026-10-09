package ar.com.securityapp.campo.ui.rounds

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
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
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ar.com.securityapp.campo.data.PatrolPoint
import ar.com.securityapp.campo.nfc.NfcListener
import ar.com.securityapp.campo.nfc.NfcTags
import ar.com.securityapp.campo.ui.observations.TopBar
import ar.com.securityapp.campo.ui.theme.Danger
import ar.com.securityapp.campo.ui.theme.Muted
import ar.com.securityapp.campo.ui.theme.Ok
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Grabar la etiqueta NFC de cada punto (quien administra las rondas). Se elige el punto, se acerca la etiqueta y queda
 * con la dirección del QR del punto. Bloquearla evita que alguien la regrabe con otro punto.
 */
@Composable
fun NfcWriterScreen(points: List<PatrolPoint>, urlOf: (PatrolPoint) -> String, onBack: () -> Unit) {
    BackHandler(onBack = onBack)
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var selected by remember { mutableStateOf<PatrolPoint?>(null) }
    var lock by remember { mutableStateOf(false) }
    var writing by remember { mutableStateOf(false) }
    var result by remember { mutableStateOf<Pair<Boolean, String>?>(null) }
    val nfc = NfcTags.state(context)

    NfcListener(enabled = selected != null && !writing) { tag ->
        val point = selected ?: return@NfcListener
        writing = true
        scope.launch {
            val error = withContext(Dispatchers.IO) { NfcTags.write(tag, urlOf(point), lock) }
            writing = false
            selected = null
            result = if (error == null) true to "Etiqueta de ${point.code} · ${point.name} grabada${if (lock) " y bloqueada" else ""}. Pegala en el punto."
            else false to error
        }
    }

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        TopBar("Grabar etiquetas NFC", onBack)
        LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item {
                when (nfc) {
                    NfcTags.State.NONE -> Text("Este celular no tiene NFC. Grabá las etiquetas con otro celular.", color = Danger)
                    NfcTags.State.OFF -> TextButton({ context.startActivity(android.content.Intent(android.provider.Settings.ACTION_NFC_SETTINGS)) }) {
                        Text("El NFC está apagado: tocá para activarlo.", color = Danger)
                    }
                    NfcTags.State.ON -> Text("Elegí el punto y acercá la etiqueta a la parte de atrás del celular. Sirven etiquetas NTAG213, 215 o 216.",
                        color = Muted)
                }
            }
            item {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Checkbox(lock, { lock = it })
                    Column {
                        Text("Bloquear la etiqueta al grabarla")
                        Text("Recomendado una vez probada: nadie la puede regrabar. No se puede deshacer.", style = MaterialTheme.typography.bodySmall, color = Muted)
                    }
                }
            }
            if (points.isEmpty()) item { Text("No hay puntos de ronda sincronizados.", color = Muted) }
            items(points, key = { it.uuid }) { p ->
                Card(Modifier.fillMaxWidth().clickable(enabled = nfc == NfcTags.State.ON) { selected = p; result = null },
                    colors = CardDefaults.cardColors(containerColor = Color.White)) {
                    Column(Modifier.padding(14.dp)) {
                        Text("${p.code} · ${p.name}", fontWeight = FontWeight.SemiBold)
                        p.description?.takeIf { it.isNotBlank() }?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Muted) }
                    }
                }
            }
        }
    }
    selected?.let { p ->
        AlertDialog(onDismissRequest = { if (!writing) selected = null }, title = { Text("${p.code} · ${p.name}") }, text = {
            Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.fillMaxWidth()) {
                CircularProgressIndicator()
                Text(if (writing) "Grabando… no muevas el celular." else "Acercá la etiqueta al celular.", Modifier.padding(top = 12.dp))
            }
        }, confirmButton = { TextButton({ selected = null }, enabled = !writing) { Text("Cancelar") } })
    }
    result?.let { (ok, msg) ->
        AlertDialog({ result = null }, confirmButton = { TextButton({ result = null }) { Text("Entendido") } },
            title = { Text(if (ok) "Listo" else "No se grabó", color = if (ok) Ok else Danger) }, text = { Text(msg) })
    }
}
