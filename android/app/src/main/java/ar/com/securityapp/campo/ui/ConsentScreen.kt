package ar.com.securityapp.campo.ui

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.systemBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import ar.com.securityapp.campo.ui.theme.Accent
import ar.com.securityapp.campo.ui.theme.BrandDark

/** Consentimiento (celular personal, Ley 25.326): el texto viene del servidor y queda registrada la aceptación. */
@Composable
fun ConsentScreen(vm: SessionViewModel, state: Screen.Consent) {
    Column(Modifier.fillMaxSize().systemBarsPadding().padding(20.dp)) {
        Text("Antes de empezar", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
        Text("Leé cómo usa tus datos esta app", style = MaterialTheme.typography.bodyMedium, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Spacer(Modifier.height(16.dp))
        Card(Modifier.fillMaxWidth().weight(1f)) {
            Text(state.text, Modifier.verticalScroll(rememberScrollState()).padding(16.dp), style = MaterialTheme.typography.bodyLarge)
        }
        state.error?.let { Text(it, color = MaterialTheme.colorScheme.error, modifier = Modifier.padding(top = 8.dp)) }
        Spacer(Modifier.height(16.dp))
        Button({ vm.acceptConsent() }, Modifier.fillMaxWidth().height(52.dp), enabled = !state.busy,
            colors = ButtonDefaults.buttonColors(containerColor = Accent, contentColor = BrandDark)) {
            Text(if (state.busy) "Guardando…" else "Acepto", fontWeight = FontWeight.Bold)
        }
        OutlinedButton({ vm.logout() }, Modifier.fillMaxWidth().padding(top = 8.dp)) { Text("No acepto · salir") }
        Text("Versión del texto: ${state.version}", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant,
            modifier = Modifier.padding(top = 8.dp))
    }
}
