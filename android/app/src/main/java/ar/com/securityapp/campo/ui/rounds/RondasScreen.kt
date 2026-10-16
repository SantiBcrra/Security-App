package ar.com.securityapp.campo.ui.rounds

import androidx.activity.compose.BackHandler
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
import androidx.compose.runtime.LaunchedEffect
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
/** @param canManagePoints permiso rondas.editar: ubicar puntos con el GPS y grabar etiquetas NFC */
fun RondasScreen(onBack: () -> Unit, canManagePoints: Boolean = false, initialScan: Boolean = false, showTopBar: Boolean = true, vm: RoundsViewModel = viewModel()) {
    val ui by vm.ui.collectAsStateWithLifecycle()
    val scan by vm.scan.collectAsStateWithLifecycle()
    val autoRoutes by vm.autoRoutes.collectAsStateWithLifecycle()
    var scanning by remember { mutableStateOf(false) }
    var confirmFinish by remember { mutableStateOf(false) }
    var simulate by remember { mutableStateOf(false) } // solo en la versión de prueba (emulador sin cámara apuntable)
    var startRoute by remember { mutableStateOf<Any?>(null) } // ruta elegida (o "libre") esperando la revisión de permisos
    var startSlot by remember { mutableStateOf<ar.com.securityapp.campo.data.PatrolSlot?>(null) } // ronda programada que se cumple
    val context = androidx.compose.ui.platform.LocalContext.current
    fun begin(route: ar.com.securityapp.campo.data.PatrolRoute?, slot: ar.com.securityapp.campo.data.PatrolSlot? = null) {
        startSlot = slot
        if (Readiness.allGood(context, vm.needsSms())) vm.start(route, slot) else startRoute = route ?: "libre"
    }

    var writingTags by remember { mutableStateOf(false) }
    var nfcMessage by remember { mutableStateOf<String?>(null) }
    var locatingPoint by remember { mutableStateOf<ar.com.securityapp.campo.data.PatrolPoint?>(null) }
    LaunchedEffect(initialScan) { if (initialScan) scanning = true }
    if (writingTags) {
        NfcWriterScreen(ui.points.values.sortedBy { it.code }, vm::tagUrl, onBack = { writingTags = false })
        return
    }
    // Ronda en curso: acercar el celular a la etiqueta NFC del punto marca igual que el QR.
    ar.com.securityapp.campo.nfc.NfcListener(enabled = !scanning && scan == null && autoRoutes.isEmpty()) { tag ->
        val raw = ar.com.securityapp.campo.nfc.NfcTags.read(tag)
        if (raw == null) {
            ScanFeedback.play(context, ScanFeedback.Kind.ERROR)
            nfcMessage = "La etiqueta está vacía o no se pudo leer. Mantené el celular quieto sobre ella o escaneá el QR."
        }
        else vm.onQr(raw, method = "nfc")
    }
    // Cada resultado de escaneo (QR o NFC) suena y vibra distinto: registrado, ronda completa, ya marcado o error.
    androidx.compose.runtime.LaunchedEffect(scan) { scan?.let { ScanFeedback.play(context, ScanFeedback.forResult(it)) } }
    if (scanning) {
        QrScanner("Apuntá al QR del punto de control", onResult = { scanning = false; vm.onQr(it) }, onClose = { scanning = false })
        return
    }
    BackHandler(onBack = onBack)

    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        if (showTopBar) Row(Modifier.fillMaxWidth().background(Brand).statusBarsPadding().padding(4.dp), verticalAlignment = Alignment.CenterVertically) {
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
                ui.progress.firstOrNull { it.next }?.point?.let { next -> if (ui.guidance && next.lat != null && next.lng != null) item { NextPointGuide(vm, next) } }
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
                item {
                    OutlinedButton({ scanning = true }, Modifier.fillMaxWidth(), enabled = !ui.busy) { Text("Escanear primer punto (inicia la ronda)") }
                    Text("Si el punto pertenece a una sola ruta asignada, se inicia esa ruta. Si no, se crea una ronda libre.", style = MaterialTheme.typography.bodySmall, color = Muted)
                }
                if (ui.slots.isNotEmpty()) {
                    item { Text("Próximas rondas programadas", style = MaterialTheme.typography.titleSmall, color = Muted) }
                    items(ui.slots, key = { "slot-${it.uuid}" }) { slot ->
                        val route = ui.routes.firstOrNull { it.uuid == slot.routeUuid }
                        SlotRow(slot, route?.name ?: "Ruta no sincronizada", enabled = route != null && !ui.busy) { begin(route, slot) }
                    }
                }
                item { Text("Mis rutas", style = MaterialTheme.typography.titleSmall, color = Muted) }
                if (ui.routes.isEmpty()) item {
                    Text("No tenés rutas asignadas. Podés hacer una ronda libre, escaneando los puntos que recorras.", color = Muted)
                }
                items(ui.routes, key = { it.uuid }) { route ->
                    Card(colors = CardDefaults.cardColors(containerColor = Color.White)) {
                        Row(Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(route.name, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
                                Text(listOfNotNull("${route.points.size} puntos", route.expectedMinutes?.let { "≈ $it min" },
                                    mapOf("secuencial" to "en orden", "azar" to "orden al azar")[route.orderMode]).joinToString(" · "),
                                    style = MaterialTheme.typography.bodySmall, color = Muted)
                            }
                            Button({ begin(route) }, enabled = !ui.busy, colors = ButtonDefaults.buttonColors(containerColor = Brand)) { Text("Iniciar") }
                        }
                    }
                }
                item {
                    OutlinedButton({ begin(null) }, Modifier.fillMaxWidth(), enabled = !ui.busy) { Text("Ronda libre (sin ruta)") }
                }
                if (canManagePoints && ui.points.isNotEmpty()) item {
                    Text("Ubicar puntos", style = MaterialTheme.typography.titleSmall, color = Muted, modifier = Modifier.padding(top = 8.dp))
                    Text("Parate junto al punto y guardá su ubicación actual. El cambio queda en cola si no hay señal.", style = MaterialTheme.typography.bodySmall, color = Muted)
                }
                if (canManagePoints && ui.points.isNotEmpty()) items(ui.points.values.sortedBy { it.code }, key = { "loc-${it.uuid}" }) { point ->
                    Card(colors = CardDefaults.cardColors(containerColor = Color.White)) {
                        Row(Modifier.padding(horizontal = 12.dp, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) { Text("${point.code} · ${point.name}"); Text(if (point.lat == null || point.lng == null) "Sin coordenadas" else "%.5f, %.5f".format(java.util.Locale.US, point.lat, point.lng), style = MaterialTheme.typography.bodySmall, color = Muted) }
                            OutlinedButton({ locatingPoint = point }, enabled = !ui.busy) { Text("Tomar GPS") }
                        }
                    }
                }
                if (canManagePoints) item {
                    TextButton({ writingTags = true }, Modifier.fillMaxWidth()) { Text("Grabar etiquetas NFC de los puntos", color = Brand) }
                }
                if (ui.history.isNotEmpty()) {
                    item { Text("Últimas rondas", style = MaterialTheme.typography.titleSmall, color = Muted, modifier = Modifier.padding(top = 8.dp)) }
                    items(ui.history, key = { it.uuid }) { r -> HistoryRow(r, ui.routes.firstOrNull { it.uuid == r.routeUuid }?.name) }
                }
            }
        }
    }

    startRoute?.let { pending ->
        ReadyCheckDialog(needSms = vm.needsSms(), onStart = {
            startRoute = null
            vm.start(pending as? ar.com.securityapp.campo.data.PatrolRoute, startSlot)
        }, onDismiss = { startRoute = null })
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
    nfcMessage?.let { m -> AlertDialog({ nfcMessage = null }, confirmButton = { TextButton({ nfcMessage = null }) { Text("Entendido") } }, text = { Text(m) }) }
    ui.locationMessage?.let { m -> AlertDialog(onDismissRequest = vm::clearLocationMessage, confirmButton = { TextButton(vm::clearLocationMessage) { Text("Entendido") } }, text = { Text(m) }) }
    if (autoRoutes.isNotEmpty()) {
        AlertDialog(onDismissRequest = vm::cancelAutoRouteChoice, title = { Text("Elegí la ruta") },
            text = { Column { Text("Este punto pertenece a varias rutas asignadas:", style = MaterialTheme.typography.bodyMedium); autoRoutes.forEach { route ->
                TextButton({ vm.chooseAutoRoute(route) }, Modifier.fillMaxWidth()) { Text(route.name, modifier = Modifier.fillMaxWidth()) }
            } } },
            confirmButton = { TextButton(vm::cancelAutoRouteChoice) { Text("Cancelar") } })
    }
    locatingPoint?.let { point ->
        AlertDialog(onDismissRequest = { locatingPoint = null }, title = { Text("¿Tomar ubicación?") },
            text = { Text("Parate junto a ${point.code} · ${point.name} y mantené el celular quieto unos segundos. Se reemplazarán las coordenadas actuales.") },
            confirmButton = { TextButton({ locatingPoint = null; vm.locatePoint(point) }) { Text("Guardar ubicación") } },
            dismissButton = { TextButton({ locatingPoint = null }) { Text("Cancelar") } })
    }
    scan?.let { result -> ScanDialog(result, onConfirmOutside = { vm.confirmOutsideRoute() }, onDismiss = { vm.dismissScan() }, onScanAgain = { vm.dismissScan(); scanning = true },
        onFinish = { vm.dismissScan(); vm.finish() }) }
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
            NfcHint()
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
private fun ScanDialog(result: ScanResult, onConfirmOutside: () -> Unit, onDismiss: () -> Unit, onScanAgain: () -> Unit, onFinish: () -> Unit) {
    // Último punto de la ruta: la ronda se finaliza sola en unos segundos (salvo que elija seguir).
    if (result is ScanResult.Ok && result.remaining == 0) {
        AutoFinishDialog(result, onFinish = onFinish, onContinue = onDismiss)
        return
    }
    val (title, text) = when (result) {
        is ScanResult.Ok -> "✓ Registrado: ${result.point.name}" to buildString {
            append(if (result.hasGps) "Punto registrado con ubicación." else "Punto registrado SIN ubicación (activá el GPS).")
            result.expected?.let { append("\n⚠ Fuera de orden: tocaba ${it.code} · ${it.name}. Queda registrado así; seguí el orden de la lista.") }
            when (result.remaining) {
                null -> {}
                0 -> append("\nNo quedan puntos de la ruta.")
                else -> append("\nFaltan ${result.remaining} punto(s).")
            }
        }
        is ScanResult.AlreadyScanned -> "Ya registrado" to "${result.point.name} ya está registrado en esta ronda."
        is ScanResult.NotInRoute -> "Fuera de la ruta" to "${result.point.name} no es parte de la ruta en curso. ¿Registrarlo igual?"
        ScanResult.UnknownQr -> "QR no reconocido" to "No es un punto de ronda de esta empresa, o falta sincronizar (tocá ↻ en el inicio con señal)."
        ScanResult.NoRound -> "Sin ronda en curso" to "Iniciá una ronda antes de escanear."
    }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title, color = if (result is ScanResult.Ok && result.expected != null) BrandDark else if (result is ScanResult.Ok) Ok else if (result is ScanResult.AlreadyScanned) MaterialTheme.colorScheme.onSurface else Danger,
            fontWeight = FontWeight.Bold) },
        text = { Text(text) },
        confirmButton = {
            when (result) {
                is ScanResult.NotInRoute -> TextButton(onConfirmOutside) { Text("Registrar") }
                is ScanResult.Ok -> if (result.remaining != 0) TextButton(onScanAgain) { Text("Escanear el próximo") } else TextButton(onDismiss) { Text("Listo") }
                else -> TextButton(onScanAgain) { Text("Escanear otro") }
            }
        },
        dismissButton = { TextButton(onDismiss) { Text(if (result is ScanResult.NotInRoute) "Cancelar" else "Cerrar") } },
    )
}

