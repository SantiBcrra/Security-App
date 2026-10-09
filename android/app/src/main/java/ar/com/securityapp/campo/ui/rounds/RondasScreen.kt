package ar.com.securityapp.campo.ui.rounds

import android.Manifest
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.Check
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
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
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import ar.com.securityapp.campo.data.PatrolRound
import ar.com.securityapp.campo.data.RoundsRepository.ScanResult
import ar.com.securityapp.campo.ui.theme.Accent
import ar.com.securityapp.campo.ui.theme.Brand
import ar.com.securityapp.campo.ui.theme.BrandDark
import ar.com.securityapp.campo.ui.theme.Danger
import ar.com.securityapp.campo.ui.theme.Muted
import ar.com.securityapp.campo.ui.theme.Ok
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter

private val HOUR = DateTimeFormatter.ofPattern("dd/MM HH:mm").withZone(ZoneId.systemDefault())
private fun hour(iso: String?): String = iso?.let { runCatching { HOUR.format(Instant.parse(it)) }.getOrNull() } ?: ""

@Suppress("DEPRECATION")
@Composable
fun RondasScreen(onBack: () -> Unit, vm: RoundsViewModel = viewModel()) {
    val ui by vm.ui.collectAsStateWithLifecycle()
    val scan by vm.scan.collectAsStateWithLifecycle()
    var scanning by remember { mutableStateOf(false) }
    var confirmFinish by remember { mutableStateOf(false) }
    var simulate by remember { mutableStateOf(false) } // solo en la versión de prueba (emulador sin cámara apuntable)
    var startRoute by remember { mutableStateOf<Any?>(null) } // ruta elegida (o "libre") esperando el permiso de ubicación
    val locationLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
        val pending = startRoute
        startRoute = null
        vm.start(pending as? ar.com.securityapp.campo.data.PatrolRoute) // con o sin permiso: sin GPS igual se registra
    }
    fun begin(route: ar.com.securityapp.campo.data.PatrolRoute?) {
        if (vm.hasLocationPermission()) vm.start(route) else {
            startRoute = route ?: "libre"
            locationLauncher.launch(arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION))
        }
    }

    if (scanning) {
        QrScanner("Apuntá al QR del punto de control", onResult = { scanning = false; vm.onQr(it) }, onClose = { scanning = false })
        return
    }
    BackHandler(onBack = onBack)

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        Row(Modifier.fillMaxWidth().background(Brand).statusBarsPadding().padding(4.dp), verticalAlignment = Alignment.CenterVertically) {
            IconButton(onBack) { Icon(Icons.Filled.ArrowBack, "Volver", tint = Color.White) }
            Text("Rondas", color = Color.White, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
            if (ui.pending > 0) Text("${ui.pending} sin enviar", color = Accent, style = MaterialTheme.typography.labelMedium, modifier = Modifier.padding(end = 12.dp))
        }
        LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            if (ui.failed > 0) item {
                Card(colors = CardDefaults.cardColors(containerColor = Color(0xFFFDE3E5))) {
                    Text("${ui.failed} envío(s) rechazado(s) por el servidor. Revisalos en Ajustes.", Modifier.padding(14.dp), color = Danger)
                }
            }
            val active = ui.active
            if (active != null) {
                item { ActiveRoundCard(ui, active, onScan = { scanning = true }, onFinish = { confirmFinish = true }) }
                if (ar.com.securityapp.campo.BuildConfig.DEBUG) item {
                    TextButton({ simulate = true }) { Text("Simular escaneo (solo versión de prueba)", color = Muted) }
                }
                if (ui.activeRoute != null) {
                    items(ui.progress, key = { it.uuid }) { p -> ProgressRow(p) }
                } else if (ui.freeScans.isNotEmpty()) {
                    item { Text("Puntos registrados", style = MaterialTheme.typography.titleSmall, color = Muted) }
                    items(ui.freeScans, key = { it.uuid }) { p -> Text("✓ ${p.code} · ${p.name}", Modifier.padding(start = 4.dp)) }
                }
            } else {
                item { Text("Mis rutas", style = MaterialTheme.typography.titleSmall, color = Muted) }
                if (ui.routes.isEmpty()) item {
                    Text("No tenés rutas asignadas. Podés hacer una ronda libre, escaneando los puntos que recorras.", color = Muted)
                }
                items(ui.routes, key = { it.uuid }) { route ->
                    Card(colors = CardDefaults.cardColors(containerColor = Color.White)) {
                        Row(Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(route.name, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
                                Text(listOfNotNull("${route.points.size} puntos", route.expectedMinutes?.let { "≈ $it min" }, route.frequency).joinToString(" · "),
                                    style = MaterialTheme.typography.bodySmall, color = Muted)
                            }
                            Button({ begin(route) }, enabled = !ui.busy, colors = ButtonDefaults.buttonColors(containerColor = Brand)) { Text("Iniciar") }
                        }
                    }
                }
                item {
                    OutlinedButton({ begin(null) }, Modifier.fillMaxWidth(), enabled = !ui.busy) { Text("Ronda libre (sin ruta)") }
                }
                if (ui.history.isNotEmpty()) {
                    item { Text("Últimas rondas", style = MaterialTheme.typography.titleSmall, color = Muted, modifier = Modifier.padding(top = 8.dp)) }
                    items(ui.history, key = { it.uuid }) { r -> HistoryRow(r, ui.routes.firstOrNull { it.uuid == r.routeUuid }?.name) }
                }
            }
        }
    }

    if (confirmFinish) {
        val missing = ui.progress.count { !it.done }
        AlertDialog(
            onDismissRequest = { confirmFinish = false },
            title = { Text("¿Finalizar la ronda?") },
            text = { Text(if (missing > 0) "Faltan $missing punto(s) de la ruta: quedan como salteados." else "Se registraron todos los puntos.") },
            confirmButton = { TextButton({ confirmFinish = false; vm.finish() }) { Text("Finalizar") } },
            dismissButton = { TextButton({ confirmFinish = false }) { Text("Seguir") } },
        )
    }
    if (simulate) {
        AlertDialog(
            onDismissRequest = { simulate = false },
            title = { Text("Simular escaneo") },
            text = {
                Column {
                    for (p in ui.points.values.sortedBy { it.code }) {
                        TextButton({ simulate = false; vm.onQr("https://servidor/ronda/punto/${p.uuid}") }) { Text("${p.code} · ${p.name}") }
                    }
                    if (ui.points.isEmpty()) Text("No hay puntos sincronizados.")
                }
            },
            confirmButton = { TextButton({ simulate = false }) { Text("Cerrar") } },
        )
    }
    scan?.let { result -> ScanDialog(result, onConfirmOutside = { vm.confirmOutsideRoute() }, onDismiss = { vm.dismissScan() }, onScanAgain = { vm.dismissScan(); scanning = true }) }
}

