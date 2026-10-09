package ar.com.securityapp.campo.ui.actions

import android.app.Application
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import ar.com.securityapp.campo.CampoApp
import ar.com.securityapp.campo.data.CapaAction
import ar.com.securityapp.campo.data.arr
import ar.com.securityapp.campo.data.bool
import ar.com.securityapp.campo.data.str
import ar.com.securityapp.campo.data.strOrNull
import ar.com.securityapp.campo.sync.SyncWorker
import ar.com.securityapp.campo.ui.observations.PhotosField
import ar.com.securityapp.campo.ui.observations.Section
import ar.com.securityapp.campo.ui.observations.TopBar
import ar.com.securityapp.campo.ui.observations.hour
import ar.com.securityapp.campo.ui.theme.Accent
import ar.com.securityapp.campo.ui.theme.Brand
import ar.com.securityapp.campo.ui.theme.BrandDark
import ar.com.securityapp.campo.ui.theme.Danger
import ar.com.securityapp.campo.ui.theme.Muted
import ar.com.securityapp.campo.ui.theme.Ok
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import java.time.LocalDate
import java.time.format.DateTimeFormatter

private val DAY = DateTimeFormatter.ofPattern("dd/MM/yyyy")
internal fun day(iso: String?): String = iso?.let { runCatching { LocalDate.parse(it).format(DAY) }.getOrNull() ?: it } ?: "sin fecha"

private val PRIORITY = mapOf("baja" to "Baja", "media" to "Media", "alta" to "Alta", "critica" to "Crítica")
private fun priorityColor(p: String) = when (p) { "critica" -> Danger; "alta" -> Color(0xFFE8730C); "media" -> Accent; else -> Muted }

data class DetailState(val loading: Boolean = false, val data: JsonObject? = null, val photos: List<Bitmap> = emptyList(), val offline: Boolean = false)

class ActionsViewModel(app: Application) : AndroidViewModel(app) {
    private val c = (app as CampoApp).container
    private val _items = MutableStateFlow(c.actions.list())
    val items: StateFlow<List<CapaAction>> = _items
    private val _detail = MutableStateFlow(DetailState())
    val detail: StateFlow<DetailState> = _detail

    init { viewModelScope.launch { c.dataChanged.collect { reload() } } }

    fun reload() { _items.value = c.actions.list() }

    fun start(a: CapaAction) {
        c.actions.start(a.uuid)
        SyncWorker.now(getApplication())
        reload()
    }

    fun close(a: CapaAction, text: String, photos: List<Uri>, onDone: () -> Unit) = viewModelScope.launch {
        c.actions.close(a.uuid, text, photos)
        SyncWorker.now(getApplication())
        reload()
        onDone()
    }

    fun openDetail(uuid: String) = viewModelScope.launch {
        _detail.value = DetailState(loading = true)
        val d = runCatching { c.actions.detail(uuid) }.getOrNull()
        _detail.value = DetailState(data = d, offline = d == null)
        val urls = d?.arr("files")?.mapNotNull { it as? JsonObject }?.filter { it.bool("image") }?.mapNotNull { it.strOrNull("url") }.orEmpty()
        val bitmaps = urls.take(6).mapNotNull { u -> runCatching { c.api.bytes(u)?.let { BitmapFactory.decodeByteArray(it, 0, it.size) } }.getOrNull() }
        _detail.value = _detail.value.copy(photos = bitmaps)
    }
}