/** Cómo marcar con NFC (o por qué no se puede) debajo del botón de escanear. */
@Composable
private fun NfcHint() {
    val context = androidx.compose.ui.platform.LocalContext.current
    when (ar.com.securityapp.campo.nfc.NfcTags.state(context)) {
        ar.com.securityapp.campo.nfc.NfcTags.State.ON -> Text("O acercá el celular a la etiqueta NFC del punto.", style = MaterialTheme.typography.bodySmall,
            color = Muted, modifier = Modifier.padding(top = 6.dp))
        ar.com.securityapp.campo.nfc.NfcTags.State.OFF -> TextButton({ context.startActivity(android.content.Intent(android.provider.Settings.ACTION_NFC_SETTINGS)) }) {
            Text("NFC apagado: tocá para activarlo y marcar acercando el celular", style = MaterialTheme.typography.bodySmall, color = Danger)
        }
        ar.com.securityapp.campo.nfc.NfcTags.State.NONE -> {}
    }
}

private val SLOT_HOUR = DateTimeFormatter.ofPattern("HH:mm").withZone(ZoneId.systemDefault())

/** Ronda programada: hora, estado (falta / es la hora / atrasada) y "Iniciar" cuando ya se puede. */
@Composable
private fun SlotRow(slot: ar.com.securityapp.campo.data.PatrolSlot, routeName: String, enabled: Boolean, onStart: () -> Unit) {
    val now = Instant.now()
    val today = java.time.LocalDate.now()
    val day = slot.dueAt.atZone(ZoneId.systemDefault()).toLocalDate()
    val dayText = when (day) { today -> "hoy"; today.plusDays(1) -> "mañana"; else -> day.format(DateTimeFormatter.ofPattern("dd/MM")) }
    val (status, color) = when {
        slot.late(now) -> "Atrasada: empezala ya (hasta las ${SLOT_HOUR.format(slot.deadlineAt)})" to Danger
        slot.canStart(now) -> "Es la hora" to Ok
        else -> "Faltan ${java.time.Duration.between(now, slot.dueAt).toMinutes().let { if (it >= 60) "${it / 60} h ${it % 60} min" else "$it min" }}" to Muted
    }
    Card(colors = CardDefaults.cardColors(containerColor = if (slot.late(now)) Color(0xFFFDE3E5) else Color.White)) {
        Row(Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("${SLOT_HOUR.format(slot.dueAt)} · $dayText", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                Text(routeName + (slot.schedule?.let { " · $it" } ?: ""), style = MaterialTheme.typography.bodyMedium)
                Text(status, style = MaterialTheme.typography.bodySmall, color = color, fontWeight = FontWeight.SemiBold)
            }
            Button(onStart, enabled = enabled && slot.canStart(now), colors = ButtonDefaults.buttonColors(containerColor = if (slot.late(now)) Danger else Brand)) { Text("Iniciar") }
        }
    }
}

