package ar.com.securityapp.campo.ui.logbook

import ar.com.securityapp.campo.ui.AppBackHandler
import ar.com.securityapp.campo.ui.theme.*

import android.app.Application
import kotlinx.serialization.json.jsonObject
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.Icons
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Checkbox
import androidx.compose.material3.FilterChip
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.ui.unit.dp
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import ar.com.securityapp.campo.CampoApp
import ar.com.securityapp.campo.data.KnownVisitor
import ar.com.securityapp.campo.data.AuthorizedVisitor
import ar.com.securityapp.campo.data.bool
import ar.com.securityapp.campo.data.LogEntry
import ar.com.securityapp.campo.data.LogbookRepository
import ar.com.securityapp.campo.data.Site
import ar.com.securityapp.campo.sync.SyncWorker
import ar.com.securityapp.campo.ui.observations.PhotosField
import ar.com.securityapp.campo.ui.observations.TopBar
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import java.time.Duration
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter

private val HOUR = DateTimeFormatter.ofPattern("HH:mm").withZone(ZoneId.systemDefault())
private val DAY_HOUR = DateTimeFormatter.ofPattern("dd/MM HH:mm").withZone(ZoneId.systemDefault())

data class LogbookState(val sites: List<Site> = emptyList(), val site: Site? = null, val entries: List<LogEntry> = emptyList(),
                        val inside: List<LogEntry> = emptyList(), val pendingClose: LogEntry? = null, val summary: Map<String, Int> = emptyMap(),
                        val message: String? = null, val busy: Boolean = false)

class LogbookViewModel(app: Application) : AndroidViewModel(app) {
    private val c = (app as CampoApp).container
    private val repo get() = c.logbook
    private val _ui = MutableStateFlow(LogbookState())
    val ui: StateFlow<LogbookState> = _ui

    /**
     * Aviso de turno para el libro: cuenta del puesto sin guardia de turno, o guardia (de alguna programación) sin turno iniciado.
     * Se anota igual: el servidor lo marca "sin guardia de turno" y avisa al supervisor. null = no hay que avisar.
     */
    fun dutyWarning(sharedDevice: Boolean): String? {
        if (c.api.previewToken != null) return null
        if (sharedDevice) return if (c.prefs.dutyGuardUuid == null)
            "No hay guardia de turno. Empezá tu turno en el inicio (Empezar mi turno, con tu nombre y PIN). Si anotás igual, queda marcado y se avisa al supervisor." else null
        val esGuardia = c.prefs.metaJson?.let { runCatching { c.api.json.parseToJsonElement(it).jsonObject.bool("es_guardia") }.getOrNull() } ?: false
        return if (esGuardia && c.shifts.current() == null)
            "No tenés turno iniciado. Empezá tu turno en el inicio (Mi turno → Empezar turno). Si anotás igual, queda marcado y se avisa al supervisor." else null
    }

    init {
        reload()
        viewModelScope.launch { c.dataChanged.collect { reload() } }
    }

    fun reload() {
        val sites = c.shifts.sites()
        val shift = c.shifts.current()
        val site = _ui.value.site?.let { s -> sites.firstOrNull { it.uuid == s.uuid } } ?: repo.site(sites, shift?.siteUuid)
        val (counts, inside) = site?.let { repo.summary(it.uuid, shift?.startedAt) } ?: (emptyMap<String, Int>() to emptyList())
        _ui.value = _ui.value.copy(sites = sites, site = site, entries = site?.let { repo.all(it.uuid).take(80) } ?: emptyList(), inside = inside,
            pendingClose = site?.let { repo.pendingClose(it.uuid) }, summary = counts)
    }

    fun choose(s: Site) { repo.chooseSite(s.uuid); _ui.value = _ui.value.copy(site = s); reload() }
    fun say(m: String?) { _ui.value = _ui.value.copy(message = m) }
    fun lookup(type: String, prefix: String): List<KnownVisitor> = repo.lookup(type, prefix)
    fun authorized(kind: String): List<AuthorizedVisitor> = _ui.value.site?.let { repo.authorized(it.uuid, kind) }.orEmpty()
    fun pending(e: LogEntry) = e.local || repo.pending(e.uuid)

