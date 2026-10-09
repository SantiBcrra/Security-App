package ar.com.securityapp.campo.ui.inspections

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
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
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
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
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import ar.com.securityapp.campo.data.Checklist
import ar.com.securityapp.campo.data.CheckItem
import ar.com.securityapp.campo.data.Equipment
import ar.com.securityapp.campo.data.MyInspection
import ar.com.securityapp.campo.data.arr
import ar.com.securityapp.campo.data.str
import ar.com.securityapp.campo.data.strOrNull
import ar.com.securityapp.campo.ui.actions.day
import ar.com.securityapp.campo.ui.observations.PhotosField
import ar.com.securityapp.campo.ui.observations.SearchDialog
import ar.com.securityapp.campo.ui.observations.TopBar
import ar.com.securityapp.campo.ui.observations.hour
import ar.com.securityapp.campo.ui.rounds.QrScanner
import ar.com.securityapp.campo.ui.theme.Accent
import ar.com.securityapp.campo.ui.theme.Brand
import ar.com.securityapp.campo.ui.theme.BrandDark
import ar.com.securityapp.campo.ui.theme.Danger
import ar.com.securityapp.campo.ui.theme.Muted
import ar.com.securityapp.campo.ui.theme.Ok
import kotlinx.serialization.json.JsonObject
import java.time.LocalDate

private val SCOPES = mapOf("equipo" to "De equipos", "sector" to "De sectores", "general" to "Generales")

/** Módulo Inspecciones: para hacer (programadas a mi cargo), nueva (QR del equipo o lista) y las que hice. */
@Composable
fun InspeccionesScreen(onBack: () -> Unit, canCreate: Boolean, vm: InspectionsViewModel = viewModel()) {
    val form by vm.form.collectAsStateWithLifecycle()
    var page by remember { mutableStateOf<String?>(null) } // "nueva", "qr", o uuid del detalle
    var picked by remember { mutableStateOf<Checklist?>(null) } // checklist elegido, falta el objetivo
    var qrEquipment by remember { mutableStateOf<Equipment?>(null) }
    var message by remember { mutableStateOf<String?>(null) }

    form?.let {
        ChecklistForm(vm, onCancel = { vm.cancel() }, onSaved = { result ->
            page = null; picked = null; qrEquipment = null
            message = "Inspección guardada: $result. Se envía sola con señal (el resultado oficial lo calcula el servidor)."
        })
        return
    }
    when (val p = page) {
        "qr" -> {
            QrScanner("Apuntá al QR del equipo", onResult = { raw ->
                val eq = vm.equipmentFromQr(raw)
                page = "nueva"
                if (eq == null) message = "Ese QR no es de un equipo de la empresa (o no está sincronizado)."
                else {
                    val lists = vm.checklistsFor(eq)
                    when (lists.size) {
                        0 -> message = "${eq.code} no tiene checklists para su tipo de equipo."
                        1 -> vm.begin(lists[0], eq)
                        else -> qrEquipment = eq
                    }
                }
            }, onClose = { page = "nueva" })
            return
        }
        "nueva" -> { NewInspection(vm, onBack = { page = null }, onQr = { page = "qr" }, onPick = { picked = it }); }
        null -> {}
        else -> { InspectionDetail(vm, p, onBack = { page = null }); return }
    }

    if (page == null) {
        BackHandler(onBack = onBack)
        val ui by vm.list.collectAsStateWithLifecycle()
        Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
            TopBar("Inspecciones", onBack)
            LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                if (canCreate) item {
                    Button({ page = "nueva" }, Modifier.fillMaxWidth().height(56.dp), colors = ButtonDefaults.buttonColors(containerColor = Accent, contentColor = BrandDark)) {
                        Text("Hacer una inspección", fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
                    }
                }
                item { Text("Para hacer", style = MaterialTheme.typography.titleSmall, color = Muted) }
                if (ui.todo.isEmpty()) item { Text("No tenés inspecciones programadas pendientes.", color = Muted) }
                items(ui.todo, key = { it.first.uuid }) { (s, label) ->
                    val today = LocalDate.now().toString()
                    val late = s.dueOn < today
                    Card(Modifier.fillMaxWidth().clickable { if (!vm.beginScheduled(s)) message = "El checklist de esta programada no está en el celular: sincronizá." },
                        colors = CardDefaults.cardColors(containerColor = if (late) Color(0xFFFDE3E5) else Color.White)) {
                        Column(Modifier.padding(14.dp)) {
                            Text(label, fontWeight = FontWeight.SemiBold)
                            Text("${s.program} · ${if (late) "VENCIDA el" else if (s.dueOn == today) "vence HOY" else "vence"} ${day(s.dueOn)}",
                                style = MaterialTheme.typography.bodySmall, color = if (late) Danger else Muted)
                        }
                    }
                }
                item { Text("Las que hice desde este celular", style = MaterialTheme.typography.titleSmall, color = Muted, modifier = Modifier.padding(top = 8.dp)) }
                if (ui.mine.isEmpty()) item { Text("Todavía ninguna.", color = Muted) }
                items(ui.mine, key = { it.uuid }) { i -> MineRow(i) { if (!i.local) page = i.uuid } }
            }
        }
    }

    picked?.let { cl -> TargetDialog(vm, cl, onDismiss = { picked = null }, onChosen = { picked = null }) }
    qrEquipment?.let { eq ->
        AlertDialog(onDismissRequest = { qrEquipment = null }, title = { Text("${eq.code} · ${eq.name}") }, text = {
            Column { vm.checklistsFor(eq).forEach { cl -> TextButton({ qrEquipment = null; vm.begin(cl, eq) }) { Text(cl.name) } } }
        }, confirmButton = { TextButton({ qrEquipment = null }) { Text("Cancelar") } })
    }
    message?.let { m -> AlertDialog({ message = null }, confirmButton = { TextButton({ message = null }) { Text("Entendido") } }, text = { Text(m) }) }
}