/** Guía al próximo punto de la ruta: distancia y rumbo desde donde está el guardia, y "Cómo llegar" (mapa del celular). */
@Composable
private fun NextPointGuide(vm: RoundsViewModel, next: ar.com.securityapp.campo.data.PatrolPoint) {
    val context = androidx.compose.ui.platform.LocalContext.current
    var here by remember { mutableStateOf<ar.com.securityapp.campo.data.Fix?>(null) }
    androidx.compose.runtime.LaunchedEffect(next.uuid) {
        while (true) { vm.here()?.let { here = it }; kotlinx.coroutines.delay(15_000) }
    }
    Card(colors = CardDefaults.cardColors(containerColor = Color(0xFFFDF3CF))) {
        Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("Próximo punto", style = MaterialTheme.typography.labelMedium, color = BrandDark)
                Text("${next.code} · ${next.name}", fontWeight = FontWeight.Bold)
                val h = here
                Text(if (h == null) "Buscando tu ubicación…" else {
                    val d = distanceM(h.lat, h.lng, next.lat!!, next.lng!!)
                    if (d < 25) "Estás en el punto: marcalo" else "A ${if (d >= 1000) "%.1f km".format(d / 1000) else "${d.toInt()} m"} hacia el ${compass(h.lat, h.lng, next.lat, next.lng!!)}"
                }, style = MaterialTheme.typography.bodyMedium, color = BrandDark)
            }
            TextButton({
                val uri = android.net.Uri.parse("geo:0,0?q=${next.lat},${next.lng}(" + android.net.Uri.encode("${next.code} ${next.name}") + ")")
                runCatching { context.startActivity(android.content.Intent(android.content.Intent.ACTION_VIEW, uri)) }
            }) { Text("Cómo llegar", color = BrandDark, fontWeight = FontWeight.Bold) }
        }
    }
}