/** Módulo Acciones: las mías primero (abiertas y en curso, ordenadas por vencimiento) y las demás que puedo ver. */
@Composable
fun AccionesScreen(onBack: () -> Unit, vm: ActionsViewModel = viewModel()) {
    var open by remember { mutableStateOf<String?>(null) }
    open?.let { uuid ->
        ActionDetail(vm, uuid, onBack = { open = null; vm.reload() })
        return
    }
    BackHandler(onBack = onBack)
    val items by vm.items.collectAsStateWithLifecycle()
    val mine = items.filter { it.mine }
    val others = items.filter { !it.mine }
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        TopBar("Acciones", onBack)
        LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item { Text("A mi cargo", style = MaterialTheme.typography.titleSmall, color = Muted) }
            if (mine.isEmpty()) item { Text("No tenés acciones a tu cargo.", color = Muted) }
            items(mine, key = { it.uuid }) { a -> ActionRow(a) { open = a.uuid } }
            if (others.isNotEmpty()) {
                item { Text("Otras que podés ver", style = MaterialTheme.typography.titleSmall, color = Muted, modifier = Modifier.padding(top = 8.dp)) }
                items(others, key = { it.uuid }) { a -> ActionRow(a) { open = a.uuid } }
            }
        }
    }
}

@Composable
private fun ActionRow(a: CapaAction, onClick: () -> Unit) {
    Card(Modifier.fillMaxWidth().clickable(onClick = onClick), colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Row(Modifier.padding(14.dp)) {
            Box(Modifier.padding(top = 4.dp).size(12.dp).background(priorityColor(a.priority), RoundedCornerShape(6.dp)))
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(a.code, fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.labelLarge)
                    Spacer(Modifier.weight(1f))
                    Text(if (a.overdue()) "VENCIDA · ${day(a.dueOn)}" else "Vence ${day(a.dueOn)}", style = MaterialTheme.typography.labelSmall,
                        color = if (a.overdue()) Danger else Muted, fontWeight = if (a.overdue()) FontWeight.Bold else FontWeight.Normal)
                }
                Text(a.title, maxLines = 2, overflow = TextOverflow.Ellipsis, style = MaterialTheme.typography.bodyMedium)
                Text(listOfNotNull(a.origin, a.observationCode, if (!a.mine) a.responsible else null).joinToString(" · "), style = MaterialTheme.typography.bodySmall, color = Muted)
                StatusLine(a)
            }
        }
    }
}

