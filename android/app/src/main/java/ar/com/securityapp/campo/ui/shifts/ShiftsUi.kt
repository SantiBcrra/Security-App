package ar.com.securityapp.campo.ui.shifts

import android.app.Application
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Tab
import androidx.compose.material3.TabRow
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import ar.com.securityapp.campo.CampoApp
import ar.com.securityapp.campo.data.Absence
import ar.com.securityapp.campo.data.Fix
import ar.com.securityapp.campo.data.GuardShift
import ar.com.securityapp.campo.data.OfflineException
import ar.com.securityapp.campo.data.ShiftExit
import ar.com.securityapp.campo.data.ShiftSettings
import ar.com.securityapp.campo.data.ShiftsRepository
import ar.com.securityapp.campo.data.Site
import ar.com.securityapp.campo.data.arr
import ar.com.securityapp.campo.data.obj
import ar.com.securityapp.campo.data.str
import ar.com.securityapp.campo.data.strOrNull
import ar.com.securityapp.campo.location.Locator
import ar.com.securityapp.campo.sync.SyncWorker
import ar.com.securityapp.campo.tracking.ShiftTrackingService
import ar.com.securityapp.campo.ui.observations.TopBar
import ar.com.securityapp.campo.ui.theme.Accent
import ar.com.securityapp.campo.ui.theme.BrandDark
import ar.com.securityapp.campo.ui.theme.Danger
import ar.com.securityapp.campo.ui.theme.Muted
import ar.com.securityapp.campo.ui.theme.Ok
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId
import java.time.format.DateTimeFormatter

private val HOUR = DateTimeFormatter.ofPattern("HH:mm").withZone(ZoneId.systemDefault())
private val DAY = DateTimeFormatter.ofPattern("EEE dd/MM", java.util.Locale("es", "AR")).withZone(ZoneId.systemDefault())

/** Antes de empezar: el GPS dice que está lejos del predio (se puede empezar igual: se avisa al supervisor). */
data class StartCheck(val shift: GuardShift?, val site: Site?, val fix: Fix?, val distanceM: Double?)

data class ShiftsState(val current: GuardShift? = null, val upcoming: List<GuardShift> = emptyList(), val openExit: ShiftExit? = null,
                       val absences: List<Absence> = emptyList(), val sites: List<Site> = emptyList(), val settings: ShiftSettings? = null,
                       val busy: Boolean = false, val message: String? = null, val check: StartCheck? = null, val inside: Boolean? = null)

class ShiftsViewModel(app: Application) : AndroidViewModel(app) {
    private val c = (app as CampoApp).container
    private val repo get() = c.shifts
    private val _ui = MutableStateFlow(ShiftsState())
    val ui: StateFlow<ShiftsState> = _ui
    private val _history = MutableStateFlow<Pair<JsonObject?, String?>>(null to null)
    val history: StateFlow<Pair<JsonObject?, String?>> = _history

    init {
        reload()
        viewModelScope.launch { c.dataChanged.collect { reload() } }
    }

    fun reload() {
        _ui.value = _ui.value.copy(current = repo.current(), upcoming = repo.upcoming().take(10), openExit = repo.openExit(), absences = repo.absences(),
            sites = repo.sites(), settings = repo.settings())
    }

    private fun msg(m: String?) { _ui.value = _ui.value.copy(busy = false, message = m) }
    fun clearMessage() { _ui.value = _ui.value.copy(message = null) }

    /** Empezar: toma el GPS; si está lejos del predio pide confirmación (no se rechaza nunca). */
    fun prepareStart(shift: GuardShift?, site: Site?) = viewModelScope.launch {
        _ui.value = _ui.value.copy(busy = true, message = null)
        val fix = Locator(getApplication()).current(timeoutMs = 15_000)
        val target = shift?.let { repo.siteOf(it) } ?: site
        val inside = fix?.let { repo.inside(target, it) }
        if (fix == null || inside == false || fix.mock) {
            _ui.value = _ui.value.copy(busy = false, check = StartCheck(shift, site, fix, fix?.let { repo.distanceTo(target, it) }))
        } else start(shift, site, fix)
    }

    fun confirmStart() {
        val ck = _ui.value.check ?: return
        _ui.value = _ui.value.copy(check = null)
        start(ck.shift, ck.site, ck.fix)
    }