    /** Lista de autorizados: cómo da este ingreso ahora (null = sin lista para el predio o sin datos todavía). */
    fun checkAuth(kind: String, f: Map<String, String?>): ar.com.securityapp.campo.data.LogbookRepository.AuthResult? {
        val site = _ui.value.site ?: return null
        return repo.checkAuth(site.uuid, kind, f["person_doc"], f["person_name"], f["plate"])
    }

    suspend fun create(kind: String, fields: Map<String, String?>, photos: List<Uri>): String? {
        val site = _ui.value.site ?: return "Elegí el predio."
        val error = repo.create(site.uuid, kind, fields, photos)
        if (error == null) { SyncWorker.now(getApplication()); reload(); say("Anotado.") }
        return error
    }

    fun exit(e: LogEntry, note: String?) { repo.exit(e, note); SyncWorker.now(getApplication()); reload(); say("Salida anotada.") }

    /** Cierre de turno firmado; si viene de "Terminar turno", termina también el turno. */
    fun close(text: String?, signature: String, endShift: Boolean) {
        val site = _ui.value.site ?: return
        repo.close(site.uuid, text, signature)
        if (endShift) c.shifts.current()?.let { s ->
            c.shifts.end(s)
            ar.com.securityapp.campo.tracking.ShiftTrackingService.stop(getApplication())
        }
        SyncWorker.now(getApplication())
        reload()
        say(if (endShift) "Turno cerrado y firmado. ¡Buen descanso!" else "Turno cerrado y firmado.")
    }

    fun receive(close: LogEntry, text: String?, signature: String) {
        repo.receive(close, text, signature); SyncWorker.now(getApplication()); reload(); say("Recibiste el puesto.")
    }
}

private fun ago(at: Instant): String {
    val m = Duration.between(at, Instant.now()).toMinutes().coerceAtLeast(0)
    return if (m < 60) "$m min" else "${m / 60} h ${m % 60} min"
}

/**
 * Libro de guardia en el celular. @param startPage "cerrar" (desde Terminar turno: al firmar termina el turno) o "recibir"
 */
