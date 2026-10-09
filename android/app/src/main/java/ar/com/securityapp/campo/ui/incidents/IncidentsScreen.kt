package ar.com.securityapp.campo.ui.incidents

import android.app.Application
import android.app.DatePickerDialog
import android.app.TimePickerDialog
import android.net.Uri
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.Close
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
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
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import ar.com.securityapp.campo.CampoApp
import ar.com.securityapp.campo.data.CatalogItem
import ar.com.securityapp.campo.data.Employee
import ar.com.securityapp.campo.data.Equipment
import ar.com.securityapp.campo.data.Fix
import ar.com.securityapp.campo.data.INCIDENT_TYPES
import ar.com.securityapp.campo.data.Incident
import ar.com.securityapp.campo.data.IncidentDraft
import ar.com.securityapp.campo.data.IncidentPerson
import ar.com.securityapp.campo.data.PERSON_ROLES
import ar.com.securityapp.campo.data.Sector
import ar.com.securityapp.campo.data.arr
import ar.com.securityapp.campo.data.str
import ar.com.securityapp.campo.data.strOrNull
import ar.com.securityapp.campo.location.Locator
import ar.com.securityapp.campo.sync.SyncWorker
import ar.com.securityapp.campo.ui.observations.Chips
import ar.com.securityapp.campo.ui.observations.PhotosField
import ar.com.securityapp.campo.ui.observations.SearchDialog
import ar.com.securityapp.campo.ui.observations.Section
import ar.com.securityapp.campo.ui.observations.TopBar
import ar.com.securityapp.campo.ui.observations.hour
import ar.com.securityapp.campo.ui.rounds.QrScanner
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
import java.time.Instant
import java.time.LocalDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

data class IncidentForm(
    val type: String? = null, val occurredAt: Instant = Instant.now(), val now: Boolean = true, val sector: Sector? = null, val equipment: Equipment? = null,
    val location: String = "", val description: String = "", val immediate: String = "", val severity: String? = null, val people: List<IncidentPerson> = emptyList(),
    val photos: List<Uri> = emptyList(), val fix: Fix? = null, val gps: String = "buscando…", val errors: List<String> = emptyList(), val saving: Boolean = false,
)

class IncidentsViewModel(app: Application) : AndroidViewModel(app) {
    private val c = (app as CampoApp).container
    val sectors: List<Sector> by lazy { c.observations.sectors() }
    val equipment: List<Equipment> by lazy { c.observations.equipment() }
    val employees: List<Employee> by lazy { c.incidents.employees() }
    val severities: List<CatalogItem> by lazy { c.observations.catalog("severidad") }
    private val _items = MutableStateFlow(c.incidents.list())
    val items: StateFlow<List<Incident>> = _items
    private val _form = MutableStateFlow(IncidentForm())
    val form: StateFlow<IncidentForm> = _form
    private val _detail = MutableStateFlow<JsonObject?>(null)
    val detail: StateFlow<JsonObject?> = _detail

    init { viewModelScope.launch { c.dataChanged.collect { reload() } } }

    fun reload() { _items.value = c.incidents.list() }

    fun newForm() {
        _form.value = IncidentForm()
        viewModelScope.launch {
            val fix = Locator(getApplication()).current()
            _form.value = _form.value.copy(fix = fix, gps = fix?.let { "con ubicación (±${it.accuracyM.toInt()} m)" } ?: "sin GPS")
        }
    }

    fun update(f: (IncidentForm) -> IncidentForm) { _form.value = f(_form.value).copy(errors = emptyList()) }

    fun sectorOf(eq: Equipment): Sector? = eq.sectorUuid?.let { u -> sectors.firstOrNull { it.uuid == u } }

    fun equipmentFromQr(raw: String): Equipment? =
        Regex("/q/([0-9a-fA-F-]{36})").find(raw)?.groupValues?.get(1)?.lowercase()?.let { u -> equipment.firstOrNull { it.uuid == u } }

    /** Mismas reglas que `IncidentService::validate` (el servidor vuelve a validar). */
    private fun validate(f: IncidentForm): List<String> = buildList {
        val type = INCIDENT_TYPES.firstOrNull { it.key == f.type }
        if (type == null) add("Elegí qué pasó.")
        if (f.sector == null && f.type != "in_itinere") add("Indicá el sector donde pasó.")
        if (f.description.trim().length < 10) add("Contá qué pasó (mínimo 10 letras).")
        if (type?.injured == true && f.people.none { it.role == "lesionado" }) add("Indicá quién se lastimó (lesionado).")
        if (f.occurredAt.isAfter(Instant.now().plusSeconds(300))) add("La fecha y hora no puede ser futura.")
    }