    fun cancelStart() { _ui.value = _ui.value.copy(check = null, busy = false) }

    private fun start(shift: GuardShift?, site: Site?, fix: Fix?) {
        repo.start(shift, site, fix)
        ShiftTrackingService.start(getApplication())
        SyncWorker.now(getApplication())
        reload()
        msg("Turno empezado. Mientras estés en turno vas a ver la notificación \"En turno\".")
    }

    fun end() {
        val s = _ui.value.current ?: return
        repo.end(s)
        ShiftTrackingService.stop(getApplication())
        SyncWorker.now(getApplication())
        reload()
        msg("Turno terminado. ¡Buen descanso!")
    }

    /** "Salgo por una emergencia": deja registrada la salida con el motivo antes de irse. */
    fun announceExit(reason: String, note: String) = viewModelScope.launch {
        val s = _ui.value.current ?: return@launch
        val fix = Locator(getApplication()).current(timeoutMs = 8_000)
        repo.exit(s, Instant.now(), fix, reason, note, announced = true)
        SyncWorker.now(getApplication())
        reload()
        msg("Se avisó a tu supervisor. Cuando vuelvas al predio la app lo registra sola.")
    }

    fun setReason(e: ShiftExit, reason: String, note: String) {
        repo.setReason(e, reason, note)
        ar.com.securityapp.campo.push.Notifs.cancel(getApplication(), "shift-exit")
        SyncWorker.now(getApplication())
        reload()
        msg("Motivo enviado a tu supervisor.")
    }

    fun requestAbsence(from: LocalDate, to: LocalDate, type: String, label: String, reason: String) {
        repo.requestAbsence(from.toString(), to.toString(), type, label, reason)
        SyncWorker.now(getApplication())
        reload()
        msg("Pedido enviado. Te avisamos cuando tu supervisor lo responda.")
    }

    fun cancelAbsence(a: Absence) = viewModelScope.launch {
        _ui.value = _ui.value.copy(busy = true)
        try { repo.cancelAbsence(a.uuid); reload(); msg("Pedido cancelado.") }
        catch (e: OfflineException) { msg("Hace falta señal para cancelar.") }
        catch (e: Exception) { msg(e.message) }
    }

    fun loadHistory() = viewModelScope.launch {
        _history.value = null to null
        _history.value = try { repo.history() to null } catch (e: OfflineException) { null to "Sin señal: el historial se ve con conexión." } catch (e: Exception) { null to e.message }
    }

    /** Al abrir la app con un turno en curso, el servicio tiene que estar corriendo. */
    fun ensureService() { if (repo.current() != null) ShiftTrackingService.start(getApplication()) }
}

private fun span(s: GuardShift) = "${DAY.format(s.startAt)} · ${HOUR.format(s.startAt)} a ${HOUR.format(s.endAt)}"

