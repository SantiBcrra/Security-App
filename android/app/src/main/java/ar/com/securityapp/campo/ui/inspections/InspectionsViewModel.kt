package ar.com.securityapp.campo.ui.inspections

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import ar.com.securityapp.campo.CampoApp
import ar.com.securityapp.campo.data.Answer
import ar.com.securityapp.campo.data.Checklist
import ar.com.securityapp.campo.data.Equipment
import ar.com.securityapp.campo.data.MyInspection
import ar.com.securityapp.campo.data.Scheduled
import ar.com.securityapp.campo.data.Sector
import ar.com.securityapp.campo.sync.SyncWorker
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject

data class ListUi(val todo: List<Pair<Scheduled, String>> = emptyList(), val mine: List<MyInspection> = emptyList(), val checklists: List<Checklist> = emptyList())

data class FormUi(val checklist: Checklist, val equipment: Equipment? = null, val sector: Sector? = null, val schedule: String? = null,
                  val answers: Map<String, Answer> = emptyMap(), val errors: Map<String, String> = emptyMap(), val saving: Boolean = false) {
    val target get() = equipment?.let { "${it.code} · ${it.name}" } ?: sector?.label ?: "General"
}

class InspectionsViewModel(app: Application) : AndroidViewModel(app) {
    private val c = (app as CampoApp).container
    private val repo get() = c.inspections
    val equipment: List<Equipment> by lazy { c.observations.equipment() }
    val sectors: List<Sector> by lazy { c.observations.sectors() }
    private val _list = MutableStateFlow(ListUi())
    val list: StateFlow<ListUi> = _list
    private val _form = MutableStateFlow<FormUi?>(null)
    val form: StateFlow<FormUi?> = _form
    private val _detail = MutableStateFlow<JsonObject?>(null)
    val detail: StateFlow<JsonObject?> = _detail

    init {
        reload()
        viewModelScope.launch { c.dataChanged.collect { reload() } }
    }

    fun reload() {
        val lists = repo.checklists()
        val names = lists.associate { it.uuid to it.name }
        _list.value = ListUi(
            todo = repo.scheduled().map { s -> s to listOfNotNull(names[s.templateUuid], targetName(s.equipmentUuid, s.sectorUuid)).joinToString(" · ") },
            mine = repo.mine(), checklists = lists,
        )
    }

    fun targetName(equipmentUuid: String?, sectorUuid: String?): String? =
        equipmentUuid?.let { u -> equipment.firstOrNull { it.uuid == u }?.let { "${it.code} · ${it.name}" } } ?: sectorUuid?.let { u -> sectors.firstOrNull { it.uuid == u }?.label }

    fun typeOf(eq: Equipment): String? = repo.equipmentType(eq.uuid)

    /** Checklists del tipo del equipo (por QR o lista). */
    fun checklistsFor(eq: Equipment): List<Checklist> = repo.forEquipment(eq, typeOf(eq))

    fun equipmentFromQr(raw: String): Equipment? {
        val uuid = Regex("/q/([0-9a-fA-F-]{36})").find(raw)?.groupValues?.get(1)?.lowercase() ?: return null
        return equipment.firstOrNull { it.uuid == uuid }
    }

    fun begin(checklist: Checklist, equipment: Equipment? = null, sector: Sector? = null, schedule: String? = null) {
        val sec = sector ?: equipment?.sectorUuid?.let { u -> sectors.firstOrNull { it.uuid == u } }
        _form.value = FormUi(checklist, equipment, sec, schedule)
    }

    fun beginScheduled(s: Scheduled): Boolean {
        val cl = _list.value.checklists.firstOrNull { it.uuid == s.templateUuid } ?: return false
        begin(cl, equipment.firstOrNull { it.uuid == s.equipmentUuid }, sectors.firstOrNull { it.uuid == s.sectorUuid }, s.uuid)
        return true
    }

    fun answer(key: String, f: (Answer) -> Answer) {
        val form = _form.value ?: return
        val answers = form.answers + (key to f(form.answers[key] ?: Answer()))
        // Los errores se recalculan solo si ya se intentó enviar (no molestar mientras completa).
        _form.value = form.copy(answers = answers, errors = if (form.errors.isEmpty()) emptyMap() else repo.evaluate(form.checklist, answers).errors)
    }

    fun evaluation() = _form.value?.let { repo.evaluate(it.checklist, it.answers) }

    fun cancel() { _form.value = null }

    fun save(onDone: (String) -> Unit) {
        val form = _form.value ?: return
        val ev = repo.evaluate(form.checklist, form.answers)
        if (ev.errors.isNotEmpty()) { _form.value = form.copy(errors = ev.errors); return }
        _form.value = form.copy(saving = true)
        viewModelScope.launch {
            repo.create(form.checklist, form.equipment?.uuid, form.sector?.uuid, form.schedule, form.answers, form.target)
            SyncWorker.now(getApplication())
            _form.value = null
            reload()
            onDone(ev.result)
        }
    }

    fun openDetail(uuid: String) = viewModelScope.launch {
        _detail.value = null
        _detail.value = runCatching { repo.detail(uuid) }.getOrNull()
    }
}