    fun save(onDone: () -> Unit) {
        val f = _form.value
        val errors = validate(f)
        if (errors.isNotEmpty()) { _form.value = f.copy(errors = errors); return }
        _form.value = f.copy(saving = true)
        viewModelScope.launch {
            c.incidents.create(IncidentDraft(f.type!!, if (f.now) Instant.now() else f.occurredAt, f.sector?.uuid, f.equipment?.uuid, f.location,
                f.description, f.immediate, f.severity, f.fix, f.people, f.photos))
            SyncWorker.now(getApplication())
            reload()
            onDone()
        }
    }

    fun openDetail(uuid: String) = viewModelScope.launch {
        _detail.value = null
        _detail.value = runCatching { c.incidents.detail(uuid) }.getOrNull()
    }
}

private val WHEN = DateTimeFormatter.ofPattern("dd/MM/yyyy HH:mm").withZone(ZoneId.systemDefault())

/** Módulo Incidentes: mis reportes y alta sin señal. Los datos de salud y la investigación se manejan en la web. */
@Composable
fun IncidentesScreen(onBack: () -> Unit, canCreate: Boolean, vm: IncidentsViewModel = viewModel()) {
    var page by remember { mutableStateOf<String?>(null) }
    var done by remember { mutableStateOf(false) }
    when (val p = page) {
        "nuevo" -> { IncidentFormScreen(vm, onBack = { page = null }, onSaved = { page = null; done = true }); return }
        null -> {}
        else -> { IncidentDetail(vm, p, onBack = { page = null }); return }
    }
    BackHandler(onBack = onBack)
    val items by vm.items.collectAsStateWithLifecycle()
    Scaffold(containerColor = MaterialTheme.colorScheme.background, topBar = { TopBar("Incidentes", onBack) }, floatingActionButton = {
        if (canCreate) ExtendedFloatingActionButton({ vm.newForm(); page = "nuevo" }, containerColor = Accent, contentColor = BrandDark) {
            Icon(Icons.Filled.Add, null); Spacer(Modifier.width(8.dp)); Text("Reportar", fontWeight = FontWeight.Bold)
        }
    }) { padding ->
        LazyColumn(Modifier.fillMaxSize().padding(padding), contentPadding = PaddingValues(16.dp, 16.dp, 16.dp, 96.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item { Text("Mis reportes", style = MaterialTheme.typography.titleSmall, color = Muted) }
            if (items.isEmpty()) item {
                Text("No reportaste incidentes. Si alguien se lastima o pasa algo que pudo terminar mal, tocá \"Reportar\": funciona sin señal.", color = Muted)
            }
            items(items, key = { it.uuid }) { i ->
                Card(Modifier.fillMaxWidth().clickable { if (!i.local) page = i.uuid }, colors = CardDefaults.cardColors(containerColor = Color.White)) {
                    Column(Modifier.padding(14.dp)) {
                        Row {
                            Text(i.code ?: "Nuevo", fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.labelLarge)
                            Spacer(Modifier.width(8.dp))
                            Text(i.typeLabel, color = if (INCIDENT_TYPES.firstOrNull { it.key == i.type }?.injured == true) Danger else Brand,
                                style = MaterialTheme.typography.labelMedium, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                            Text(hour(i.occurredAt), style = MaterialTheme.typography.labelSmall, color = Muted)
                        }
                        Text(i.description, maxLines = 2, overflow = TextOverflow.Ellipsis)
                        when {
                            i.failure != null -> Text("Rechazado: ${i.failure}", color = Danger, style = MaterialTheme.typography.bodySmall)
                            i.local -> Text("Pendiente de enviar (se manda solo con señal)", color = BrandDark, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                            else -> Text(i.statusLabel, color = if (i.status == "cerrado") Ok else Brand, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                        }
                    }
                }
            }
        }
    }
    if (done) AlertDialog({ done = false }, confirmButton = { TextButton({ done = false }) { Text("Entendido") } },
        text = { Text("Reporte guardado. Se envía solo con señal y avisa a supervisores y SyH. Si alguien está herido, llamá además a emergencias y al supervisor.") })
}

@Composable
private fun IncidentFormScreen(vm: IncidentsViewModel, onBack: () -> Unit, onSaved: () -> Unit) {
    val f by vm.form.collectAsStateWithLifecycle()
    val context = LocalContext.current
    var scanning by remember { mutableStateOf(false) }
    var pickSector by remember { mutableStateOf(false) }
    var pickEquipment by remember { mutableStateOf(false) }
    var addingPerson by remember { mutableStateOf(false) }
    var qrError by remember { mutableStateOf<String?>(null) }
    if (scanning) {
        QrScanner("Apuntá al QR del equipo", onResult = { raw ->
            scanning = false
            val eq = vm.equipmentFromQr(raw)
            if (eq == null) qrError = "Ese QR no es de un equipo de la empresa (o no está sincronizado)."
            else vm.update { it.copy(equipment = eq, sector = it.sector ?: vm.sectorOf(eq)) }
        }, onClose = { scanning = false })
        return
    }
    if (addingPerson) {
        PersonForm(vm, onCancel = { addingPerson = false }, onAdd = { p -> addingPerson = false; vm.update { it.copy(people = it.people + p) } })
        return
    }
    BackHandler(onBack = onBack)
    val type = INCIDENT_TYPES.firstOrNull { it.key == f.type }
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        TopBar("Reportar incidente", onBack)
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            Section("¿Qué pasó?") {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    INCIDENT_TYPES.forEach { t ->
                        val on = t.key == f.type
                        Column(Modifier.fillMaxWidth().background(if (on) (if (t.injured) Danger else Brand) else Color.White, RoundedCornerShape(12.dp))
                            .border(1.dp, if (on) Color.Transparent else Color(0xFFD5D8E0), RoundedCornerShape(12.dp))
                            .clickable { vm.update { it.copy(type = t.key) } }.padding(horizontal = 14.dp, vertical = 10.dp)) {
                            Text(t.label, fontWeight = FontWeight.Bold, color = if (on) Color.White else MaterialTheme.colorScheme.onSurface)
                            Text(t.hint, style = MaterialTheme.typography.bodySmall, color = if (on) Color(0xFFEDEFF4) else Muted)
                        }
                    }
                }
            }
            if (type?.serious == true) Text("Es grave: se avisa al instante a supervisores y SyH. Si hay heridos, llamá también a emergencias.",
                color = Danger, fontWeight = FontWeight.SemiBold)
            Section("¿Cuándo?") {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(if (f.now) "Recién (ahora)" else WHEN.format(f.occurredAt), Modifier.weight(1f), fontWeight = FontWeight.SemiBold)
                    OutlinedButton({
                        val cur = LocalDateTime.ofInstant(f.occurredAt, ZoneId.systemDefault())
                        DatePickerDialog(context, { _, y, m, d ->
                            TimePickerDialog(context, { _, h, min ->
                                val at = LocalDateTime.of(y, m + 1, d, h, min).atZone(ZoneId.systemDefault()).toInstant()
                                vm.update { it.copy(occurredAt = at, now = false) }
                            }, cur.hour, cur.minute, true).show()
                        }, cur.year, cur.monthValue - 1, cur.dayOfMonth).apply { datePicker.maxDate = System.currentTimeMillis() }.show()
                    }) { Text("Cambiar") }
                }
            }
            Section(if (f.type == "in_itinere") "¿Dónde? (opcional)" else "¿Dónde?") {
                OutlinedButton({ pickSector = true }, Modifier.fillMaxWidth()) {
                    Text(f.sector?.label ?: "Elegir sector", maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                Row(Modifier.padding(top = 6.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton({ scanning = true }, Modifier.weight(1f)) { Text("Escanear equipo") }
                    OutlinedButton({ pickEquipment = true }, Modifier.weight(1f)) { Text("Elegir equipo") }
                }
                f.equipment?.let { eq ->
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text("Equipo: ${eq.code} · ${eq.name}", Modifier.weight(1f))
                        IconButton({ vm.update { it.copy(equipment = null) } }) { Icon(Icons.Filled.Close, "Quitar equipo") }
                    }
                }
                OutlinedTextField(f.location, { v -> vm.update { it.copy(location = v.take(191)) } }, Modifier.fillMaxWidth().padding(top = 6.dp),
                    label = { Text(if (f.type == "in_itinere") "Dónde (calle, recorrido)" else "Lugar exacto (opcional)") }, singleLine = true)
                Text("GPS: ${f.gps}", style = MaterialTheme.typography.bodySmall, color = Muted, modifier = Modifier.padding(top = 4.dp))
            }
            Section("Personas" + if (type?.injured == true) " (indicá quién se lastimó)" else " (opcional)") {
                f.people.forEach { p ->
                    Row(Modifier.fillMaxWidth().background(Color.White, RoundedCornerShape(10.dp)).padding(start = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f).padding(vertical = 8.dp)) {
                            Text(p.label, fontWeight = FontWeight.SemiBold)
                            Text(PERSON_ROLES[p.role] + if (p.employee == null) " · externo" else "", style = MaterialTheme.typography.bodySmall,
                                color = if (p.role == "lesionado") Danger else Muted)
                        }
                        IconButton({ vm.update { it.copy(people = it.people - p) } }) { Icon(Icons.Filled.Close, "Quitar") }
                    }
                    Spacer(Modifier.height(6.dp))
                }
                OutlinedButton({ addingPerson = true }, Modifier.fillMaxWidth()) { Text("Agregar persona") }
            }
            Section("Qué pasó") {
                OutlinedTextField(f.description, { v -> vm.update { it.copy(description = v.take(4000)) } }, Modifier.fillMaxWidth().heightIn(min = 120.dp),
                    placeholder = { Text("Contá qué pasó, cómo y qué se estaba haciendo") })
            }
            Section("Qué se hizo en el momento (opcional)") {
                OutlinedTextField(f.immediate, { v -> vm.update { it.copy(immediate = v.take(2000)) } }, Modifier.fillMaxWidth().heightIn(min = 72.dp),
                    placeholder = { Text("Primeros auxilios, se paró la máquina, se señalizó…") })
            }
            if (vm.severities.isNotEmpty()) Section("¿Qué tan grave pudo ser? (opcional)") {
                Chips(vm.severities, f.severity, colored = true) { id -> vm.update { it.copy(severity = if (it.severity == id) null else id) } }
            }
            Section("Fotos (${f.photos.size}/6)") { PhotosField(f.photos, 6) { list -> vm.update { it.copy(photos = list) } } }
            Text("No saques fotos de heridas: las fotos las ve cualquiera que vea el incidente. Los datos médicos se cargan en la web.", style = MaterialTheme.typography.bodySmall, color = Muted)
            f.errors.forEach { Text(it, color = Danger) }
            Button({ vm.save(onSaved) }, Modifier.fillMaxWidth().height(56.dp), enabled = !f.saving,
                colors = ButtonDefaults.buttonColors(containerColor = Accent, contentColor = BrandDark)) {
                if (f.saving) CircularProgressIndicator(strokeWidth = 2.dp, color = BrandDark)
                else Text("Enviar reporte", fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
            }
            Text("Si no hay señal queda guardado en el celular y se envía solo al reconectar.", style = MaterialTheme.typography.bodySmall, color = Muted)
        }
    }
    if (pickSector) SearchDialog("Sector", vm.sectors.map { it.uuid to it.label }, onPick = { id ->
        pickSector = false; vm.update { it.copy(sector = vm.sectors.first { s -> s.uuid == id }) }
    }, onDismiss = { pickSector = false })
    if (pickEquipment) SearchDialog("Equipo", vm.equipment.map { it.uuid to "${it.code} · ${it.name}" }, onPick = { id ->
        pickEquipment = false
        val eq = vm.equipment.first { it.uuid == id }
        vm.update { it.copy(equipment = eq, sector = it.sector ?: vm.sectorOf(eq)) }
    }, onDismiss = { pickEquipment = false })
    qrError?.let { m -> AlertDialog({ qrError = null }, confirmButton = { TextButton({ qrError = null }) { Text("Entendido") } }, text = { Text(m) }) }
}

/** Alta de una persona: empleado (buscando en los sincronizados) o externo. */
@Composable
private fun PersonForm(vm: IncidentsViewModel, onCancel: () -> Unit, onAdd: (IncidentPerson) -> Unit) {
    BackHandler(onBack = onCancel)
    var p by remember { mutableStateOf(IncidentPerson(role = "lesionado")) }
    var external by remember { mutableStateOf(false) }
    var pickEmployee by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        TopBar("Agregar persona", onCancel)
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            Section("Rol") {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    PERSON_ROLES.forEach { (key, label) ->
                        val on = p.role == key
                        Text(label, Modifier.weight(1f).background(if (on) Brand else Color.White, RoundedCornerShape(12.dp))
                            .border(1.dp, if (on) Color.Transparent else Color(0xFFD5D8E0), RoundedCornerShape(12.dp))
                            .clickable { p = p.copy(role = key) }.padding(vertical = 14.dp),
                            color = if (on) Color.White else MaterialTheme.colorScheme.onSurface, fontWeight = FontWeight.Bold,
                            textAlign = androidx.compose.ui.text.style.TextAlign.Center)
                    }
                }
            }
            Section("¿Quién?") {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedButton({ external = false }, Modifier.weight(1f), enabled = external) { Text("Empleado") }
                    OutlinedButton({ external = true; p = p.copy(employee = null) }, Modifier.weight(1f), enabled = !external) { Text("Externo") }
                }
                if (!external) OutlinedButton({ pickEmployee = true }, Modifier.fillMaxWidth().padding(top = 8.dp)) {
                    Text(p.employee?.name ?: "Buscar empleado", maxLines = 1, overflow = TextOverflow.Ellipsis)
                } else Column(Modifier.padding(top = 8.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    OutlinedTextField(p.externalName, { p = p.copy(externalName = it.take(160)) }, Modifier.fillMaxWidth(), label = { Text("Nombre y apellido") }, singleLine = true)
                    OutlinedTextField(p.externalDni, { p = p.copy(externalDni = it.filter(Char::isDigit).take(11)) }, Modifier.fillMaxWidth(), label = { Text("DNI (opcional)") },
                        singleLine = true, keyboardOptions = androidx.compose.foundation.text.KeyboardOptions(keyboardType = androidx.compose.ui.text.input.KeyboardType.Number))
                    OutlinedTextField(p.externalCompany, { p = p.copy(externalCompany = it.take(160)) }, Modifier.fillMaxWidth(), label = { Text("Empresa (opcional)") }, singleLine = true)
                }
            }
            if (p.role == "lesionado") Section("Qué lesión se ve (opcional)") {
                OutlinedTextField(p.injury, { p = p.copy(injury = it.take(500)) }, Modifier.fillMaxWidth(), placeholder = { Text("Ej.: corte en la mano izquierda") })
                Text("Solo lo ve personal autorizado (SyH).", style = MaterialTheme.typography.bodySmall, color = Muted)
            }
            if (p.role == "testigo") Section("Qué vio (opcional)") {
                OutlinedTextField(p.statement, { p = p.copy(statement = it.take(2000)) }, Modifier.fillMaxWidth().heightIn(min = 96.dp))
            }
            error?.let { Text(it, color = Danger) }
            Button({
                if (p.employee == null && p.externalName.trim().length < 3) error = "Elegí el empleado o escribí el nombre." else onAdd(p)
            }, Modifier.fillMaxWidth().height(52.dp), colors = ButtonDefaults.buttonColors(containerColor = Brand)) { Text("Agregar", fontWeight = FontWeight.Bold) }
        }
    }
    if (pickEmployee) SearchDialog("Empleado", vm.employees.map { it.uuid to it.name }, onPick = { id ->
        pickEmployee = false; p = p.copy(employee = vm.employees.first { it.uuid == id }); error = null
    }, onDismiss = { pickEmployee = false })
}