/** Tarjeta del inicio: turno en curso o el próximo, con Empezar / Terminar. */
@Composable
fun ShiftCard(onOpen: () -> Unit, vm: ShiftsViewModel = viewModel()) {
    val ui by vm.ui.collectAsStateWithLifecycle()
    LaunchedEffect(Unit) { vm.reload(); vm.ensureService() }
    val cfg = ui.settings ?: return
    val current = ui.current
    val next = ui.upcoming.firstOrNull { it.status == "programado" || it.status == "justificado" }
    var confirmEnd by remember { mutableStateOf(false) }
    var emergency by remember { mutableStateOf(false) }
    var pickSite by remember { mutableStateOf(false) }
    // Sin permiso de ubicación se pide primero; empiece o no, se registra (sin GPS se avisa).
    var pending by remember { mutableStateOf<Pair<GuardShift?, Site?>?>(null) }
    val permission = androidx.activity.compose.rememberLauncherForActivityResult(androidx.activity.result.contract.ActivityResultContracts.RequestMultiplePermissions()) {
        pending?.let { (s, site) -> vm.prepareStart(s, site) }; pending = null
    }
    val context = LocalContext.current
    fun begin(s: GuardShift?, site: Site?) {
        if (ShiftTrackingService.hasLocation(context)) vm.prepareStart(s, site)
        else { pending = s to site; permission.launch(arrayOf(android.Manifest.permission.ACCESS_FINE_LOCATION, android.Manifest.permission.ACCESS_COARSE_LOCATION)) }
    }
    val now = Instant.now()
    val bg = when {
        current != null && ui.openExit != null -> Color(0xFFFBE3E5)
        current != null -> Color(0xFFE3F3EA)
        next?.late(now) == true -> Color(0xFFFDF3CF)
        else -> Color.White
    }
    Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = bg)) {
        Column(Modifier.padding(16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("Mi turno", style = MaterialTheme.typography.titleSmall, color = Muted, modifier = Modifier.weight(1f))
                TextButton(onOpen) { Text("Mis turnos y ausencias") }
            }
            when {
                current != null -> {
                    Text(if (ui.openExit != null) "En turno · FUERA del predio" else "En turno · ${current.siteName}", fontWeight = FontWeight.Bold,
                        color = if (ui.openExit != null) Danger else Ok, style = MaterialTheme.typography.titleMedium)
                    Text("Desde las ${current.startedAt?.let { HOUR.format(it) } ?: "—"}" + (if (!current.unscheduled) " · sale a las ${HOUR.format(current.endAt)}" else " · turno no programado"),
                        style = MaterialTheme.typography.bodySmall, color = BrandDark)
                    ui.openExit?.let { e ->
                        Text(if (e.reason == null) "Saliste a las ${HOUR.format(e.leftAt)}: indicá el motivo." else "Saliste a las ${HOUR.format(e.leftAt)} (${cfg.reasons.toMap()[e.reason] ?: e.reason}). Al volver se registra solo.",
                            style = MaterialTheme.typography.bodySmall, color = Danger, modifier = Modifier.padding(top = 4.dp))
                    }
                    Row(Modifier.padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        if (ui.openExit == null) OutlinedButton({ emergency = true }, Modifier.weight(1f)) { Text("Salgo por una emergencia") }
                        Button({ confirmEnd = true }, Modifier.weight(1f), colors = ButtonDefaults.buttonColors(containerColor = BrandDark)) { Text("Terminar turno") }
                    }
                }
                next != null -> {
                    Text(if (next.status == "justificado") "Próximo turno (tenés ausencia aprobada)" else if (next.late(now)) "Todavía no empezaste tu turno" else "Próximo turno",
                        fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium, color = if (next.late(now)) Danger else BrandDark)
                    Text("${next.siteName} · ${span(next)}", style = MaterialTheme.typography.bodyMedium)
                    val can = next.canStart(cfg.earlyMinutes, now)
                    Button({ begin(next, null) }, Modifier.fillMaxWidth().padding(top = 10.dp), enabled = can && !ui.busy,
                        colors = ButtonDefaults.buttonColors(containerColor = Accent, contentColor = BrandDark)) {
                        if (ui.busy) CircularProgressIndicator(Modifier.size(18.dp), strokeWidth = 2.dp) else Text("Empezar turno", fontWeight = FontWeight.Bold)
                    }
                    if (!can) Text("Se puede empezar desde ${cfg.earlyMinutes} min antes (${HOUR.format(next.startAt.minusSeconds(cfg.earlyMinutes * 60L))}).",
                        style = MaterialTheme.typography.bodySmall, color = Muted, modifier = Modifier.padding(top = 4.dp))
                }
                else -> {
                    Text("No tenés turnos programados.", style = MaterialTheme.typography.bodyMedium)
                    TextButton({ pickSite = true }) { Text("Empezar un turno no programado (cubro a alguien)") }
                }
            }
            ui.message?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Muted, modifier = Modifier.padding(top = 6.dp)) }
        }
    }
    if (confirmEnd && current != null) {
        val left = java.time.Duration.between(Instant.now(), current.endAt).toMinutes()
        AlertDialog(onDismissRequest = { confirmEnd = false }, title = { Text("¿Terminar el turno?") },
            text = { Text(if (!current.unscheduled && left > 5) "Todavía faltan $left min para tu horario de salida (${HOUR.format(current.endAt)}). Queda registrado que te fuiste antes."
                else "Se registra la hora de salida y la app deja de usar tu ubicación.") },
            confirmButton = { TextButton({ confirmEnd = false; vm.end() }) { Text("Terminar turno") } },
            dismissButton = { TextButton({ confirmEnd = false }) { Text("Cancelar") } })
    }
    if (emergency) ReasonDialog(cfg, "Salgo por una emergencia", "Elegí el motivo: se le avisa a tu supervisor. Cuando vuelvas al predio se registra solo.",
        onDismiss = { emergency = false }) { r, n -> emergency = false; vm.announceExit(r, n) }
    ui.openExit?.takeIf { it.reason == null }?.let { e ->
        var later by remember(e.uuid) { mutableStateOf(false) }
        if (!later) ReasonDialog(cfg, "Saliste del predio: ¿por qué?",
            "Saliste a las ${HOUR.format(e.leftAt)}. Si no indicás el motivo en ${cfg.noReasonMinutes} min se avisa a tu supervisor.",
            onDismiss = { later = true }) { r, n -> vm.setReason(e, r, n) }
    }
    ui.check?.let { ck -> StartCheckDialog(ck, onConfirm = { vm.confirmStart() }, onCancel = { vm.cancelStart() }) }
    if (pickSite) SitePicker(ui.sites, onDismiss = { pickSite = false }) { s -> pickSite = false; begin(null, s) }
}

