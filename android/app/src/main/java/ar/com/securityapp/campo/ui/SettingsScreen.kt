package ar.com.securityapp.campo.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
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
import ar.com.securityapp.campo.BuildConfig
import ar.com.securityapp.campo.data.Me
import ar.com.securityapp.campo.ui.theme.Brand
import ar.com.securityapp.campo.ui.theme.Danger
import ar.com.securityapp.campo.ui.theme.Muted

@Suppress("DEPRECATION")
@Composable
fun SettingsScreen(vm: SessionViewModel, me: Me, onBack: () -> Unit) {
    BackHandler(onBack = onBack)
    var confirmLogout by remember { mutableStateOf(false) }
    Column(Modifier.fillMaxSize()) {
        Row(Modifier.fillMaxWidth().background(Brand).statusBarsPadding().padding(4.dp), verticalAlignment = Alignment.CenterVertically) {
            IconButton(onBack) { Icon(Icons.Filled.ArrowBack, "Volver", tint = Color.White) }
            Text("Ajustes", color = Color.White, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
        }
        Column(Modifier.verticalScroll(rememberScrollState()).padding(16.dp)) {
            Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = Color.White)) {
                Column(Modifier.padding(16.dp)) {
                    Row2("Usuario", me.name)
                    Row2("Rol", me.role)
                    Row2("Empresa", "${me.companyName} (${me.companySlug})")
                    HorizontalDivider(Modifier.padding(vertical = 8.dp))
                    Row2("Versión de la app", "${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE})")
                    Row2("Servidor", vm.prefs.serverUrl)
                    Row2("Dispositivo", vm.prefs.deviceUuid.take(8) + "…")
                }
            }
            OutlinedButton({ confirmLogout = true }, Modifier.fillMaxWidth().padding(top = 16.dp)) { Text("Cerrar sesión", color = Danger) }
            Text("Al cerrar sesión se borra del celular todo lo descargado.", style = MaterialTheme.typography.bodySmall, color = Muted,
                modifier = Modifier.padding(top = 6.dp))
        }
    }
    if (confirmLogout) {
        AlertDialog(
            onDismissRequest = { confirmLogout = false },
            title = { Text("¿Cerrar sesión?") },
            text = { Text("Vas a tener que volver a ingresar con tu usuario y contraseña.") },
            confirmButton = { TextButton({ confirmLogout = false; vm.logout() }) { Text("Cerrar sesión", color = Danger) } },
            dismissButton = { TextButton({ confirmLogout = false }) { Text("Cancelar") } },
        )
    }
}

@Composable
private fun Row2(label: String, value: String) {
    Column(Modifier.padding(vertical = 4.dp)) {
        Text(label, style = MaterialTheme.typography.labelMedium, color = Muted)
        Text(value, style = MaterialTheme.typography.bodyLarge)
    }
}