@Composable
fun LogbookScreen(onBack: () -> Unit, canCreate: Boolean, showTopBar: Boolean = true, startPage: String? = null, onClosed: () -> Unit = {},
                  sharedDevice: Boolean = false, vm: LogbookViewModel = viewModel()) {
    val ui by vm.ui.collectAsStateWithLifecycle()
    var page by remember(startPage) { mutableStateOf(startPage) }
    val endShift = startPage == "cerrar"
    AppBackHandler { if (page != null && page != startPage) page = null else onBack() }
    when {
        page == "novedad" || page == "persona" || page == "vehiculo" -> { EntryForm(page!!, vm, vm.dutyWarning(sharedDevice), vm.authorized(page!!), onDone = { page = null }); return }
        page == "cerrar" -> { CloseForm(ui, endShift, onCancel = { if (startPage == "cerrar") onBack() else page = null },
            onSign = { t, s -> vm.close(t, s, endShift); if (endShift) onClosed() else page = null }); return }
        page == "recibir" && ui.pendingClose != null -> { ReceiveForm(ui.pendingClose!!, onCancel = { page = null },
            onSign = { t, s -> vm.receive(ui.pendingClose!!, t, s); page = null }); return }
    }
    var exiting by remember { mutableStateOf<LogEntry?>(null) }
    var pickSite by remember { mutableStateOf(false) }
    var showAuthorized by remember { mutableStateOf(false) }
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        if (showTopBar) TopBar("Libro de guardia", onBack)
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(ui.site?.name ?: "Elegí el predio", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                if (ui.sites.size > 1) TextButton({ pickSite = true }) { Text("Cambiar predio") }
            }
            ui.message?.let { Text(it, color = Ok, style = MaterialTheme.typography.bodySmall) }
            vm.dutyWarning(sharedDevice)?.let { DutyWarning(it) }
            if (ui.site == null) {
                Text("No hay predios cargados o no elegiste uno.", color = Muted)
                return@Column
            }
            ui.pendingClose?.takeIf { canCreate }?.let { pc ->
                Card(colors = CardDefaults.cardColors(containerColor = OkBg)) {
                    Column(Modifier.padding(12.dp)) {
                        Text("Relevo: ${pc.userName ?: "el guardia anterior"} cerró su turno a las ${HOUR.format(pc.occurredAt)}", fontWeight = FontWeight.SemiBold)
                        pc.text?.let { Text("Dejó anotado: $it", style = MaterialTheme.typography.bodySmall) }
                        Button({ page = "recibir" }, Modifier.padding(top = 8.dp), colors = ButtonDefaults.buttonColors(containerColor = Ok, contentColor = androidx.compose.ui.graphics.Color.White)) { Text("Recibir el puesto") }
                    }
                }
            }
            if (canCreate) Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                // "+" arriba y el nombre abajo, que se achica si no entra (celulares angostos o letra grande): nunca se corta.
                listOf("novedad" to "Novedad", "persona" to "Persona", "vehiculo" to "Vehículo").forEach { (k, l) ->
                    Button({ vm.say(null); page = k }, Modifier.weight(1f).heightIn(min = 64.dp), shape = RoundedCornerShape(12.dp),
                        contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 6.dp, vertical = 8.dp),
                        colors = ButtonDefaults.buttonColors(containerColor = Accent, contentColor = OnAccent)) {
                        Column(horizontalAlignment = Alignment.CenterHorizontally) {
                            androidx.compose.material3.Icon(androidx.compose.material.icons.Icons.Filled.Add, null, Modifier.size(22.dp))
                            ar.com.securityapp.campo.ui.FitText(l, style = MaterialTheme.typography.labelLarge.copy(fontWeight = FontWeight.Bold))
                        }
                    }
                }
            }
            if (canCreate) OutlinedButton({ showAuthorized = true }, Modifier.fillMaxWidth()) { Text("Ver lista de autorizados") }
            Text("Adentro ahora (${ui.inside.size})", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold)
            if (ui.inside.isEmpty()) Text("No queda nadie adentro.", color = Muted, style = MaterialTheme.typography.bodySmall)
            ui.inside.forEach { e ->
                Card(colors = CardDefaults.cardColors(containerColor = CardBg)) {
                    Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text((if (e.kind == "vehiculo") "🚗 " else "👤 ") + e.label.ifEmpty { "Sin datos" }, fontWeight = FontWeight.SemiBold)
                            Text("Desde ${DAY_HOUR.format(e.occurredAt)} (${ago(e.occurredAt)})" + (e.visiting?.let { " · $it" } ?: ""), style = MaterialTheme.typography.bodySmall, color = Muted)
                        }
                        if (canCreate) OutlinedButton({ exiting = e }) { Text("Salió") }
                    }
                }
            }
            if (canCreate) OutlinedButton({ page = "cerrar" }, Modifier.fillMaxWidth()) { Text("Cerrar mi turno (relevo con firma)") }
            Text("Últimas anotaciones", style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold)
            if (ui.entries.isEmpty()) Text("Todavía no hay anotaciones.", color = Muted, style = MaterialTheme.typography.bodySmall)
            ui.entries.forEach { e -> EntryRow(e, vm.pending(e)) }
        }
    }
    exiting?.let { e ->
        var note by remember(e.uuid) { mutableStateOf("") }
        AlertDialog(onDismissRequest = { exiting = null }, title = { Text("¿Salió?") },
            text = { Column {
                Text(e.label.ifEmpty { "Sin datos" }, fontWeight = FontWeight.SemiBold)
                OutlinedTextField(note, { note = it.take(255) }, Modifier.fillMaxWidth().padding(top = 8.dp), label = { Text("Observación (carga, remito…), opcional") })
            } },
            confirmButton = { TextButton({ vm.exit(e, note); exiting = null }) { Text("Anotar salida") } },
            dismissButton = { TextButton({ exiting = null }) { Text("Cancelar") } })
    }
    if (pickSite) AlertDialog(onDismissRequest = { pickSite = false }, title = { Text("¿Qué predio?") },
        text = { Column { ui.sites.forEach { s -> Text(s.name, Modifier.fillMaxWidth().clickable { vm.choose(s); pickSite = false }.padding(vertical = 12.dp)) } } },
        confirmButton = { TextButton({ pickSite = false }) { Text("Cancelar") } })
    if (showAuthorized) AuthorizedListDialog(vm.authorized("persona") + vm.authorized("vehiculo"), onDismiss = { showAuthorized = false })
}