@Composable
private fun StartCheckDialog(ck: StartCheck, onConfirm: () -> Unit, onCancel: () -> Unit) {
    val far = ck.distanceM?.let { if (it >= 1000) "%.1f km".format(it / 1000) else "${it.toInt()} m" }
    AlertDialog(onDismissRequest = onCancel, title = { Text(if (ck.fix == null) "Sin GPS" else "No estás en el predio") },
        text = { Text(when {
            ck.fix == null -> "No se pudo obtener tu ubicación. Activá el GPS y probá de nuevo. Si empezás igual, queda registrado sin ubicación."
            ck.fix.mock -> "El celular tiene una ubicación simulada (app de GPS falso). Si empezás igual se avisa a tu supervisor."
            else -> "Estás a ${far ?: "?"} del predio. Si empezás igual, se avisa a tu supervisor."
        }) },
        confirmButton = { TextButton(onConfirm) { Text("Empezar igual") } },
        dismissButton = { TextButton(onCancel) { Text("Cancelar") } })
}

/** Motivo de la salida del predio (opciones rápidas + nota). */
@Composable
fun ReasonDialog(cfg: ShiftSettings, title: String, hint: String, onDismiss: () -> Unit, onSend: (String, String) -> Unit) {
    var reason by remember { mutableStateOf<String?>(null) }
    var note by remember { mutableStateOf("") }
    AlertDialog(onDismissRequest = onDismiss, title = { Text(title) },
        text = {
            Column(Modifier.heightIn(max = 460.dp).verticalScroll(rememberScrollState())) {
                Text(hint, style = MaterialTheme.typography.bodySmall, color = Muted)
                cfg.reasons.forEach { (key, label) ->
                    Row(Modifier.fillMaxWidth().clickable { reason = key }.padding(vertical = 2.dp), verticalAlignment = Alignment.CenterVertically) {
                        RadioButton(reason == key, { reason = key }); Text(label)
                    }
                }
                OutlinedTextField(note, { note = it.take(500) }, Modifier.fillMaxWidth(), label = { Text(if (reason == "otro") "Contá qué pasó" else "Nota (opcional)") }, minLines = 2)
            }
        },
        confirmButton = { TextButton({ reason?.let { onSend(it, note) } }, enabled = reason != null && (reason != "otro" || note.isNotBlank())) { Text("Enviar") } },
        dismissButton = { TextButton(onDismiss) { Text("Más tarde") } })
}

@Composable
private fun SitePicker(sites: List<Site>, onDismiss: () -> Unit, onPick: (Site) -> Unit) {
    AlertDialog(onDismissRequest = onDismiss, title = { Text("¿En qué predio?") },
        text = {
            Column(Modifier.heightIn(max = 400.dp).verticalScroll(rememberScrollState())) {
                Text("Un turno no programado queda marcado como tal (por ejemplo si cubrís a un compañero).", style = MaterialTheme.typography.bodySmall, color = Muted)
                sites.forEach { s -> Text(s.name, Modifier.fillMaxWidth().clickable { onPick(s) }.padding(vertical = 12.dp), style = MaterialTheme.typography.bodyLarge) }
                if (sites.isEmpty()) Text("No hay predios cargados.", color = Muted)
            }
        },
        confirmButton = { TextButton(onDismiss) { Text("Cancelar") } })
}

