package ar.com.securityapp.campo.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.heightIn
import androidx.compose.runtime.LaunchedEffect
import androidx.lifecycle.compose.collectAsStateWithLifecycle
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
fun SettingsScreen(vm: SessionViewModel, me: Me, onBack: () -> Unit, showTopBar: Boolean = true) {
    BackHandler(onBack = onBack)
    var confirmLogout by remember { mutableStateOf(false) }
    var failed by remember { mutableStateOf(vm.failedOps()) }
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        if (showTopBar) Row(Modifier.fillMaxWidth().background(Brand).statusBarsPadding().padding(4.dp), verticalAlignment = Alignment.CenterVertically) {
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
            if (vm.canPreview(me)) PreviewCard(vm)
            if (me.can("usuarios", "editar") && !me.isPreview) {
                // Detalle técnico: solo para administradores (el guardia no lo necesita).
                val counts = vm.sync.collectAsStateWithLifecycle().value.counts
                val labels = mapOf("sectors" to "sectores", "equipment" to "equipos", "employees" to "empleados", "patrol_routes" to "rutas",
                    "patrol_points" to "puntos de ronda", "actions" to "acciones", "inspection_schedule" to "inspecciones programadas")
                Text("Datos guardados en el celular", style = MaterialTheme.typography.titleSmall, color = Muted, modifier = Modifier.padding(top = 16.dp))
                Text(counts.filter { it.value > 0 }.map { "${it.value} ${labels[it.key]}" }.joinToString(" · ").ifEmpty { "Sin datos todavía" },
                    style = MaterialTheme.typography.bodySmall, color = Muted, modifier = Modifier.padding(top = 4.dp))
            }
            if (failed.isNotEmpty()) {
                Text("Envíos rechazados por el servidor", style = MaterialTheme.typography.titleSmall, color = Danger, modifier = Modifier.padding(top = 16.dp))
                for (op in failed) {
                    Card(Modifier.fillMaxWidth().padding(top = 8.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
                        Column(Modifier.padding(12.dp)) {
                            Text(OP_LABELS[op.type] ?: op.type, fontWeight = FontWeight.SemiBold)
                            Text(op.error ?: "", style = MaterialTheme.typography.bodySmall, color = Danger)
                            Row {
                                TextButton({ vm.retryOp(op); failed = vm.failedOps() }) { Text("Reintentar") }
                                TextButton({ vm.discardOp(op); failed = vm.failedOps() }) { Text("Descartar", color = Muted) }
                            }
                        }
                    }
                }
            }
            if (me.isPreview) {
                OutlinedButton({ vm.exitPreview(); onBack() }, Modifier.fillMaxWidth().padding(top = 16.dp)) { Text("Salir de la vista previa") }
            } else {
                OutlinedButton({ confirmLogout = true }, Modifier.fillMaxWidth().padding(top = 16.dp)) { Text("Cerrar sesión", color = Danger) }
                Text("Al cerrar sesión se borra del celular todo lo descargado.", style = MaterialTheme.typography.bodySmall, color = Muted,
                    modifier = Modifier.padding(top = 6.dp))
            }
        }
    }
    if (confirmLogout) {
        AlertDialog(
            onDismissRequest = { confirmLogout = false },
            title = { Text("¿Cerrar sesión?") },
            text = {
                val pending = vm.pendingCount()
                Text(if (pending > 0) "Hay $pending registro(s) sin enviar (sin señal). Si cerrás sesión SE PIERDEN. Conectate y sincronizá antes."
                     else "Vas a tener que volver a ingresar con tu usuario y contraseña.")
            },
            confirmButton = { TextButton({ confirmLogout = false; vm.logout() }) { Text("Cerrar sesión", color = Danger) } },
            dismissButton = { TextButton({ confirmLogout = false }) { Text("Cancelar") } },
        )
    }
}

/**
 * "Ver como" (típico de los sistemas con roles): el administrador elige un usuario y ve la app exactamente como él,
 * con sus módulos, sus botones y los datos que le bajan a él. Solo lectura.
 */
@Composable
private fun PreviewCard(vm: SessionViewModel) {
    val state by vm.preview.collectAsStateWithLifecycle()
    var open by remember { mutableStateOf(false) }
    var users by remember { mutableStateOf<List<SessionViewModel.PreviewUser>?>(null) }
    var loadError by remember { mutableStateOf<String?>(null) }
    Card(Modifier.fillMaxWidth().padding(top = 16.dp), colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Column(Modifier.padding(16.dp)) {
            Text("Ver la app como otro usuario", fontWeight = FontWeight.SemiBold)
            Text("Para saber qué ve exactamente un guardia o un supervisor: sus módulos, sus botones y sus datos. Es solo una vista previa: " +
                "podés tocar todo, pero no se guarda ni se envía nada.", style = MaterialTheme.typography.bodySmall, color = Muted, modifier = Modifier.padding(top = 4.dp))
            (state.error)?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Danger, modifier = Modifier.padding(top = 6.dp)) }
            OutlinedButton({ vm.clearPreviewNotice(); open = true }, Modifier.fillMaxWidth().padding(top = 8.dp), enabled = !state.busy) {
                Text(if (state.busy) "Entrando…" else "Elegir usuario")
            }
        }
    }
    if (open) {
        LaunchedEffect(Unit) {
            loadError = null
            users = try { vm.previewUsers() } catch (e: Exception) { loadError = if (e is ar.com.securityapp.campo.data.OfflineException) "Hace falta señal." else e.message; emptyList() }
        }
        AlertDialog(
            onDismissRequest = { open = false },
            title = { Text("Ver como…") },
            text = {
                Column(Modifier.heightIn(max = 420.dp).verticalScroll(rememberScrollState())) {
                    val list = users
                    when {
                        loadError != null -> Text(loadError!!, color = Danger)
                        list == null -> Text("Cargando…", color = Muted)
                        list.isEmpty() -> Text("No hay otros usuarios activos.", color = Muted)
                        else -> list.groupBy { it.role }.forEach { (role, people) ->
                            Text(role, style = MaterialTheme.typography.labelLarge, color = Muted, modifier = Modifier.padding(top = 8.dp, bottom = 2.dp))
                            people.forEach { u ->
                                Text(u.name, style = MaterialTheme.typography.bodyLarge, modifier = Modifier.fillMaxWidth()
                                    .clickable { open = false; vm.enterPreview(u) }.padding(vertical = 10.dp))
                            }
                        }
                    }
                }
            },
            confirmButton = { TextButton({ open = false }) { Text("Cancelar") } },
        )
    }
}

private val OP_LABELS = mapOf("round.start" to "Inicio de ronda", "round.scan" to "Escaneo de punto", "round.finish" to "Fin de ronda", "patrol.point_locate" to "Ubicación de punto", "device.status" to "Estado del celular")

@Composable
private fun Row2(label: String, value: String) {
    Column(Modifier.padding(vertical = 4.dp)) {
        Text(label, style = MaterialTheme.typography.labelMedium, color = Muted)
        Text(value, style = MaterialTheme.typography.bodyLarge)
    }
}
