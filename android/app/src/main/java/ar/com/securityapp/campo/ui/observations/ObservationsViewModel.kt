package ar.com.securityapp.campo.ui.observations

import android.app.Application
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import ar.com.securityapp.campo.CampoApp
import ar.com.securityapp.campo.data.CatalogItem
import ar.com.securityapp.campo.data.Equipment
import ar.com.securityapp.campo.data.Fix
import ar.com.securityapp.campo.data.Observation
import ar.com.securityapp.campo.data.ObservationDraft
import ar.com.securityapp.campo.data.Sector
import ar.com.securityapp.campo.data.arr
import ar.com.securityapp.campo.data.strOrNull
import ar.com.securityapp.campo.location.Locator
import ar.com.securityapp.campo.sync.SyncWorker
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonObject
import java.time.Instant

data class ObsListUi(val mine: List<Observation> = emptyList(), val others: List<Observation> = emptyList(),
                     val failures: Map<String, String> = emptyMap(), val sectors: Map<String, String> = emptyMap(),
                     val severities: Map<String, CatalogItem> = emptyMap())

data class FormUi(
    val categories: List<CatalogItem> = emptyList(), val severities: List<CatalogItem> = emptyList(), val risks: List<CatalogItem> = emptyList(),
    val sectors: List<Sector> = emptyList(), val equipment: List<Equipment> = emptyList(), val anonymousAllowed: Boolean = false,
    val category: String? = null, val severity: String? = null, val risk: String? = null, val sector: String? = null, val equipmentUuid: String? = null,
    val description: String = "", val location: String = "", val imminent: Boolean = false, val anonymous: Boolean = false,
    val photos: List<Uri> = emptyList(), val fix: Fix? = null, val gps: String = "buscando…", val saving: Boolean = false, val errors: List<String> = emptyList(),
)

data class DetailUi(val local: Observation? = null, val remote: JsonObject? = null, val photos: List<Bitmap> = emptyList(), val loading: Boolean = true,
                    val failure: String? = null)

class ObservationsViewModel(app: Application) : AndroidViewModel(app) {
    private val c = (app as CampoApp).container
    private val _list = MutableStateFlow(ObsListUi())
    val list: StateFlow<ObsListUi> = _list
    private val _form = MutableStateFlow(FormUi())
    val form: StateFlow<FormUi> = _form
    private val _detail = MutableStateFlow(DetailUi())
    val detail: StateFlow<DetailUi> = _detail

    init {
        reload()
        viewModelScope.launch { c.dataChanged.collect { reload() } }
    }

    fun reload() {
        val all = c.observations.list()
        _list.value = ObsListUi(
            mine = all.filter { it.mine || it.local }, others = all.filter { !it.mine && !it.local }.take(50),
            failures = all.filter { it.local }.mapNotNull { o -> c.observations.failure(o.uuid)?.let { o.uuid to it } }.toMap(),
            sectors = c.observations.sectors().associate { it.uuid to it.label },
            severities = c.observations.catalog("severidad").associateBy { it.uuid },
        )
    }

    // ── formulario ───────────────────────────────────────────────

    fun newForm() {
        val r = c.observations
        _form.value = FormUi(categories = r.catalog("categoria"), severities = r.catalog("severidad"), risks = r.catalog("tipo_riesgo"),
            sectors = r.sectors(), equipment = r.equipment(), anonymousAllowed = r.anonymousAllowed(c.prefs))
        viewModelScope.launch {
            val fix = Locator(getApplication()).current()
            _form.value = _form.value.copy(fix = fix, gps = fix?.let { "con ubicación (±${it.accuracyM.toInt()} m)" } ?: "sin GPS")
        }
    }

    fun update(f: (FormUi) -> FormUi) { _form.value = f(_form.value).copy(errors = emptyList()) }

    /** QR del equipo (`/q/{uuid}`): elige el equipo y, si no hay sector, el del equipo. */
    fun onEquipmentQr(raw: String): Boolean {
        val uuid = Regex("/q/([0-9a-fA-F-]{36})").find(raw)?.groupValues?.get(1)?.lowercase() ?: return false
        val eq = _form.value.equipment.firstOrNull { it.uuid == uuid } ?: return false
        update { it.copy(equipmentUuid = eq.uuid, sector = it.sector ?: eq.sectorUuid) }
        return true
    }

    fun validate(): List<String> {
        val f = _form.value
        return buildList {
            if (f.category == null) add("Elegí qué es (acto, condición…).")
            if (f.severity == null) add("Elegí la severidad.")
            if (f.sector == null) add("Elegí el sector.")
            if (f.description.trim().length < 10) add("Contá qué viste (mínimo 10 letras).")
        }
    }

    fun save(onDone: () -> Unit) {
        val f = _form.value
        val errors = validate()
        if (errors.isNotEmpty()) { _form.value = f.copy(errors = errors); return }
        _form.value = f.copy(saving = true)
        viewModelScope.launch {
            try {
                c.observations.create(ObservationDraft(f.category!!, f.severity!!, f.risk, f.sector!!, f.equipmentUuid, f.description, f.location,
                    Instant.now(), f.imminent, f.anonymous && f.anonymousAllowed, f.fix, f.photos))
                SyncWorker.now(getApplication())
                reload()
                onDone()
            } catch (e: Exception) {
                _form.value = _form.value.copy(saving = false, errors = listOf("No se pudo guardar: ${e.message}"))
            }
        }
    }

    // ── detalle ──────────────────────────────────────────────────

    fun openDetail(uuid: String) {
        val local = c.observations.list().firstOrNull { it.uuid == uuid }
        _detail.value = DetailUi(local = local, failure = c.observations.failure(uuid))
        if (local?.local == true) { _detail.value = _detail.value.copy(loading = false); return }
        viewModelScope.launch {
            val remote = runCatching { c.observations.detail(uuid) }.getOrNull()
            _detail.value = _detail.value.copy(remote = remote, loading = false)
            val urls = remote?.arr("photos")?.mapNotNull { (it as? JsonObject)?.strOrNull("url") }.orEmpty()
            val bitmaps = urls.take(6).mapNotNull { loadPhoto(it) }
            _detail.value = _detail.value.copy(photos = bitmaps)
        }
    }

    /** Las fotos se piden con el token (nunca hay links públicos a archivos de la empresa). */
    private suspend fun loadPhoto(path: String): Bitmap? = runCatching {
        c.api.bytes(path)?.let { BitmapFactory.decodeByteArray(it, 0, it.size) }
    }.getOrNull()
}