/** "No hay guardia de turno" / "No tenés turno iniciado": se puede anotar igual (queda marcado y avisa al supervisor). */
@Composable
private fun DutyWarning(text: String) {
    Card(colors = CardDefaults.cardColors(containerColor = DangerBg)) {
        Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            Text("⚠", style = MaterialTheme.typography.titleLarge, modifier = Modifier.padding(end = 10.dp))
            Text(text, style = MaterialTheme.typography.bodyMedium, color = Danger, fontWeight = FontWeight.SemiBold)
        }
    }
}

/** Resultado de la lista de autorizados mientras el guardia escribe el DNI o la patente (también sin señal). */
@Composable
private fun AuthBanner(r: ar.com.securityapp.campo.data.LogbookRepository.AuthResult?) {
    r ?: return
    Card(colors = CardDefaults.cardColors(containerColor = if (r.ok) OkBg else if (r.status == "no_autorizado") DangerBg else WarnBg)) {
        Column(Modifier.fillMaxWidth().padding(12.dp)) {
            Text((if (r.ok) "✅ " else "⚠ ") + r.label, fontWeight = FontWeight.Bold, color = if (r.ok) Ok else if (r.status == "no_autorizado") Danger else BrandDark)
            r.who?.takeIf { it.isNotBlank() }?.let { Text(it, style = MaterialTheme.typography.bodySmall) }
            r.window?.takeIf { !r.ok }?.let { Text("Autorizado: $it", style = MaterialTheme.typography.bodySmall) }
            if (!r.ok) Text("Podés anotarlo igual: se avisa al supervisor. Si corresponde, consultá antes de dejarlo pasar.",
                style = MaterialTheme.typography.bodySmall, color = Muted)
        }
    }
}

@Composable
private fun AuthTag(status: String?) {
    status ?: return
    val ok = status == "autorizado"
    Text((if (ok) "✅ " else "⚠ ") + (ar.com.securityapp.campo.data.LogbookRepository.AUTH_LABELS[status] ?: status), style = MaterialTheme.typography.labelMedium,
        color = if (ok) Ok else if (status == "no_autorizado") Danger else BrandDark, fontWeight = FontWeight.SemiBold)
}

