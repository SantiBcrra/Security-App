package ar.com.securityapp.campo.ui.observations

import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.PickVisualMediaRequest
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.Close
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.core.content.FileProvider
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import ar.com.securityapp.campo.data.CatalogItem
import ar.com.securityapp.campo.data.Observation
import ar.com.securityapp.campo.data.arr
import ar.com.securityapp.campo.data.str
import ar.com.securityapp.campo.data.strOrNull
import ar.com.securityapp.campo.data.bool
import ar.com.securityapp.campo.ui.rounds.QrScanner
import ar.com.securityapp.campo.ui.theme.Accent
import ar.com.securityapp.campo.ui.theme.Brand
import ar.com.securityapp.campo.ui.theme.BrandDark
import ar.com.securityapp.campo.ui.theme.Danger
import ar.com.securityapp.campo.ui.theme.Muted
import ar.com.securityapp.campo.ui.theme.Ok
import kotlinx.serialization.json.JsonObject
import java.io.File
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter

private val HOUR = DateTimeFormatter.ofPattern("dd/MM HH:mm").withZone(ZoneId.systemDefault())
internal fun hour(iso: String?): String = iso?.let { runCatching { HOUR.format(Instant.parse(it)) }.getOrNull() ?: it.take(16).replace('T', ' ') } ?: ""

internal fun color(hex: String?): Color = runCatching { Color(android.graphics.Color.parseColor(hex)) }.getOrDefault(Muted)