@Composable
private fun MineRow(i: MyInspection, onClick: () -> Unit) {
    Card(Modifier.fillMaxWidth().clickable(onClick = onClick), colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Column(Modifier.padding(14.dp)) {
            Row {
                Text(i.code ?: "Nueva", fontWeight = FontWeight.SemiBold, style = MaterialTheme.typography.labelLarge)
                Spacer(Modifier.weight(1f))
                Text(hour(i.doneAt), style = MaterialTheme.typography.labelSmall, color = Muted)
            }
            Text("${i.template} · ${i.target}", maxLines = 2, overflow = TextOverflow.Ellipsis)
            when {
                i.failure != null -> Text("Rechazada: ${i.failure}", color = Danger, style = MaterialTheme.typography.bodySmall)
                i.local -> Text("Pendiente de enviar (se manda sola con señal)", color = BrandDark, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
                else -> Text(listOfNotNull(i.resultLabel, i.score?.let { "$it%" }, if (i.actions > 0) "${i.actions} acción(es) creadas" else null).joinToString(" · "),
                    color = if (i.resultLabel == "Conforme") Ok else Danger, style = MaterialTheme.typography.bodySmall, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

@Composable
private fun NewInspection(vm: InspectionsViewModel, onBack: () -> Unit, onQr: () -> Unit, onPick: (Checklist) -> Unit) {
    BackHandler(onBack = onBack)
    val ui by vm.list.collectAsStateWithLifecycle()
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        TopBar("Nueva inspección", onBack)
        LazyColumn(Modifier.fillMaxSize(), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item {
                Button(onQr, Modifier.fillMaxWidth().height(56.dp), colors = ButtonDefaults.buttonColors(containerColor = Accent, contentColor = BrandDark)) {
                    Text("Escanear QR del equipo", fontWeight = FontWeight.Bold)
                }
            }
            if (ui.checklists.isEmpty()) item { Text("No hay checklists en el celular. Sincronizá con señal.", color = Muted) }
            for ((scope, label) in SCOPES) {
                val group = ui.checklists.filter { it.scope == scope }
                if (group.isEmpty()) continue
                item { Text(label, style = MaterialTheme.typography.titleSmall, color = Muted, modifier = Modifier.padding(top = 8.dp)) }
                items(group, key = { it.uuid }) { cl ->
                    Card(Modifier.fillMaxWidth().clickable { onPick(cl) }, colors = CardDefaults.cardColors(containerColor = Color.White)) {
                        Column(Modifier.padding(14.dp)) {
                            Text(cl.name, fontWeight = FontWeight.SemiBold)
                            Text("${cl.items.size} ítems" + (cl.description?.let { " · $it" } ?: ""), style = MaterialTheme.typography.bodySmall, color = Muted,
                                maxLines = 2, overflow = TextOverflow.Ellipsis)
                        }
                    }
                }
            }
        }
    }
}

/** Qué se inspecciona: equipo del tipo del checklist, sector, o nada (general). */
@Composable
private fun TargetDialog(vm: InspectionsViewModel, cl: Checklist, onDismiss: () -> Unit, onChosen: () -> Unit) {
    when (cl.scope) {
        "equipo" -> {
            val options = vm.equipment.filter { vm.typeOf(it) == cl.typeUuid }
            SearchDialog("¿Qué equipo?", options.map { it.uuid to "${it.code} · ${it.name}" }, onPick = { id ->
                onChosen(); vm.begin(cl, equipment = options.first { it.uuid == id })
            }, onDismiss = onDismiss)
        }
        "sector" -> SearchDialog("¿Qué sector?", vm.sectors.map { it.uuid to it.label }, onPick = { id ->
            onChosen(); vm.begin(cl, sector = vm.sectors.first { it.uuid == id })
        }, onDismiss = onDismiss)
        else -> LaunchedEffect(cl.uuid) { onChosen(); vm.begin(cl) }
    }
}

@Composable
private fun ChecklistForm(vm: InspectionsViewModel, onCancel: () -> Unit, onSaved: (String) -> Unit) {
    val f by vm.form.collectAsStateWithLifecycle()
    val form = f ?: return
    var confirmExit by remember { mutableStateOf(false) }
    BackHandler { if (form.answers.isEmpty()) onCancel() else confirmExit = true }
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        TopBar(form.checklist.name, { if (form.answers.isEmpty()) onCancel() else confirmExit = true })
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text(form.target, fontWeight = FontWeight.SemiBold, color = Brand)
            for (section in form.checklist.sections) {
                if (section.title.isNotBlank()) Text(section.title, style = MaterialTheme.typography.titleSmall, color = Muted, modifier = Modifier.padding(top = 6.dp))
                for (item in section.items) ItemCard(vm, item, form.answers[item.key] ?: ar.com.securityapp.campo.data.Answer(), form.errors[item.key])
            }
            val ev = vm.evaluation()
            if (ev != null && ev.fail > 0) Text(
                "${ev.fail} ítem(s) no cumplen" + (if (ev.criticalFail > 0) ", ${ev.criticalFail} CRÍTICO(S): se avisa a supervisores y SyH." else ".") +
                    " Se crea una acción correctiva por cada uno.", color = Danger, style = MaterialTheme.typography.bodySmall)
            if (form.errors.isNotEmpty()) Text("Faltan ${form.errors.size} dato(s): revisá los ítems marcados en rojo.", color = Danger, fontWeight = FontWeight.SemiBold)
            Button({ vm.save(onSaved) }, Modifier.fillMaxWidth().height(56.dp), enabled = !form.saving,
                colors = ButtonDefaults.buttonColors(containerColor = Accent, contentColor = BrandDark)) {
                if (form.saving) CircularProgressIndicator(strokeWidth = 2.dp, color = BrandDark)
                else Text("Guardar inspección", fontWeight = FontWeight.Bold, style = MaterialTheme.typography.titleMedium)
            }
        }
    }
    if (confirmExit) AlertDialog(onDismissRequest = { confirmExit = false }, title = { Text("¿Salir sin guardar?") },
        text = { Text("Se pierden las respuestas cargadas.") },
        confirmButton = { TextButton({ confirmExit = false; onCancel() }) { Text("Salir", color = Danger) } },
        dismissButton = { TextButton({ confirmExit = false }) { Text("Seguir") } })
}

@Composable
private fun ItemCard(vm: InspectionsViewModel, item: CheckItem, a: ar.com.securityapp.campo.data.Answer, error: String?) {
    val fails = when (item.type) {
        "si_no", "si_no_na" -> a.value != null && a.value != "na" && a.value != item.okWhen
        "numero" -> a.value?.replace(',', '.')?.toDoubleOrNull()?.let { n -> (item.min != null && n < item.min) || (item.max != null && n > item.max) } ?: false
        else -> false
    }
    Card(Modifier.fillMaxWidth().then(if (error != null) Modifier.border(2.dp, Danger, RoundedCornerShape(14.dp)) else Modifier),
        colors = CardDefaults.cardColors(containerColor = if (fails) Color(0xFFFFF4F4) else Color.White)) {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row {
                Text(item.text, Modifier.weight(1f), fontWeight = FontWeight.SemiBold)
                if (item.critical) Text("CRÍTICO", color = Danger, style = MaterialTheme.typography.labelSmall, fontWeight = FontWeight.Bold)
            }
            item.help?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Muted) }
            when (item.type) {
                "si_no", "si_no_na" -> Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    val opts = listOf("si" to "Sí", "no" to "No") + if (item.type == "si_no_na") listOf("na" to "N/A") else emptyList()
                    for ((v, label) in opts) {
                        val on = a.value == v
                        val good = v == "na" || v == item.okWhen
                        Box(Modifier.weight(1f).height(52.dp).background(if (on) (if (good) Ok else Danger) else Color.White, RoundedCornerShape(12.dp))
                            .border(1.dp, if (on) Color.Transparent else Color(0xFFD5D8E0), RoundedCornerShape(12.dp))
                            .clickable { vm.answer(item.key) { it.copy(value = v) } }, contentAlignment = Alignment.Center) {
                            Text(label, color = if (on) Color.White else MaterialTheme.colorScheme.onSurface, fontWeight = FontWeight.Bold)
                        }
                    }
                }
                "numero" -> OutlinedTextField(a.value ?: "", { v -> vm.answer(item.key) { it.copy(value = v.take(20)) } }, Modifier.fillMaxWidth(), singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                    suffix = item.unit?.let { u -> { Text(u) } },
                    supportingText = if (item.min != null || item.max != null) { { Text(listOfNotNull(item.min?.let { "mín. ${fmt(it)}" }, item.max?.let { "máx. ${fmt(it)}" }).joinToString(" · ")) } } else null)
                else -> OutlinedTextField(a.value ?: "", { v -> vm.answer(item.key) { it.copy(value = v.take(255)) } }, Modifier.fillMaxWidth())
            }
            if (fails || a.comment.isNotEmpty()) OutlinedTextField(a.comment, { v -> vm.answer(item.key) { it.copy(comment = v.take(2000)) } },
                Modifier.fillMaxWidth().heightIn(min = 72.dp), label = { Text(if (fails) "Qué pasa (obligatorio)" else "Comentario") })
            val photoNeeded = item.photo == "siempre" || (item.photo == "si_no_cumple" && fails)
            if (photoNeeded || fails || a.photos.isNotEmpty()) {
                Text(if (photoNeeded) "Foto obligatoria" else "Foto (opcional)", style = MaterialTheme.typography.labelMedium, color = if (photoNeeded) Danger else Muted)
                PhotosField(a.photos, 3) { list -> vm.answer(item.key) { it.copy(photos = list) } }
            }
            error?.let { Text(it, color = Danger, style = MaterialTheme.typography.bodySmall) }
        }
    }
}