@Composable
private fun EntryRow(e: LogEntry, pending: Boolean) {
    val color = when { e.important -> DangerBg; e.kind == "cierre" || e.kind == "recepcion" -> NeutralBg; else -> CardBg }
    Card(colors = CardDefaults.cardColors(containerColor = color)) {
        Column(Modifier.padding(12.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(DAY_HOUR.format(e.occurredAt) + (e.folio?.let { " · folio $it" } ?: ""), style = MaterialTheme.typography.labelMedium, color = Muted, modifier = Modifier.weight(1f))
                if (pending) Text("enviando…", style = MaterialTheme.typography.labelSmall, color = BrandDark)
            }
            Text(LogbookRepository.KINDS[e.kind] ?: e.kind, fontWeight = FontWeight.SemiBold, color = if (e.important) Danger else BrandDark)
            if (e.isEntry) {
                Text(e.label.ifEmpty { "Sin datos" })
                AuthTag(e.authStatus)
                Text(if (e.exitedAt != null) "Salió ${DAY_HOUR.format(e.exitedAt)}" else "Sigue adentro", style = MaterialTheme.typography.bodySmall,
                    color = if (e.exitedAt != null) Ok else BrandDark)
            }
            e.text?.let { Text(it, style = MaterialTheme.typography.bodyMedium) }
            e.userName?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Muted) }
        }
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun EntryForm(kind: String, vm: LogbookViewModel, dutyWarning: String?, authorized: List<AuthorizedVisitor>, onDone: () -> Unit) {
    val f = remember(kind) { mutableStateMapOf<String, String>() }
    var photos by remember(kind) { mutableStateOf<List<Uri>>(emptyList()) }
    var error by remember { mutableStateOf<String?>(null) }
    var saving by remember { mutableStateOf(false) }
    var showAuthorized by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val title = mapOf("novedad" to "Nueva novedad", "persona" to "Ingreso de persona", "vehiculo" to "Ingreso de vehículo")[kind]!!
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        TopBar(title, onDone)
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            dutyWarning?.let { DutyWarning(it) }
            @Composable fun Field(key: String, label: String, caps: Boolean = false, lines: Int = 1) =
                OutlinedTextField(f[key] ?: "", { f[key] = it }, Modifier.fillMaxWidth(), label = { Text(label) }, minLines = lines, singleLine = lines == 1,
                    keyboardOptions = KeyboardOptions(capitalization = if (caps) KeyboardCapitalization.Characters else KeyboardCapitalization.Sentences))
            when (kind) {
                "novedad" -> {
                    Text("Tipo", style = MaterialTheme.typography.labelLarge)
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        LogbookRepository.CATEGORIES.forEach { (k, l) ->
                            FilterChip(f["category"] == k, { f["category"] = k }, label = { Text(l, fontWeight = if (k == "riesgo") FontWeight.Bold else null) },
                                colors = if (k == "riesgo") androidx.compose.material3.FilterChipDefaults.filterChipColors(selectedContainerColor = Danger,
                                    selectedLabelColor = androidx.compose.ui.graphics.Color.White, labelColor = Danger) else androidx.compose.material3.FilterChipDefaults.filterChipColors())
                        }
                    }
                    if (f["category"] == "riesgo") {
                        // Lo inseguro avisa siempre al instante: no depende de marcar "Importante".
                        Card(colors = CardDefaults.cardColors(containerColor = DangerBg)) {
                            Text("Se avisa AL INSTANTE a RRHH, Seguridad e Higiene y supervisores, y se abre una observación para corregirlo. " +
                                "Contá qué y dónde (ej.: \"cable pelado en el tablero del depósito 2\", \"fuga de gas en el tubo de la cocina\").",
                                style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(10.dp))
                        }
                    }
                    Field("text", "¿Qué pasó? (hora, lugar, quién, qué se hizo)", lines = 5)
                    if (f["category"] != "riesgo") Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.clickable { f["important"] = if (f["important"] == "1") "0" else "1" }) {
                        Checkbox(f["important"] == "1", { f["important"] = if (it) "1" else "0" }); Text("Importante: avisar al supervisor")
                    }
                }
                "persona" -> {
                    OutlinedButton({ showAuthorized = true }, Modifier.fillMaxWidth(), enabled = authorized.any { it.status() == "autorizado" }) {
                        Text("Elegir de autorizados")
                    }
                    Lookup("persona", f["person_doc"] ?: "", vm) { k ->
                        f["person_doc"] = k.doc ?: k.key; k.name?.let { f["person_name"] = it }; k.company?.let { f["company"] = it }; k.plate?.let { f["plate"] = it }
                    }
                    OutlinedTextField(f["person_doc"] ?: "", { f["person_doc"] = it }, Modifier.fillMaxWidth(), label = { Text("DNI (completa si ya vino)") }, singleLine = true)
                    Field("person_name", "Nombre y apellido")
                    AuthBanner(vm.checkAuth("persona", f))
                    Field("company", "Empresa")
                    Field("visiting", "A quién visita / sector")
                    Field("reason", "Motivo")
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Box(Modifier.weight(1f)) { Field("badge", "Tarjeta") }
                        Box(Modifier.weight(1f)) { Field("plate", "Patente", caps = true) }
                    }
                    Text("Alcanza con el nombre, el DNI o la empresa.", style = MaterialTheme.typography.bodySmall, color = Muted)
                }
                "vehiculo" -> {
                    OutlinedButton({ showAuthorized = true }, Modifier.fillMaxWidth(), enabled = authorized.any { it.status() == "autorizado" }) {
                        Text("Elegir de autorizados")
                    }
                    Lookup("vehiculo", f["plate"] ?: "", vm) { k ->
                        f["plate"] = k.plate ?: k.key; k.vehicleType?.let { f["vehicle_type"] = it }; k.driver?.let { f["driver"] = it }
                        k.doc?.let { f["person_doc"] = it }; k.company?.let { f["company"] = it }
                    }
                    OutlinedTextField(f["plate"] ?: "", { f["plate"] = it.uppercase() }, Modifier.fillMaxWidth(), label = { Text("Patente *") }, singleLine = true,
                        keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Characters))
                    AuthBanner(vm.checkAuth("vehiculo", f))
                    FlowRow(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        LogbookRepository.VEHICLE_TYPES.forEach { (k, l) -> FilterChip(f["vehicle_type"] == k, { f["vehicle_type"] = k }, label = { Text(l) }) }
                    }
                    Field("driver", "Conductor")
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        Box(Modifier.weight(1f)) { Field("person_doc", "DNI del conductor") }
                        Box(Modifier.weight(1f)) { Field("company", "Empresa") }
                    }
                    Field("visiting", "Destino / a quién")
                    Field("load_note", "Carga / remito")
                }
            }
            if (kind != "novedad") Field("text", "Observaciones (opcional)", lines = 2)
            Text("Fotos (opcional)", style = MaterialTheme.typography.labelLarge)
            PhotosField(photos, 4) { photos = it }
            error?.let { Text(it, color = Danger) }
            Button({
                saving = true
                scope.launch {
                    error = vm.create(kind, f.toMap(), photos)
                    saving = false
                    if (error == null) onDone()
                }
            }, Modifier.fillMaxWidth().height(52.dp), enabled = !saving) { Text("Anotar", fontWeight = FontWeight.Bold) }
            Spacer(Modifier.height(24.dp))
        }
    }
    if (showAuthorized) AuthorizedListDialog(authorized, onlyCurrent = true, onDismiss = { showAuthorized = false }) { a ->
        if (kind == "persona") {
            a.doc?.let { f["person_doc"] = it }; a.name?.let { f["person_name"] = it }; a.company?.let { f["company"] = it }
        } else {
            a.plate?.let { f["plate"] = it }; a.company?.let { f["company"] = it }
        }
        showAuthorized = false
    }
}