private fun distanceM(lat1: Double, lng1: Double, lat2: Double, lng2: Double): Double {
    val r = 6_371_000.0
    val dLat = Math.toRadians(lat2 - lat1)
    val dLng = Math.toRadians(lng2 - lng1)
    val a = Math.sin(dLat / 2).let { it * it } + Math.cos(Math.toRadians(lat1)) * Math.cos(Math.toRadians(lat2)) * Math.sin(dLng / 2).let { it * it }
    return 2 * r * Math.asin(Math.sqrt(a))
}

/** Rumbo en palabras (norte, noreste…). */
private fun compass(lat1: Double, lng1: Double, lat2: Double, lng2: Double): String {
    val y = Math.sin(Math.toRadians(lng2 - lng1)) * Math.cos(Math.toRadians(lat2))
    val x = Math.cos(Math.toRadians(lat1)) * Math.sin(Math.toRadians(lat2)) - Math.sin(Math.toRadians(lat1)) * Math.cos(Math.toRadians(lat2)) * Math.cos(Math.toRadians(lng2 - lng1))
    val deg = (Math.toDegrees(Math.atan2(y, x)) + 360) % 360
    return listOf("norte", "noreste", "este", "sureste", "sur", "suroeste", "oeste", "noroeste")[((deg + 22.5) / 45).toInt() % 8]
}