/** "Mis turnos y ausencias": próximos, historial con horas (con señal) y pedidos de ausencia. */
@Composable
fun ShiftsScreen(onBack: () -> Unit, vm: ShiftsViewModel = viewModel()) {
    BackHandler(onBack = onBack)
    val ui by vm.ui.collectAsStateWithLifecycle()
    var tab by remember { mutableIntStateOf(0) }
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        TopBar("Mis turnos", onBack)
        TabRow(tab) {
            listOf("Próximos", "Historial", "Ausencias").forEachIndexed { i, t -> Tab(tab == i, { tab = i; if (i == 1) vm.loadHistory() }, text = { Text(t) }) }
        }
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            ui.message?.let { Text(it, color = Muted, style = MaterialTheme.typography.bodySmall) }
            when (tab) {
                0 -> Upcoming(ui)
                1 -> History(vm)
                else -> Absences(ui, vm)
            }
        }
    }
}

@Composable
private fun Upcoming(ui: ShiftsState) {
    ui.current?.let { Text("En turno ahora: ${it.siteName}, desde las ${it.startedAt?.let { t -> HOUR.format(t) } ?: "—"}", fontWeight = FontWeight.SemiBold, color = Ok) }
    if (ui.upcoming.isEmpty()) Text("No tenés turnos programados en los próximos días.", color = Muted)
    ui.upcoming.forEach { s ->
        Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = Color.White)) {
            Column(Modifier.padding(12.dp)) {
                Text(span(s), fontWeight = FontWeight.SemiBold)
                Text(s.siteName + (s.name?.let { " · $it" } ?: ""), style = MaterialTheme.typography.bodySmall, color = Muted)
                if (s.status == "justificado") Text("Ausencia aprobada: no tenés que venir.", style = MaterialTheme.typography.bodySmall, color = Ok)
            }
        }
    }
}

@Composable
private fun History(vm: ShiftsViewModel) {
    val (data, error) = vm.history.collectAsStateWithLifecycle().value
    LaunchedEffect(Unit) { vm.loadHistory() }
    error?.let { Text(it, color = Danger); return }
    if (data == null) { CircularProgressIndicator(); return }
    data.obj("totales")?.let { t ->
        Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = Color.White)) {
            Column(Modifier.padding(12.dp)) {
                Text("Últimos 30 días", style = MaterialTheme.typography.labelLarge, color = Muted)
                Text("${t.str("turnos")} turnos · ${t.str("horas")} h trabajadas", fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
                Text("Llegadas tarde: ${t.str("llegadas_tarde")} · Fuera del predio: ${t.str("minutos_fuera")} min · Ausencias: ${t.str("ausencias")} (justificadas ${t.str("justificadas")})",
                    style = MaterialTheme.typography.bodySmall, color = Muted)
            }
        }
    }
    val list = data.arr("turnos").mapNotNull { it as? JsonObject }
    if (list.isEmpty()) Text("Sin turnos en el período.", color = Muted)
    list.forEach { s ->
        Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = Color.White)) {
            Column(Modifier.padding(12.dp)) {
                val start = s.strOrNull("start_at")?.let { runCatching { Instant.parse(it) }.getOrNull() }
                Row {
                    Text((start?.let { DAY.format(it) } ?: "") + " · " + s.str("site_name"), fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                    Text(s.str("status_label"), style = MaterialTheme.typography.labelMedium, color = if (s.str("status") == "ausente") Danger else Muted)
                }
                val real = listOfNotNull(s.strOrNull("started_at"), s.strOrNull("ended_at")).map { runCatching { HOUR.format(Instant.parse(it)) }.getOrDefault("") }
                if (real.isNotEmpty()) Text("Real: " + real.joinToString(" a ") + " · " + (s.str("worked_minutes").toIntOrNull() ?: 0).let { "${it / 60}:%02d h".format(it % 60) }
                    + ((s.str("late_minutes").toIntOrNull() ?: 0).takeIf { it > 0 }?.let { " · $it min tarde" } ?: ""), style = MaterialTheme.typography.bodySmall)
                s.arr("exits").mapNotNull { it as? JsonObject }.forEach { e ->
                    Text("Salió " + (e.strOrNull("left_at")?.let { runCatching { HOUR.format(Instant.parse(it)) }.getOrNull() } ?: "") + " · " + (e.strOrNull("reason") ?: "sin motivo"),
                        style = MaterialTheme.typography.bodySmall, color = Danger)
                }
            }
        }
    }
}