@Composable
private fun AuthorizedListDialog(items: List<AuthorizedVisitor>, onlyCurrent: Boolean = false, onDismiss: () -> Unit, onPick: ((AuthorizedVisitor) -> Unit)? = null) {
    val visible = items.filter { !onlyCurrent || it.status() == "autorizado" }
    AlertDialog(onDismissRequest = onDismiss, title = { Text(if (onPick == null) "Lista de autorizados" else "Elegí un autorizado") },
        text = {
            if (visible.isEmpty()) Text(if (onlyCurrent) "No hay autorizados vigentes para este predio." else "No hay autorizados cargados para este predio.", color = Muted)
            else Column(Modifier.heightIn(max = 420.dp).verticalScroll(rememberScrollState())) {
                visible.forEach { a ->
                    val status = a.status()
                    val row = Modifier.fillMaxWidth().padding(vertical = 8.dp).then(if (onPick != null) Modifier.clickable { onPick(a) } else Modifier)
                    Column(row) {
                        Text(a.label, fontWeight = FontWeight.SemiBold)
                        Text(if (status == "autorizado") "✅ Autorizado ahora" else "⚠ ${LogbookRepository.AUTH_LABELS[status] ?: status}",
                            style = MaterialTheme.typography.bodySmall, color = if (status == "autorizado") Ok else BrandDark)
                        a.reason?.takeIf { it.isNotBlank() }?.let { Text("Motivo: $it", style = MaterialTheme.typography.bodySmall, color = Muted) }
                    }
                }
            }
        }, confirmButton = { TextButton(onDismiss) { Text("Cerrar") } })
}

