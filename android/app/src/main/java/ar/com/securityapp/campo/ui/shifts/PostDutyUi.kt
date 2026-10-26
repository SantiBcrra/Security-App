package ar.com.securityapp.campo.ui.shifts

import android.app.Application
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import ar.com.securityapp.campo.CampoApp
import ar.com.securityapp.campo.data.Fix
import ar.com.securityapp.campo.data.OnDuty
import ar.com.securityapp.campo.data.PostGuard
import ar.com.securityapp.campo.data.PostSpan
import ar.com.securityapp.campo.data.obj
import ar.com.securityapp.campo.data.str
import ar.com.securityapp.campo.location.Locator
import ar.com.securityapp.campo.sync.SyncWorker
import ar.com.securityapp.campo.tracking.ShiftTrackingService
import ar.com.securityapp.campo.ui.rememberHaptics
import ar.com.securityapp.campo.ui.theme.*
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import kotlinx.serialization.json.jsonObject
import java.time.Duration
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter

private val HM = DateTimeFormatter.ofPattern("HH:mm").withZone(ZoneId.systemDefault())
private val DHM = DateTimeFormatter.ofPattern("EEE dd/MM HH:mm", java.util.Locale("es", "AR")).withZone(ZoneId.systemDefault())

/** Para qué se eligió el guardia: empezar con el puesto vacío o recibirlo de otro (relevo). */
enum class DutyPurpose { START, HANDOVER }

/** Paso del flujo de identificación. */
sealed interface DutyStep {
    data class Pick(val purpose: DutyPurpose) : DutyStep
    data class Pin(val guard: PostGuard, val purpose: DutyPurpose) : DutyStep
    data class Consent(val guard: PostGuard, val purpose: DutyPurpose) : DutyStep
    data class FarAway(val guard: PostGuard, val purpose: DutyPurpose, val fix: Fix?, val distanceM: Double?) : DutyStep
    data object EndMenu : DutyStep
    data object LeaveEmpty : DutyStep
}

data class PostDutyState(val onDuty: OnDuty? = null, val guards: List<PostGuard> = emptyList(), val siteName: String = "",
                         val span: PostSpan? = null, val next: PostSpan? = null, val outside: Boolean = false,
                         val step: DutyStep? = null, val busy: Boolean = false, val message: String? = null, val pinError: String? = null,
                         val consentText: String = "", val consentVersion: String = "")

class PostDutyViewModel(app: Application) : AndroidViewModel(app) {
    private val c = (app as CampoApp).container
    private val repo get() = c.post
    private val _ui = MutableStateFlow(PostDutyState())
    val ui: StateFlow<PostDutyState> = _ui
    private var wrongPins = 0
    private var lockedUntil = 0L
    fun isPreview(): Boolean = c.api.previewToken != null

    init {
        reload()
        viewModelScope.launch { c.dataChanged.collect { reload() } }
    }

    fun reload() {
        val duty = repo.onDuty()
        c.dutyName.value = duty?.guardName
        val consent = c.prefs.metaJson?.let { runCatching { c.api.json.parseToJsonElement(it).jsonObject.obj("puesto")?.obj("consentimiento") }.getOrNull() }
        _ui.value = _ui.value.copy(onDuty = duty, guards = repo.guards(), siteName = repo.posts().firstOrNull()?.siteName ?: "",
            span = repo.currentSpan(), next = repo.nextSpan(), outside = c.shifts.openExit() != null,
            consentText = consent?.str("texto") ?: "", consentVersion = consent?.str("version") ?: "")
    }

    fun clearMessage() { _ui.value = _ui.value.copy(message = null) }
    fun open(step: DutyStep?) { _ui.value = _ui.value.copy(step = step, pinError = null) }

    fun pick(g: PostGuard, purpose: DutyPurpose) {
        if (!g.hasPin) { _ui.value = _ui.value.copy(message = "${g.name} todavía no tiene PIN. Pedile al supervisor que se lo cargue."); return }
        if (purpose == DutyPurpose.HANDOVER && g.uuid == _ui.value.onDuty?.guardUuid) {
            _ui.value = _ui.value.copy(message = "${g.name} ya está de turno."); return
        }
        open(DutyStep.Pin(g, purpose))
    }