/** "Ronda completa": cuenta regresiva y se finaliza sola; "Seguir en la ronda" la deja abierta (finaliza a mano después). */
@Composable
private fun AutoFinishDialog(result: ScanResult.Ok, onFinish: () -> Unit, onContinue: () -> Unit) {
    var seconds by remember { mutableStateOf(AUTO_FINISH_SECONDS) }
    androidx.compose.runtime.LaunchedEffect(result.point.uuid) {
        while (seconds > 0) { kotlinx.coroutines.delay(1_000); seconds-- }
        onFinish()
    }
    AlertDialog(
        onDismissRequest = {}, // tocar afuera no cancela: o finaliza, o elige seguir
        title = { Text("✓ Ronda completa", color = Ok, fontWeight = FontWeight.Bold) },
        text = {
            Text(buildString {
                append("Registrado: ${result.point.name}.")
                result.expected?.let { append("\n⚠ Fuera de orden: tocaba ${it.code} · ${it.name}.") }
                if (!result.hasGps) append("\nSin ubicación (activá el GPS).")
                append("\n\nMarcaste todos los puntos. La ronda se finaliza en $seconds s.")
            })
        },
        confirmButton = { TextButton(onFinish) { Text("Finalizar ahora", fontWeight = FontWeight.Bold) } },
        dismissButton = { TextButton(onContinue) { Text("Seguir en la ronda") } },
    )
}

private const val AUTO_FINISH_SECONDS = 10