/** Sugerencias por DNI o patente con lo que ya vino antes. */
@Composable
private fun Lookup(type: String, value: String, vm: LogbookViewModel, onPick: (KnownVisitor) -> Unit) {
    val items = remember(value) { vm.lookup(type, value) }
    var picked by remember { mutableStateOf<String?>(null) }
    if (items.isEmpty() || picked == value) return
    Card(colors = CardDefaults.cardColors(containerColor = WarnBg)) {
        Column(Modifier.padding(8.dp)) {
            Text("Ya vino antes:", style = MaterialTheme.typography.labelMedium, color = BrandDark)
            items.forEach { k ->
                Text(listOfNotNull(if (type == "vehiculo") k.plate ?: k.key else k.doc ?: k.key, k.name ?: k.driver, k.company, "${k.visits} visita(s)").joinToString(" · "),
                    Modifier.fillMaxWidth().clickable { onPick(k); picked = k.key }.padding(vertical = 8.dp))
            }
        }
    }
}

@Composable
private fun CloseForm(ui: LogbookState, endShift: Boolean, onCancel: () -> Unit, onSign: (String, String) -> Unit) {
    val sig = remember { SignatureState() }
    var text by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        TopBar(if (endShift) "Cerrar libro y terminar turno" else "Cerrar mi turno", onCancel)
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            val c = ui.summary
            Text("Tu tramo: ${c["novedades"] ?: 0} novedad(es)" + ((c["importantes"] ?: 0).takeIf { it > 0 }?.let { " ($it importantes)" } ?: "") +
                ", ${c["personas"] ?: 0} ingreso(s) de personas y ${c["vehiculos"] ?: 0} de vehículos.")
            if (ui.inside.isNotEmpty()) Text("Siguen adentro: " + ui.inside.joinToString("; ") { it.label.ifEmpty { "sin datos" } } + ". Se avisa al supervisor.",
                color = Danger, style = MaterialTheme.typography.bodySmall)
            OutlinedTextField(text, { text = it.take(3000) }, Modifier.fillMaxWidth(), minLines = 3, label = { Text("Cómo dejás el puesto: sin novedades, pendientes, fallas…") })
            SignaturePad(sig)
            error?.let { Text(it, color = Danger) }
            Button({
                val data = sig.toDataUrl()
                if (data == null) error = "Firmá en el recuadro." else onSign(text, data)
            }, Modifier.fillMaxWidth().height(52.dp), colors = ButtonDefaults.buttonColors(containerColor = Brand, contentColor = androidx.compose.ui.graphics.Color.White)) {
                Text(if (endShift) "Firmar y terminar turno" else "Firmar cierre", fontWeight = FontWeight.Bold)
            }
            Text("Si llega otro guardia, él recibe el puesto con su firma. Si no hay relevo, el cierre queda igual.", style = MaterialTheme.typography.bodySmall, color = Muted)
        }
    }
}

@Composable
private fun ReceiveForm(close: LogEntry, onCancel: () -> Unit, onSign: (String, String) -> Unit) {
    val sig = remember { SignatureState() }
    var text by remember { mutableStateOf("") }
    var error by remember { mutableStateOf<String?>(null) }
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        TopBar("Recibir el puesto", onCancel)
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("${close.userName ?: "El guardia anterior"} cerró su turno a las ${HOUR.format(close.occurredAt)}.", fontWeight = FontWeight.SemiBold)
            close.text?.let { Text("Dejó anotado: $it") }
            val inside = (close.data?.get("adentro") as? kotlinx.serialization.json.JsonArray)?.size ?: 0
            if (inside > 0) Text("Dejó $inside persona(s) o vehículo(s) adentro.", color = Danger, style = MaterialTheme.typography.bodySmall)
            OutlinedTextField(text, { text = it.take(3000) }, Modifier.fillMaxWidth(), minLines = 2,
                label = { Text("Recibo de conformidad (o anotá lo que encontraste distinto)") })
            SignaturePad(sig)
            error?.let { Text(it, color = Danger) }
            Button({
                val data = sig.toDataUrl()
                if (data == null) error = "Firmá en el recuadro." else onSign(text, data)
            }, Modifier.fillMaxWidth().height(52.dp), colors = ButtonDefaults.buttonColors(containerColor = Ok, contentColor = androidx.compose.ui.graphics.Color.White)) { Text("Firmar y recibir el puesto", fontWeight = FontWeight.Bold) }
        }
    }
}