    /** @return true si el PIN es correcto (sigue el flujo). */
    fun checkPin(g: PostGuard, purpose: DutyPurpose, pin: String): Boolean {
        val now = System.currentTimeMillis()
        if (now < lockedUntil) { _ui.value = _ui.value.copy(pinError = "Demasiados intentos. Esperá ${(lockedUntil - now) / 1000 + 1} s."); return false }
        if (!repo.verifyPin(g, pin)) {
            wrongPins++
            if (wrongPins >= 5) { lockedUntil = now + 60_000; wrongPins = 0 }
            _ui.value = _ui.value.copy(pinError = "PIN incorrecto."); return false
        }
        wrongPins = 0
        if (!g.consent && _ui.value.consentText.isNotBlank()) open(DutyStep.Consent(g, purpose)) else locate(g, purpose)
        return true
    }

    fun acceptConsent(g: PostGuard, purpose: DutyPurpose) {
        repo.acceptConsent(g, _ui.value.consentVersion)
        locate(g.copy(consent = true), purpose)
    }

    /** GPS antes de empezar: si está lejos del predio o la ubicación es falsa, se pide confirmación (no se rechaza nunca). */
    private fun locate(g: PostGuard, purpose: DutyPurpose) = viewModelScope.launch {
        _ui.value = _ui.value.copy(step = null, busy = true)
        val fix = Locator(getApplication()).current(timeoutMs = 12_000)
        val site = c.shifts.site(_ui.value.span?.siteUuid ?: repo.posts().firstOrNull()?.siteUuid ?: "")
        val inside = fix?.let { c.shifts.inside(site, it) }
        if (fix == null || inside == false || fix.mock) {
            _ui.value = _ui.value.copy(busy = false, step = DutyStep.FarAway(g, purpose, fix, fix?.let { c.shifts.distanceTo(site, it) }))
        } else start(g, purpose, fix)
    }

    fun confirmFar(s: DutyStep.FarAway) = start(s.guard, s.purpose, s.fix)

    private fun start(g: PostGuard, purpose: DutyPurpose, fix: Fix?) {
        val previous = _ui.value.onDuty
        val site = c.shifts.site(_ui.value.span?.siteUuid ?: repo.posts().firstOrNull()?.siteUuid ?: "")
        repo.startDuty(g, site, fix)
        ShiftTrackingService.start(getApplication())
        SyncWorker.now(getApplication())
        _ui.value = _ui.value.copy(step = null, busy = false)
        reload()
        _ui.value = _ui.value.copy(message = if (purpose == DutyPurpose.HANDOVER && previous != null)
            "Cambio de guardia: ${g.name} recibe el puesto de ${previous.guardName}." else "Turno de ${g.name} empezado. Todo lo que hagas queda a tu nombre.")
    }

    /** @param leaveEmpty "Dejar el puesto sin persona" con motivo (no avisa "relevo que no llegó"). */
    fun end(leaveEmpty: Boolean, note: String?) {
        val name = _ui.value.onDuty?.guardName ?: ""
        repo.endDuty(leaveEmpty, note)
        ShiftTrackingService.stop(getApplication())
        SyncWorker.now(getApplication())
        _ui.value = _ui.value.copy(step = null)
        reload()
        _ui.value = _ui.value.copy(message = if (leaveEmpty) "Turno de $name terminado. El puesto queda sin persona: $note."
            else "Turno de $name terminado. Si el próximo guardia no empieza su turno en un rato, se avisa al supervisor.")
    }

    fun ensureService() { if (c.shifts.current() != null) ShiftTrackingService.start(getApplication()) }
}