private fun fmt(d: Double) = if (d % 1.0 == 0.0) d.toLong().toString() else d.toString()

@Composable
private fun InspectionDetail(vm: InspectionsViewModel, uuid: String, onBack: () -> Unit) {
    LaunchedEffect(uuid) { vm.openDetail(uuid) }
    BackHandler(onBack = onBack)
    val d by vm.detail.collectAsStateWithLifecycle()
    Column(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
        TopBar(d?.str("code") ?: "Inspección", onBack)
        val data = d
        if (data == null) { Text("Cargando… (necesita señal)", Modifier.padding(16.dp), color = Muted); return@Column }
        Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(data.str("result_label") + (data.strOrNull("score")?.let { " · $it%" } ?: ""), fontWeight = FontWeight.Bold,
                color = if (data.str("result") == "conforme") Ok else Danger, style = MaterialTheme.typography.titleMedium)
            Text(listOfNotNull(data.strOrNull("template"), data.strOrNull("equipment") ?: data.strOrNull("sector")).joinToString(" · "))
            Text("${data.str("inspector")} · ${hour(data.strOrNull("done_at"))}", color = Muted, style = MaterialTheme.typography.bodySmall)
            data.arr("answers").mapNotNull { it as? JsonObject }.forEach { a ->
                val ok = a["ok"]?.toString()
                Column(Modifier.fillMaxWidth().background(if (ok == "false") Color(0xFFFFF4F4) else Color.White, RoundedCornerShape(10.dp)).padding(10.dp)) {
                    Row {
                        Text(a.str("text"), Modifier.weight(1f), style = MaterialTheme.typography.bodyMedium)
                        Text(a.str("value"), fontWeight = FontWeight.Bold, color = when (ok) { "true" -> Ok; "false" -> Danger; else -> Muted })
                    }
                    a.strOrNull("comment")?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Muted) }
                    a.strOrNull("action_code")?.let { Text("Acción $it", style = MaterialTheme.typography.bodySmall, color = Brand, fontWeight = FontWeight.SemiBold) }
                }
            }
        }
    }
}