@Composable
internal fun TopBar(title: String, onBack: () -> Unit, trailing: @Composable () -> Unit = {}) {
    Row(Modifier.fillMaxWidth().background(Brand).statusBarsPadding().padding(4.dp), verticalAlignment = Alignment.CenterVertically) {
        IconButton(onBack) { Icon(Icons.Filled.ArrowBack, "Volver", tint = Color.White) }
        Text(title, color = Color.White, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
        trailing()
    }
}

/** Módulo Observaciones: lista (mías + visibles), alta offline y detalle. */
@Composable
fun ObservacionesScreen(onBack: () -> Unit, canCreate: Boolean, vm: ObservationsViewModel = viewModel()) {
    var page by remember { mutableStateOf<String?>(null) } // null = lista, "nueva", o uuid del detalle
    when (val p = page) {
        "nueva" -> { ObservationForm(vm, onBack = { page = null }); return }
        null -> {}
        else -> { ObservationDetail(vm, p, onBack = { page = null; vm.reload() }); return }
    }
    BackHandler(onBack = onBack)
    val ui by vm.list.collectAsStateWithLifecycle()
    Scaffold(containerColor = MaterialTheme.colorScheme.background, topBar = { TopBar("Observaciones", onBack) }, floatingActionButton = {
        if (canCreate) ExtendedFloatingActionButton({ vm.newForm(); page = "nueva" }, containerColor = Accent, contentColor = BrandDark) {
            Icon(Icons.Filled.Add, null); Spacer(Modifier.width(8.dp)); Text("Reportar", fontWeight = FontWeight.Bold)
        }
    }) { padding ->
        LazyColumn(Modifier.fillMaxSize().padding(padding), contentPadding = PaddingValues(16.dp, 16.dp, 16.dp, 96.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item { Text("Mis reportes", style = MaterialTheme.typography.titleSmall, color = Muted) }
            if (ui.mine.isEmpty()) item { Text("Todavía no reportaste nada. Tocá \"Reportar\": funciona aunque no haya señal.", color = Muted) }
            items(ui.mine, key = { it.uuid }) { o -> ObsRow(o, ui, onClick = { page = o.uuid }) }
            if (ui.others.isNotEmpty()) {
                item { Text("Otras de mis sectores", style = MaterialTheme.typography.titleSmall, color = Muted, modifier = Modifier.padding(top = 8.dp)) }
                items(ui.others, key = { it.uuid }) { o -> ObsRow(o, ui, onClick = { page = o.uuid }) }
            }
        }
    }
}

@Composable
private fun ObsRow(o: Observation, ui: ObsListUi, onClick: () -> Unit) {
    val failure = ui.failures[o.uuid]
    val sev = o.severityUuid?.let { ui.severities[it] }
    Card(Modifier.fillMaxWidth().clickable(onClick = onClick), colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Row(Modifier.padding(14.dp), verticalAlignment = Alignment.Top) {
            Box(Modifier.padding(top = 4.dp).size(12.dp).background(color(sev?.color), RoundedCornerShape(6.dp)))
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(if (o.number > 0) "OBS-%06d".format(o.number) else "Nueva", fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.labelLarge)
                    Spacer(Modifier.width(8.dp))
                    if (o.imminent) Text("INMINENTE", color = Danger, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.Bold)
                    Spacer(Modifier.weight(1f))
                    Text(hour(o.createdAt), style = MaterialTheme.typography.labelSmall, color = Muted)
                }
                Text(o.description, maxLines = 2, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.bodyMedium)
                Text(listOfNotNull(o.sectorUuid?.let { ui.sectors[it] }, if (o.photos > 0) "${o.photos} foto(s)" else null).joinToString(" · "),
                    style = MaterialTheme.typography.bodySmall, color = Muted)
                when {
                    failure != null -> Text("Rechazada: $failure", color = Danger, style = MaterialTheme.typography.bodySmall)
                    o.local -> Text("Pendiente de enviar (se manda sola con señal)", color = BrandDark, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                    else -> Text(o.statusLabel, color = if (o.status == "cerrada") Ok else Brand, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                }
            }
        }
    }
}

// ── formulario ───────────────────────────────────────────────────

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun ObservationForm(vm: ObservationsViewModel, onBack: () -> Unit) {
    val f by vm.form.collectAsStateWithLifecycle()
    val context = LocalContext.current
    var scanning by remember { mutableStateOf(false) }
    var pickSector by remember { mutableStateOf(false) }
    var pickEquipment by remember { mutableStateOf(false) }
    var pickRisk by remember { mutableStateOf(false) }
    var confirmImminent by remember { mutableStateOf(false) }
    var qrError by remember { mutableStateOf<String?>(null) }
    if (scanning) {
        QrScanner("Apuntá al QR del equipo", onResult = { raw ->
            scanning = false
            if (!vm.onEquipmentQr(raw)) qrError = "Ese QR no es de un equipo de la empresa (o el equipo no está sincronizado)."
        }, onClose = { scanning = false })
        return
    }
    BackHandler(onBack = onBack)
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        TopBar("Reportar observación", onBack)
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            Section("¿Qué es?") { Chips(f.categories, f.category) { id -> vm.update { it.copy(category = id) } } }
            Section("Severidad") { Chips(f.severities, f.severity, colored = true) { id -> vm.update { it.copy(severity = id) } } }
            if (f.risks.isNotEmpty()) Section("Tipo de riesgo (opcional)") {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    OutlinedButton({ pickRisk = true }, Modifier.weight(1f)) {
                        Text(f.risks.firstOrNull { it.uuid == f.risk }?.name ?: "Elegir tipo de riesgo", maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                    if (f.risk != null) IconButton({ vm.update { it.copy(risk = null) } }) { Icon(Icons.Filled.Close, "Quitar tipo de riesgo") }
                }
            }
            Section("Dónde") {
                OutlinedButton({ pickSector = true }, Modifier.fillMaxWidth()) {
                    Text(f.sectors.firstOrNull { it.uuid == f.sector }?.label ?: "Elegir sector", maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                Row(Modifier.padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton({ scanning = true }, Modifier.weight(1f)) { Text("Escanear equipo") }
                    OutlinedButton({ pickEquipment = true }, Modifier.weight(1f)) { Text("Elegir equipo") }
                }
                f.equipment.firstOrNull { it.uuid == f.equipmentUuid }?.let { eq ->
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text("Equipo: ${eq.code} · ${eq.name}", Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium)
                        IconButton({ vm.update { it.copy(equipmentUuid = null) } }) { Icon(Icons.Filled.Close, "Quitar equipo") }
                    }
                }
                OutlinedTextField(f.location, { v -> vm.update { it.copy(location = v.take(200)) } }, Modifier.fillMaxWidth().padding(top = 6.dp),
                    label = { Text("Lugar exacto (opcional)") }, singleLine = true)
                Text("GPS: ${f.gps}", style = MaterialTheme.typography.bodySmall, color = Muted, modifier = Modifier.padding(top = 4.dp))
            }
            Section("Qué viste") {
                OutlinedTextField(f.description, { v -> vm.update { it.copy(description = v.take(2000)) } }, Modifier.fillMaxWidth().heightIn(min = 120.dp),
                    placeholder = { Text("Describí la situación") })
            }
            Section("Fotos (${f.photos.size}/6)") { PhotosField(f.photos, 6) { list -> vm.update { it.copy(photos = list) } } }
            Card(colors = CardDefaults.cardColors(containerColor = if (f.imminent) Color(0xFFFDE3E5) else Color.White)) {
                Row(Modifier.padding(14.dp), verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("RIESGO INMINENTE", fontWeight = FontWeight.Bold, color = Danger)
                        Text("Alguien se puede lastimar ya. Avisa al instante a supervisores y SyH.", style = MaterialTheme.typography.bodySmall, color = Muted)
                    }
                    Switch(f.imminent, { on -> if (on) confirmImminent = true else vm.update { it.copy(imminent = false) } })
                }
            }
            if (f.anonymousAllowed) Row(verticalAlignment = Alignment.CenterVertically) {
                Checkbox(f.anonymous, { v -> vm.update { it.copy(anonymous = v) } })
                Text("Enviar de forma anónima (no queda tu nombre)")
            }
            f.errors.forEach { Text(it, color = Danger) }
            Button({ vm.save(onBack) }, Modifier.fillMaxWidth().height(56.dp), enabled = !f.saving,
                colors = ButtonDefaults.buttonColors(containerColor = Accent, contentColor = BrandDark)) {
                if (f.saving) CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp, color = BrandDark)
                else Text("Enviar reporte", fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
            }
            Text("Si no hay señal queda guardado en el celular y se envía solo al reconectar.", style = MaterialTheme.typography.bodySmall, color = Muted)
        }
    }

    if (pickSector) SearchDialog("Sector", f.sectors.map { it.uuid to it.label }, onPick = { id -> pickSector = false; vm.update { it.copy(sector = id) } },
        onDismiss = { pickSector = false })
    if (pickRisk) SearchDialog("Tipo de riesgo", f.risks.map { it.uuid to it.name }, onPick = { id -> pickRisk = false; vm.update { it.copy(risk = id) } },
        onDismiss = { pickRisk = false })
    if (pickEquipment) SearchDialog("Equipo", f.equipment.map { it.uuid to "${it.code} · ${it.name}" }, onPick = { id ->
        pickEquipment = false
        val eq = f.equipment.first { it.uuid == id }
        vm.update { it.copy(equipmentUuid = id, sector = it.sector ?: eq.sectorUuid) }
    }, onDismiss = { pickEquipment = false })
    if (confirmImminent) AlertDialog(
        onDismissRequest = { confirmImminent = false },
        title = { Text("¿Riesgo inminente?", color = Danger) },
        text = { Text("Si alguien puede lastimarse ahora: frená la tarea y alejá a las personas. El aviso sale al instante a supervisores y SyH.") },
        confirmButton = { TextButton({ confirmImminent = false; vm.update { it.copy(imminent = true) } }) { Text("Sí, es inminente", color = Danger) } },
        dismissButton = { TextButton({ confirmImminent = false }) { Text("No") } },
    )
    qrError?.let { msg -> AlertDialog({ qrError = null }, confirmButton = { TextButton({ qrError = null }) { Text("Entendido") } }, text = { Text(msg) }) }
}

@Composable
internal fun Section(title: String, content: @Composable () -> Unit) {
    Column {
        Text(title, style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(bottom = 6.dp))
        content()
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
internal fun Chips(items: List<CatalogItem>, selected: String?, colored: Boolean = false, onPick: (String) -> Unit) {
    if (items.isEmpty()) { Text("Sin opciones sincronizadas: sincronizá con señal.", color = Muted); return }
    FlowRow(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        items.forEach { item ->
            val on = item.uuid == selected
            val tone = if (colored) color(item.color) else Brand
            Box(Modifier.heightIn(min = 48.dp).background(if (on) tone else Color.White, RoundedCornerShape(12.dp))
                .border(1.dp, if (on) tone else Color(0xFFD5D8E0), RoundedCornerShape(12.dp)).clickable { onPick(item.uuid) }
                .padding(horizontal = 16.dp, vertical = 12.dp), contentAlignment = Alignment.Center) {
                Text(item.name, color = if (on) Color.White else MaterialTheme.colorScheme.onSurface, fontWeight = if (on) FontWeight.Bold else FontWeight.Normal)
            }
        }
    }
}

/** Sacar foto / galería + miniaturas con "quitar". Las fotos se achican recién al guardar (`Uploads.add`). */
@Composable
internal fun PhotosField(photos: List<Uri>, max: Int, onChange: (List<Uri>) -> Unit) {
    val context = LocalContext.current
    var cameraUri by remember { mutableStateOf<Uri?>(null) }
    val current by androidx.compose.runtime.rememberUpdatedState(photos)
    val camera = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { ok ->
        val uri = cameraUri
        if (ok && uri != null) onChange(current + uri)
    }
    val gallery = rememberLauncherForActivityResult(ActivityResultContracts.PickMultipleVisualMedia(6)) { uris ->
        if (uris.isNotEmpty()) onChange((current + uris).take(max))
    }
    Column {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedButton({
                val dir = File(context.cacheDir, "camera").apply { mkdirs() }
                val uri = FileProvider.getUriForFile(context, context.packageName + ".files", File(dir, "foto-${System.currentTimeMillis()}.jpg"))
                cameraUri = uri
                camera.launch(uri)
            }, enabled = photos.size < max, modifier = Modifier.weight(1f)) { Text("Sacar foto") }
            OutlinedButton({ gallery.launch(PickVisualMediaRequest(ActivityResultContracts.PickVisualMedia.ImageOnly)) },
                enabled = photos.size < max, modifier = Modifier.weight(1f)) { Text("Galería") }
        }
        if (photos.isNotEmpty()) Row(Modifier.horizontalScroll(rememberScrollState()).padding(top = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            photos.forEach { uri -> PhotoThumb(uri, onRemove = { onChange(current - uri) }) }
        }
    }
}

@Composable
private fun PhotoThumb(uri: Uri, onRemove: () -> Unit) {
    val context = LocalContext.current
    val bmp = remember(uri) {
        runCatching {
            context.contentResolver.openInputStream(uri)?.use {
                android.graphics.BitmapFactory.decodeStream(it, null, android.graphics.BitmapFactory.Options().apply { inSampleSize = 8 })
            }
        }.getOrNull()
    }
    Box(Modifier.size(88.dp)) {
        if (bmp != null) Image(bmp.asImageBitmap(), null, Modifier.fillMaxSize(), contentScale = ContentScale.Crop)
        else Box(Modifier.fillMaxSize().background(Color(0xFFE8EAF0)))
        Box(Modifier.align(Alignment.TopEnd).padding(4.dp).size(24.dp).background(Color(0x99000000), RoundedCornerShape(12.dp)).clickable(onClick = onRemove),
            contentAlignment = Alignment.Center) { Icon(Icons.Filled.Close, "Quitar foto", tint = Color.White, modifier = Modifier.size(16.dp)) }
    }
}

@Composable
internal fun SearchDialog(title: String, options: List<Pair<String, String>>, onPick: (String) -> Unit, onDismiss: () -> Unit) {
    var q by remember { mutableStateOf("") }
    AlertDialog(onDismissRequest = onDismiss, title = { Text(title) }, text = {
        Column {
            OutlinedTextField(q, { q = it }, Modifier.fillMaxWidth(), placeholder = { Text("Buscar…") }, singleLine = true)
            val found = options.filter { q.isBlank() || it.second.contains(q.trim(), ignoreCase = true) }.take(80)
            LazyColumn(Modifier.heightIn(max = 360.dp).padding(top = 8.dp)) {
                items(found, key = { it.first }) { (id, label) ->
                    Text(label, Modifier.fillMaxWidth().clickable { onPick(id) }.padding(vertical = 12.dp))
                }
                if (found.isEmpty()) item { Text("Nada coincide.", color = Muted) }
            }
        }
    }, confirmButton = { TextButton(onDismiss) { Text("Cerrar") } })
}

// ── detalle ──────────────────────────────────────────────────────

@Composable
private fun ObservationDetail(vm: ObservationsViewModel, uuid: String, onBack: () -> Unit) {
    LaunchedEffect(uuid) { vm.openDetail(uuid) }
    BackHandler(onBack = onBack)
    val d by vm.detail.collectAsStateWithLifecycle()
    val r = d.remote
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        TopBar(r?.str("code") ?: "Observación", onBack)
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            when {
                r != null -> RemoteDetail(r, d)
                d.loading -> CircularProgressIndicator(color = Brand)
                d.local != null -> {
                    val o = d.local!!
                    Text(if (d.failure != null) "Rechazada por el servidor" else "Pendiente de enviar", fontWeight = FontWeight.Bold,
                        color = if (d.failure != null) Danger else BrandDark)
                    d.failure?.let { Text(it, color = Danger) }
                    Text(o.description)
                    Text("Hora del hecho: ${hour(o.createdAt)} · ${o.photos} foto(s)", color = Muted, style = MaterialTheme.typography.bodySmall)
                    if (d.failure != null) Text("Podés reintentar o descartar el envío en Ajustes → Envíos rechazados.", color = Muted)
                }
                else -> Text("Sin conexión: el detalle completo se ve con señal.", color = Muted)
            }
        }
    }
}

@Composable
private fun RemoteDetail(r: JsonObject, d: DetailUi) {
    Card(colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(r.str("status_label"), fontWeight = FontWeight.Bold, color = Brand, modifier = Modifier.weight(1f))
                if (r.bool("imminent")) Text("INMINENTE", color = Danger, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.labelMedium)
            }
            Text(r.str("description"))
            Detail("Categoría", r.strOrNull("category")); Detail("Severidad", r.strOrNull("severity")); Detail("Riesgo", r.strOrNull("risk"))
            Detail("Sector", listOfNotNull(r.strOrNull("site"), r.strOrNull("sector")).joinToString(" › ").ifEmpty { null })
            Detail("Equipo", r.strOrNull("equipment")); Detail("Lugar", r.strOrNull("location"))
            Detail("Reportó", r.strOrNull("reporter") ?: "Anónimo"); Detail("Fecha", hour(r.strOrNull("created_at_device")))
            Detail("A cargo", r.strOrNull("assigned")); Detail("Acción", r.strOrNull("action")); Detail("Vence", r.strOrNull("action_due_on"))
        }
    }
    if (d.photos.isNotEmpty()) Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        d.photos.forEach { Image(it.asImageBitmap(), null, Modifier.size(140.dp), contentScale = ContentScale.Crop) }
    }
    val events = r.arr("events").mapNotNull { it as? JsonObject }
    if (events.isNotEmpty()) {
        Text("Línea de tiempo", style = MaterialTheme.typography.titleSmall, color = Muted)
        events.forEach { e ->
            Column(Modifier.fillMaxWidth().background(Color.White, RoundedCornerShape(10.dp)).padding(10.dp)) {
                Text(listOfNotNull(e.strOrNull("to_label") ?: eventLabel(e.str("type")), e.strOrNull("actor")).joinToString(" · "), fontWeight = FontWeight.SemiBold,
                    style = MaterialTheme.typography.bodyMedium)
                e.strOrNull("comment")?.let { Text(it, style = MaterialTheme.typography.bodyMedium) }
                Text(hour(e.strOrNull("at")), style = MaterialTheme.typography.labelSmall, color = Muted)
            }
        }
    }
}

private fun eventLabel(type: String) = when (type) {
    "created" -> "Reportada"; "comment" -> "Comentario"; "correction" -> "Corrección"; "imminent_alert" -> "Alerta de riesgo inminente"
    "photo" -> "Foto agregada"; else -> type.replace('_', ' ')
}

@Composable
private fun Detail(label: String, value: String?) {
    if (value.isNullOrBlank()) return
    Row { Text("$label: ", color = Muted, style = MaterialTheme.typography.bodySmall); Text(value, style = MaterialTheme.typography.bodySmall) }
}