/** Tarjeta del inicio en el celular compartido del puesto: quién está, empezar, cambio de guardia y terminar. */
@Composable
fun PostDutyCard(vm: PostDutyViewModel = viewModel()) {
    val ui by vm.ui.collectAsStateWithLifecycle()
    val haptics = rememberHaptics()
    var previewWarning by remember { mutableStateOf(false) }
    LaunchedEffect(Unit) { vm.ensureService() }
    val now = Instant.now()
    val duty = ui.onDuty
    val bg = when {
        duty != null && ui.outside -> DangerBg
        duty != null -> OkBg
        ui.span != null && !now.isBefore(ui.span!!.startAt) -> DangerBg // franja en curso y nadie
        else -> CardBg
    }
    Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = bg)) {
        Column(Modifier.padding(16.dp)) {
            Text("Puesto${if (ui.siteName.isNotBlank()) " · ${ui.siteName}" else ""}", style = MaterialTheme.typography.labelLarge, color = Muted)
            if (duty != null) {
                Text("De turno", style = MaterialTheme.typography.bodySmall, color = Muted, modifier = Modifier.padding(top = 6.dp))
                Text(duty.guardName, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
                duty.since?.let { Text("desde las ${HM.format(it)} (${hours(Duration.between(it, now))})", style = MaterialTheme.typography.bodyMedium) }
                if (ui.outside) Text("Fuera del predio", color = Danger, fontWeight = FontWeight.Bold)
                Row(Modifier.fillMaxWidth().padding(top = 12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button({ haptics.tick(); if (vm.isPreview()) previewWarning = true else vm.open(DutyStep.Pick(DutyPurpose.HANDOVER)) }, Modifier.weight(1f).heightIn(min = 52.dp),
                        colors = ButtonDefaults.buttonColors(containerColor = Accent, contentColor = OnAccent)) { Text("Cambio de guardia", fontWeight = FontWeight.Bold, textAlign = TextAlign.Center) }
                    OutlinedButton({ haptics.tick(); if (vm.isPreview()) previewWarning = true else vm.open(DutyStep.EndMenu) }, Modifier.weight(1f).heightIn(min = 52.dp)) {
                        Text("Terminar mi turno", textAlign = TextAlign.Center)
                    }
                }
            } else {
                Text("Nadie de turno", style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 6.dp))
                val span = ui.span
                when {
                    span != null && !now.isBefore(span.startAt) -> Text("El puesto tiene que estar cubierto (hasta el ${DHM.format(span.endAt)}).", color = Danger)
                    span != null -> Text("El turno empieza a las ${HM.format(span.startAt)}.", style = MaterialTheme.typography.bodyMedium)
                    ui.next != null -> Text("Próximo turno: ${DHM.format(ui.next!!.startAt)}.", style = MaterialTheme.typography.bodyMedium, color = Muted)
                }
                Button({ haptics.tick(); if (vm.isPreview()) previewWarning = true else vm.open(DutyStep.Pick(DutyPurpose.START)) }, Modifier.fillMaxWidth().padding(top = 12.dp).height(56.dp),
                    enabled = !ui.busy, colors = ButtonDefaults.buttonColors(containerColor = Accent, contentColor = OnAccent)) {
                    if (ui.busy) CircularProgressIndicator(Modifier.size(20.dp), strokeWidth = 2.dp, color = OnAccent)
                    else Text("Empezar mi turno", fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
                }
            }
            ui.message?.let { m ->
                Text(m, style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(top = 8.dp).clickable { vm.clearMessage() })
            }
        }
    }

    when (val step = ui.step) {
        is DutyStep.Pick -> GuardPicker(ui.guards, step.purpose, ui.onDuty, onPick = { vm.pick(it, step.purpose) }, onDismiss = { vm.open(null) })
        is DutyStep.Pin -> PinDialog(step.guard, ui.pinError, onSubmit = { pin -> if (vm.checkPin(step.guard, step.purpose, pin)) haptics.confirm() else haptics.reject() },
            onDismiss = { vm.open(null) })
        is DutyStep.Consent -> AlertDialog(onDismissRequest = { vm.open(null) }, title = { Text("${step.guard.name}: uso de tu ubicación") },
            text = { Column(Modifier.heightIn(max = 380.dp).verticalScroll(rememberScrollState())) { Text(ui.consentText, style = MaterialTheme.typography.bodySmall) } },
            confirmButton = { TextButton({ vm.acceptConsent(step.guard, step.purpose) }) { Text("Acepto") } },
            dismissButton = { TextButton({ vm.open(null) }) { Text("Cancelar") } })
        is DutyStep.FarAway -> AlertDialog(onDismissRequest = { vm.open(null) }, title = { Text(if (step.fix == null) "Sin ubicación" else "Lejos del predio") },
            text = { Text(when {
                step.fix == null -> "No se pudo tomar la ubicación del celular. Si empezás igual, queda registrado sin GPS."
                step.fix.mock -> "El celular informa una ubicación simulada. Si empezás igual, se avisa al supervisor."
                else -> "El GPS indica que el celular está a ${step.distanceM?.toInt() ?: "?"} m del predio. Si empezás igual, se avisa al supervisor."
            }) },
            confirmButton = { TextButton({ vm.confirmFar(step) }) { Text("Empezar igual") } },
            dismissButton = { TextButton({ vm.open(null) }) { Text("Cancelar") } })
        DutyStep.EndMenu -> EndMenu(ui.onDuty?.guardName ?: "", onHandover = { vm.open(DutyStep.Pick(DutyPurpose.HANDOVER)) },
            onLeaveEmpty = { vm.open(DutyStep.LeaveEmpty) }, onEnd = { haptics.confirm(); vm.end(false, null) }, onDismiss = { vm.open(null) })
        DutyStep.LeaveEmpty -> LeaveEmptyDialog(onConfirm = { note -> haptics.confirm(); vm.end(true, note) }, onDismiss = { vm.open(null) })
        null -> {}
    }
    if (previewWarning) AlertDialog(onDismissRequest = { previewWarning = false }, title = { Text("Vista previa") },
        text = { Text("En la vista previa no se pueden empezar ni terminar turnos.") }, confirmButton = { TextButton({ previewWarning = false }) { Text("Entendido") } })
}

private fun hours(d: Duration): String = "${d.toHours()} h ${(d.toMinutes() % 60).toString().padStart(2, '0')} min"

@Composable
private fun GuardPicker(guards: List<PostGuard>, purpose: DutyPurpose, onDuty: OnDuty?, onPick: (PostGuard) -> Unit, onDismiss: () -> Unit) {
    AlertDialog(onDismissRequest = onDismiss,
        title = { Text(if (purpose == DutyPurpose.HANDOVER) "¿Quién recibe el puesto?" else "¿Quién sos?") },
        text = {
            Column(Modifier.heightIn(max = 420.dp).verticalScroll(rememberScrollState())) {
                if (purpose == DutyPurpose.HANDOVER && onDuty != null) Text("El guardia que llega elige su nombre y pone su PIN. ${onDuty.guardName} termina su turno al confirmar.",
                    style = MaterialTheme.typography.bodySmall, color = Muted, modifier = Modifier.padding(bottom = 8.dp))
                if (guards.isEmpty()) Text("No hay guardias cargados en el grupo de este puesto. Pedile al supervisor que los agregue en Programación de turnos.", color = Muted)
                guards.forEach { g ->
                    val current = g.uuid == onDuty?.guardUuid
                    Row(Modifier.fillMaxWidth().heightIn(min = 56.dp).clickable(enabled = !current) { onPick(g) }.padding(vertical = 6.dp),
                        verticalAlignment = Alignment.CenterVertically) {
                        Box(Modifier.size(40.dp).background(if (current) NeutralBg else Accent, CircleShape), contentAlignment = Alignment.Center) {
                            Text(g.name.split(" ").mapNotNull { it.firstOrNull()?.uppercase() }.take(2).joinToString(""), color = OnAccent, fontWeight = FontWeight.Bold)
                        }
                        Column(Modifier.padding(start = 12.dp).weight(1f)) {
                            Text(g.name, fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.titleMedium)
                            Text(when { current -> "De turno ahora"; !g.hasPin -> "Sin PIN: pedilo al supervisor"; g.dniEnd != null -> "DNI …${g.dniEnd}"; else -> "" },
                                style = MaterialTheme.typography.bodySmall, color = if (!g.hasPin) Danger else Muted)
                        }
                    }
                    HorizontalDivider()
                }
            }
        },
        confirmButton = {}, dismissButton = { TextButton(onDismiss) { Text("Cancelar") } })
}

/** Teclado numérico grande (para usar con guantes): 4 a 6 números. */
@Composable
private fun PinDialog(guard: PostGuard, error: String?, onSubmit: (String) -> Unit, onDismiss: () -> Unit) {
    var pin by remember(guard.uuid) { mutableStateOf("") }
    LaunchedEffect(error) { if (error != null) pin = "" }
    AlertDialog(onDismissRequest = onDismiss, title = { Text(guard.name) }, text = {
        Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.fillMaxWidth()) {
            Text("Poné tu PIN", style = MaterialTheme.typography.bodyMedium, color = Muted)
            Row(Modifier.padding(vertical = 12.dp), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                repeat(6) { i ->
                    Box(Modifier.size(14.dp).background(if (i < pin.length) MaterialTheme.colorScheme.onSurface else Color.Transparent, CircleShape)
                        .border(1.5.dp, if (i < 4 || i < pin.length) MaterialTheme.colorScheme.onSurface else BorderC, CircleShape))
                }
            }
            error?.let { Text(it, color = Danger, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(bottom = 6.dp)) }
            val keys = listOf("1", "2", "3", "4", "5", "6", "7", "8", "9", "⌫", "0", "OK")
            keys.chunked(3).forEach { row ->
                Row(horizontalArrangement = Arrangement.spacedBy(10.dp), modifier = Modifier.padding(vertical = 5.dp)) {
                    row.forEach { k ->
                        val enabled = when (k) { "OK" -> pin.length >= 4; "⌫" -> pin.isNotEmpty(); else -> pin.length < 6 }
                        Box(Modifier.size(width = 76.dp, height = 58.dp)
                            .background(if (k == "OK") (if (enabled) Accent else NeutralBg) else NeutralBg, RoundedCornerShape(14.dp))
                            .clickable(enabled = enabled) { when (k) { "⌫" -> pin = pin.dropLast(1); "OK" -> onSubmit(pin); else -> pin += k } },
                            contentAlignment = Alignment.Center) {
                            Text(k, fontSize = if (k == "OK") 18.sp else 24.sp, fontWeight = FontWeight.Bold,
                                color = if (k == "OK") OnAccent else MaterialTheme.colorScheme.onSurface)
                        }
                    }
                }
            }
        }
    }, confirmButton = {}, dismissButton = { TextButton(onDismiss) { Text("Cancelar") } })
}