@Composable
private fun Absences(ui: ShiftsState, vm: ShiftsViewModel) {
    var form by remember { mutableStateOf(false) }
    Button({ form = true }, Modifier.fillMaxWidth(), colors = ButtonDefaults.buttonColors(containerColor = BrandDark)) { Text("Pedir ausencia") }
    Text("Avisá con tiempo si no vas a poder venir. Tu supervisor lo aprueba y ese día no saltan alarmas.", style = MaterialTheme.typography.bodySmall, color = Muted)
    if (ui.absences.isEmpty()) Text("No tenés pedidos.", color = Muted)
    ui.absences.forEach { a ->
        Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = Color.White)) {
            Column(Modifier.padding(12.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(a.typeLabel, fontWeight = FontWeight.SemiBold, modifier = Modifier.weight(1f))
                    val (label, color) = when (a.status) { "aprobada" -> "Aprobada" to Ok; "rechazada" -> "Rechazada" to Danger; "cancelada" -> "Cancelada" to Muted
                        else -> (if (a.local) "Enviando…" else "Pendiente") to BrandDark }
                    Box(Modifier.background(color.copy(alpha = .12f), RoundedCornerShape(6.dp)).padding(horizontal = 8.dp, vertical = 2.dp)) { Text(label, color = color, style = MaterialTheme.typography.labelMedium) }
                }
                Text(if (a.dateFrom == a.dateTo) a.dateFrom.toDmy() else "${a.dateFrom.toDmy()} al ${a.dateTo.toDmy()}", style = MaterialTheme.typography.bodySmall)
                a.reason?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Muted) }
                a.decisionNote?.let { Text("Respuesta: $it", style = MaterialTheme.typography.bodySmall) }
                if (a.status == "solicitada" && !a.local) TextButton({ vm.cancelAbsence(a) }) { Text("Cancelar pedido", color = Danger) }
            }
        }
    }
    if (form) AbsenceForm(onDismiss = { form = false }) { from, to, type, label, reason -> form = false; vm.requestAbsence(from, to, type, label, reason) }
}

private fun String.toDmy() = runCatching { LocalDate.parse(this).format(DateTimeFormatter.ofPattern("dd/MM/yyyy")) }.getOrDefault(this)

@Composable
private fun AbsenceForm(onDismiss: () -> Unit, onSend: (LocalDate, LocalDate, String, String, String) -> Unit) {
    val context = LocalContext.current
    var from by remember { mutableStateOf(LocalDate.now()) }
    var to by remember { mutableStateOf(LocalDate.now()) }
    var type by remember { mutableStateOf<Pair<String, String>?>(null) }
    var reason by remember { mutableStateOf("") }
    fun pick(initial: LocalDate, set: (LocalDate) -> Unit) {
        android.app.DatePickerDialog(context, { _, y, m, d -> set(LocalDate.of(y, m + 1, d)) }, initial.year, initial.monthValue - 1, initial.dayOfMonth).show()
    }
    AlertDialog(onDismissRequest = onDismiss, title = { Text("Pedir ausencia") },
        text = {
            Column(Modifier.heightIn(max = 480.dp).verticalScroll(rememberScrollState())) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton({ pick(from) { from = it; if (to < it) to = it } }, Modifier.weight(1f)) { Text("Desde " + from.format(DateTimeFormatter.ofPattern("dd/MM"))) }
                    OutlinedButton({ pick(to) { if (it >= from) to = it } }, Modifier.weight(1f)) { Text("Hasta " + to.format(DateTimeFormatter.ofPattern("dd/MM"))) }
                }
                Spacer(Modifier.size(8.dp))
                ShiftsRepository.ABSENCE_TYPES.forEach { t ->
                    Row(Modifier.fillMaxWidth().clickable { type = t }, verticalAlignment = Alignment.CenterVertically) { RadioButton(type == t, { type = t }); Text(t.second) }
                }
                OutlinedTextField(reason, { reason = it.take(500) }, Modifier.fillMaxWidth(), label = { Text("Motivo (opcional)") }, minLines = 2)
            }
        },
        confirmButton = { TextButton({ type?.let { onSend(from, to, it.first, it.second, reason) } }, enabled = type != null) { Text("Enviar pedido") } },
        dismissButton = { TextButton(onDismiss) { Text("Cancelar") } })
}