@Composable
private fun ActiveRoundCard(ui: RoundsUi, round: PatrolRound, onScan: () -> Unit, onFinish: () -> Unit) {
    val total = ui.progress.size
    val done = ui.progress.count { it.done }
    Card(colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Column(Modifier.padding(16.dp)) {
            Text("Ronda en curso", style = MaterialTheme.typography.labelLarge, color = Ok, fontWeight = FontWeight.Bold)
            Text(ui.activeRoute?.name ?: "Ronda libre", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
            Text("Empezó ${hour(round.startedAt)}", style = MaterialTheme.typography.bodySmall, color = Muted)
            if (total > 0) {
                LinearProgressIndicator(progress = { done.toFloat() / total }, modifier = Modifier.fillMaxWidth().padding(top = 12.dp), color = Ok)
                Text("$done de $total puntos", style = MaterialTheme.typography.bodyMedium, modifier = Modifier.padding(top = 4.dp))
            }
            Button(onScan, Modifier.fillMaxWidth().padding(top = 14.dp).height(60.dp), enabled = !ui.busy,
                colors = ButtonDefaults.buttonColors(containerColor = Accent, contentColor = BrandDark)) {
                Text("Escanear punto", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
            }
            OutlinedButton(onFinish, Modifier.fillMaxWidth().padding(top = 8.dp)) { Text("Finalizar ronda") }
        }
    }
}

@Composable
private fun ProgressRow(p: ProgressItem) {
    Row(Modifier.fillMaxWidth().background(if (p.next) Color(0xFFFDF3CF) else Color.Transparent, RoundedCornerShape(10.dp)).padding(10.dp),
        verticalAlignment = Alignment.CenterVertically) {
        Box(Modifier.size(30.dp).background(if (p.done) Ok else if (p.next) Accent else Color(0xFFE8EAF0), CircleShape), contentAlignment = Alignment.Center) {
            if (p.done) Icon(Icons.Filled.Check, null, tint = Color.White, modifier = Modifier.size(18.dp))
            else Text("${p.n}", fontWeight = FontWeight.Bold, color = BrandDark)
        }
        Spacer(Modifier.width(12.dp))
        Column(Modifier.weight(1f)) {
            Text(p.point?.name ?: "Punto no sincronizado", fontWeight = if (p.next) FontWeight.Bold else FontWeight.Normal)
            Text(listOfNotNull(p.point?.code, if (p.point?.critical == true) "punto crítico" else null, if (p.next) "próximo" else null).joinToString(" · "),
                style = MaterialTheme.typography.bodySmall, color = if (p.point?.critical == true) Danger else Muted)
        }
    }
}

@Composable
private fun HistoryRow(r: PatrolRound, routeName: String?) {
    Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(routeName ?: "Ronda libre", fontWeight = FontWeight.SemiBold)
            Text("${hour(r.startedAt)}${r.finishedAt?.let { " → " + hour(it) } ?: ""}", style = MaterialTheme.typography.bodySmall, color = Muted)
        }
        val (label, color) = when (r.status) {
            "completa" -> "Completa" to Ok
            "incompleta" -> "Incompleta" to Danger
            else -> r.status to Muted
        }
        Text(if (r.local) "$label · sin enviar" else label, color = color, style = MaterialTheme.typography.labelMedium)
    }
}