@Composable
private fun EndMenu(name: String, onHandover: () -> Unit, onLeaveEmpty: () -> Unit, onEnd: () -> Unit, onDismiss: () -> Unit) {
    AlertDialog(onDismissRequest = onDismiss, title = { Text("Terminar el turno de $name") }, text = {
        Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Option("Llegó el otro guardia", "Cambio de guardia: el que llega pone su PIN y recibe el puesto.", onHandover)
            Option("Dejar el puesto sin persona", "Por ejemplo, llegó el encargado o el dueño. Contás el motivo y no se avisa a nadie.", onLeaveEmpty)
            Option("Terminar: todavía no llegó nadie", "El puesto queda vacío. Si el próximo no empieza su turno en un rato, se avisa al supervisor.", onEnd)
        }
    }, confirmButton = {}, dismissButton = { TextButton(onDismiss) { Text("Cancelar") } })
}

@Composable
private fun Option(title: String, hint: String, onClick: () -> Unit) {
    Column(Modifier.fillMaxWidth().background(NeutralBg, RoundedCornerShape(12.dp)).clickable(onClick = onClick).padding(12.dp)) {
        Text(title, fontWeight = FontWeight.SemiBold)
        Text(hint, style = MaterialTheme.typography.bodySmall, color = Muted)
    }
}