@Composable
private fun IncidentDetail(vm: IncidentsViewModel, uuid: String, onBack: () -> Unit) {
    LaunchedEffect(uuid) { vm.openDetail(uuid) }
    BackHandler(onBack = onBack)
    val d by vm.detail.collectAsStateWithLifecycle()
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        TopBar(d?.str("code") ?: "Incidente", onBack)
        val data = d
        if (data == null) { Text("Cargando… (necesita señal)", Modifier.padding(16.dp), color = Muted); return@Column }
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(data.str("type_label"), fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
            Text(data.str("status_label"), color = Brand, fontWeight = FontWeight.SemiBold)
            Text(data.str("description"))
            Info("Cuándo", hour(data.strOrNull("occurred_at")))
            Info("Sector", data.strOrNull("sector")); Info("Equipo", data.strOrNull("equipment")); Info("Lugar", data.strOrNull("location"))
            Info("Qué se hizo", data.strOrNull("immediate_actions")); Info("Reportó", data.strOrNull("reporter"))
            val people = data.arr("people").mapNotNull { it as? JsonObject }
            if (people.isNotEmpty()) Info("Personas", people.joinToString(" · ") { "${it.str("name")} (${it.str("role")})" })
            Text("La investigación y el seguimiento se hacen en la web.", color = Muted, style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(top = 8.dp))
        }
    }
}

@Composable
private fun Info(label: String, value: String?) {
    if (value.isNullOrBlank()) return
    Row { Text("$label: ", color = Muted, style = MaterialTheme.typography.bodySmall); Text(value, style = MaterialTheme.typography.bodySmall) }
}