@Composable
private fun ScanDialog(result: ScanResult, onConfirmOutside: () -> Unit, onDismiss: () -> Unit, onScanAgain: () -> Unit) {
    val (title, text) = when (result) {
        is ScanResult.Ok -> "✓ ${result.point.name}" to buildString {
            append(if (result.hasGps) "Punto registrado con ubicación." else "Punto registrado SIN ubicación (activá el GPS).")
            if (result.remaining > 0) append("\nFaltan ${result.remaining} punto(s).") else append("\nNo quedan puntos de la ruta.")
        }
        is ScanResult.AlreadyScanned -> "Ya registrado" to "${result.point.name} ya está registrado en esta ronda."
        is ScanResult.NotInRoute -> "Fuera de la ruta" to "${result.point.name} no es parte de la ruta en curso. ¿Registrarlo igual?"
        ScanResult.UnknownQr -> "QR no reconocido" to "No es un punto de ronda de esta empresa, o falta sincronizar (tocá ↻ en el inicio con señal)."
        ScanResult.NoRound -> "Sin ronda en curso" to "Iniciá una ronda antes de escanear."
    }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = { Text(text) },
        confirmButton = {
            when (result) {
                is ScanResult.NotInRoute -> TextButton(onConfirmOutside) { Text("Registrar") }
                is ScanResult.Ok -> if (result.remaining > 0) TextButton(onScanAgain) { Text("Escanear el próximo") } else TextButton(onDismiss) { Text("Listo") }
                else -> TextButton(onScanAgain) { Text("Escanear otro") }
            }
        },
        dismissButton = { TextButton(onDismiss) { Text(if (result is ScanResult.NotInRoute) "Cancelar" else "Cerrar") } },
    )
}