@Composable
private fun StatusLine(a: CapaAction) {
    when {
        a.failure != null -> Text("Rechazado: ${a.failure}", color = Danger, style = MaterialTheme.typography.bodySmall)
        a.pending != null -> Text(if (a.pending == "close") "Cierre pendiente de enviar" else "Pendiente de enviar", color = BrandDark,
            style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
        else -> Text(a.statusLabel, color = if (a.status == "verificada") Ok else Brand, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
private fun ActionDetail(vm: ActionsViewModel, uuid: String, onBack: () -> Unit) {
    val items by vm.items.collectAsStateWithLifecycle()
    val a = items.firstOrNull { it.uuid == uuid }
    var closing by remember { mutableStateOf(false) }
    LaunchedEffect(uuid) { vm.openDetail(uuid) }
    if (a == null) {
        BackHandler(onBack = onBack)
        Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
            TopBar("Acción", onBack)
            Text("Ya no tenés acceso a esta acción.", Modifier.padding(16.dp), color = Muted)
        }
        return
    }
    if (closing) { CloseForm(vm, a, onDone = { closing = false }); return }
    BackHandler(onBack = onBack)
    val d by vm.detail.collectAsStateWithLifecycle()
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        TopBar(a.code, onBack)
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Card(colors = CardDefaults.cardColors(containerColor = Color.White)) {
                Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    StatusLine(a)
                    Text(a.title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                    if (a.description.isNotBlank()) Text(a.description)
                    Info("Prioridad", PRIORITY[a.priority] ?: a.priority)
                    Info("Vence", day(a.dueOn) + if (a.overdue()) " (vencida)" else "")
                    Info("Responsable", a.responsible)
                    Info("Origen", listOfNotNull(a.origin, a.observationCode).joinToString(" · "))
                    a.closureText?.let { Info("Qué se hizo", it) }
                }
            }
            if (a.canStart && a.pending == null) Button({ vm.start(a) }, Modifier.fillMaxWidth().height(52.dp),
                colors = ButtonDefaults.buttonColors(containerColor = Brand)) { Text("La tomo (empezar)", fontWeight = FontWeight.Bold) }
            if (a.canClose && a.pending != "close") Button({ closing = true }, Modifier.fillMaxWidth().height(56.dp),
                colors = ButtonDefaults.buttonColors(containerColor = Accent, contentColor = BrandDark)) {
                Text("Cerrar con evidencia", fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
            }
            if (a.pending == "close") Text("Se envía solo con señal: primero suben las fotos y después el cierre.", color = Muted,
                style = MaterialTheme.typography.bodySmall)
            if (a.status == "cerrada" && a.pending == null) Text("Cerrada: falta que la verifique otra persona (se hace en la web).", color = Muted,
                style = MaterialTheme.typography.bodySmall)
            when {
                d.loading -> CircularProgressIndicator(color = Brand)
                d.offline -> Text("Sin conexión: la línea de tiempo y la evidencia se ven con señal.", color = Muted, style = MaterialTheme.typography.bodySmall)
                d.data != null -> {
                    if (d.photos.isNotEmpty()) Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        d.photos.forEach { Image(it.asImageBitmap(), null, Modifier.size(140.dp), contentScale = ContentScale.Crop) }
                    }
                    val events = d.data!!.arr("events").mapNotNull { it as? JsonObject }
                    if (events.isNotEmpty()) Text("Línea de tiempo", style = MaterialTheme.typography.titleSmall, color = Muted)
                    events.forEach { e ->
                        Column(Modifier.fillMaxWidth().background(Color.White, RoundedCornerShape(10.dp)).padding(10.dp)) {
                            Text(listOfNotNull(e.str("label"), e.strOrNull("actor")).joinToString(" · "), fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.bodyMedium)
                            e.strOrNull("comment")?.let { Text(it, style = MaterialTheme.typography.bodyMedium) }
                            Text(hour(e.strOrNull("at")), style = MaterialTheme.typography.labelSmall, color = Muted)
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun CloseForm(vm: ActionsViewModel, a: CapaAction, onDone: () -> Unit) {
    BackHandler(onBack = onDone)
    var text by remember { mutableStateOf("") }
    var photos by remember { mutableStateOf(listOf<Uri>()) }
    var error by remember { mutableStateOf<String?>(null) }
    var saving by remember { mutableStateOf(false) }
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        TopBar("Cerrar ${a.code}", onDone)
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            Text(a.title, fontWeight = FontWeight.SemiBold)
            Section("Qué se hizo") {
                OutlinedTextField(text, { text = it.take(2000) }, Modifier.fillMaxWidth().heightIn(min = 120.dp), placeholder = { Text("Contá cómo se resolvió") })
            }
            Section("Fotos de evidencia (${photos.size}/6)") { PhotosField(photos, 6) { photos = it } }
            if (a.evidence > 0) Text("Ya tiene ${a.evidence} evidencia(s) cargada(s) en la web.", color = Muted, style = MaterialTheme.typography.bodySmall)
            error?.let { Text(it, color = Danger) }
            Button({
                error = when {
                    text.trim().length < 5 -> "Contá qué se hizo."
                    photos.isEmpty() && a.evidence == 0 -> "Agregá al menos una foto de evidencia."
                    else -> null
                }
                if (error == null) { saving = true; vm.close(a, text, photos, onDone) }
            }, Modifier.fillMaxWidth().height(56.dp), enabled = !saving, colors = ButtonDefaults.buttonColors(containerColor = Accent, contentColor = BrandDark)) {
                Text("Cerrar acción", fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
            }
            Text("Si no hay señal queda guardado y se envía solo al reconectar. Después la verifica otra persona.", color = Muted,
                style = MaterialTheme.typography.bodySmall)
        }
    }
}

@Composable
private fun Info(label: String, value: String) {
    if (value.isBlank()) return
    Row { Text("$label: ", color = Muted, style = MaterialTheme.typography.bodySmall); Text(value, style = MaterialTheme.typography.bodySmall) }
}