@Composable
private fun LeaveEmptyDialog(onConfirm: (String) -> Unit, onDismiss: () -> Unit) {
    val reasons = listOf("Llegó el encargado", "Llegó el dueño", "Cierre del predio", "Otro")
    var picked by remember { mutableStateOf<String?>(null) }
    var note by remember { mutableStateOf("") }
    val text = if (picked == "Otro") note.trim() else listOfNotNull(picked, note.trim().takeIf { it.isNotEmpty() }).joinToString(": ")
    AlertDialog(onDismissRequest = onDismiss, title = { Text("Dejar el puesto sin persona") }, text = {
        Column {
            Text("¿Por qué queda sin guardia?", style = MaterialTheme.typography.bodySmall, color = Muted)
            reasons.forEach { r ->
                Row(Modifier.fillMaxWidth().clickable { picked = r }.padding(vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                    Box(Modifier.size(20.dp).border(2.dp, if (picked == r) Accent else BorderC, CircleShape).padding(4.dp)
                        .background(if (picked == r) Accent else Color.Transparent, CircleShape))
                    Spacer(Modifier.width(10.dp)); Text(r)
                }
            }
            OutlinedTextField(note, { note = it.take(200) }, Modifier.fillMaxWidth(), label = { Text(if (picked == "Otro") "Motivo" else "Detalle (opcional)") })
        }
    }, confirmButton = { TextButton({ onConfirm(text) }, enabled = text.length >= 3) { Text("Dejar sin persona") } },
        dismissButton = { TextButton(onDismiss) { Text("Cancelar") } })
}
