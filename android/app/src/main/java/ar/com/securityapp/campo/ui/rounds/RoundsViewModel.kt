package ar.com.securityapp.campo.ui.rounds

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import ar.com.securityapp.campo.CampoApp
import ar.com.securityapp.campo.data.PatrolPoint
import ar.com.securityapp.campo.data.PatrolRound
import ar.com.securityapp.campo.data.PatrolRoute
import ar.com.securityapp.campo.data.RoundsRepository.ScanResult
import ar.com.securityapp.campo.location.Locator
import ar.com.securityapp.campo.sync.SyncWorker
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch

/** Punto de la ruta en curso con su estado: hecho, el próximo, o pendiente. */
data class ProgressItem(val n: Int, val point: PatrolPoint?, val uuid: String, val done: Boolean, val next: Boolean)

data class RoundsUi(
    val routes: List<PatrolRoute> = emptyList(),
    /** Rondas programadas por hacer (próximas 24 h) y si la empresa activó la guía. */
    val slots: List<ar.com.securityapp.campo.data.PatrolSlot> = emptyList(),
    val guidance: Boolean = true,
    val points: Map<String, PatrolPoint> = emptyMap(),
    val active: PatrolRound? = null,
    val activeRoute: PatrolRoute? = null,
    val progress: List<ProgressItem> = emptyList(),
    val freeScans: List<PatrolPoint> = emptyList(),
    val history: List<PatrolRound> = emptyList(),
    val pending: Int = 0,
    val failed: Int = 0,
    val busy: Boolean = false,
    val locationMessage: String? = null,
)

class RoundsViewModel(app: Application) : AndroidViewModel(app) {
    private val c = (app as CampoApp).container
    private val locator = Locator(app)
    private val _ui = MutableStateFlow(RoundsUi())
    val ui: StateFlow<RoundsUi> = _ui
    /** Resultado del último escaneo para mostrar (y pedir confirmación si el punto no es de la ruta). */
    private val _scan = MutableStateFlow<ScanResult?>(null)
    val scan: StateFlow<ScanResult?> = _scan
    private val _autoRoutes = MutableStateFlow<List<PatrolRoute>>(emptyList())
    val autoRoutes: StateFlow<List<PatrolRoute>> = _autoRoutes
    private var pendingRaw: String? = null
    private var pendingMethod = "qr"
    fun isPreview(): Boolean = c.api.previewToken != null

    /** Lo que se graba en la etiqueta NFC de un punto: la misma dirección que su QR. */
    fun tagUrl(point: ar.com.securityapp.campo.data.PatrolPoint): String = c.prefs.serverUrl.trimEnd('/') + "/ronda/punto/" + point.uuid

    init {
        reload()
        // Al reabrir la app con una ronda en curso, el recorrido sigue (si Android había cortado el servicio).
        _ui.value.active?.let { TrackingStarter.ensure(app, it, _ui.value.activeRoute?.name) }
        viewModelScope.launch { c.dataChanged.collect { reload() } }
    }

    fun hasLocationPermission() = locator.hasPermission()

    fun reload() {
        val r = c.rounds
        val points = r.points()
        val active = r.active()
        val route = r.route(active?.routeUuid)
        val scans = active?.let { r.scans(it.uuid) }.orEmpty()
        val done = scans.map { it.pointUuid }.toSet()
        var nextMarked = false
        // El orden de esta ronda (secuencial / al azar) o el de la ruta.
        val progress = (active?.pointOrder ?: route?.points)?.mapIndexed { i, uuid ->
            val isDone = uuid in done
            val next = !isDone && !nextMarked
            if (next) nextMarked = true
            ProgressItem(i + 1, points[uuid], uuid, isDone, next)
        }.orEmpty()
        _ui.value = RoundsUi(
            routes = r.routes(), points = points, active = active, activeRoute = route, progress = progress,
            slots = r.slots().filter { it.dueAt.isBefore(java.time.Instant.now().plusSeconds(24 * 3600)) },
            guidance = ar.com.securityapp.campo.data.RoundAutomationSettings.from(c.prefs, c.api).guidance,
            freeScans = if (route == null) scans.mapNotNull { points[it.pointUuid] } else emptyList(),
            history = r.rounds().filter { it.status != "en_curso" }.take(15),
            pending = c.outbox.pendingCount(), failed = c.outbox.failed().size,
        )
    }

    /** @param slot ronda programada que se está cumpliendo (si se inició desde "Próximas rondas") */
    fun start(route: PatrolRoute?, slot: ar.com.securityapp.campo.data.PatrolSlot? = null) = viewModelScope.launch {
        _ui.value = _ui.value.copy(busy = true)
        val fix = locator.current(timeoutMs = 6_000)
        val round = c.rounds.start(route?.uuid, fix, slot?.uuid)
        runCatching { ar.com.securityapp.campo.tracking.SlotAlarms.schedule(getApplication(), c) } // cancela el "todavía no empezaste"
        TrackingStarter.ensure(getApplication(), round, route?.name)
        SyncWorker.now(getApplication())
        reload()
    }

    /** @param method "qr" o "nfc" (queda registrado en el servidor) */
    fun onQr(raw: String, allowOutsideRoute: Boolean = false, method: String = "qr") = viewModelScope.launch {
        _ui.value = _ui.value.copy(busy = true)
        if (_ui.value.active == null && !allowOutsideRoute) {
            val pointUuid = ar.com.securityapp.campo.data.RoundsRepository.pointUuidFrom(raw)
            val point = pointUuid?.let { c.rounds.points()[it] }
            if (point == null) {
                _scan.value = ScanResult.UnknownQr
                _ui.value = _ui.value.copy(busy = false)
                return@launch
            }
            val candidates = c.rounds.routesForPoint(point.uuid)
            if (candidates.size > 1) {
                pendingRaw = raw; pendingMethod = method; _autoRoutes.value = candidates
                _ui.value = _ui.value.copy(busy = false)
                return@launch
            }
            val fix = locator.current()
            _scan.value = c.rounds.autoStartAndScan(raw, fix, candidates.singleOrNull()?.uuid, method)
            _autoRoutes.value = emptyList()
            SyncWorker.now(getApplication())
            reload()
            return@launch
        }
        val fix = locator.current()
        val result = c.rounds.scan(raw, fix, allowOutsideRoute, method)
        pendingRaw = if (result is ScanResult.NotInRoute) raw else null
        pendingMethod = method
        _scan.value = result
        if (result is ScanResult.Ok) SyncWorker.now(getApplication())
        reload()
    }

    /** El guardia confirmó registrar un punto que no es de la ruta en curso. */
    fun confirmOutsideRoute() {
        val raw = pendingRaw ?: return
        _scan.value = null
        onQr(raw, allowOutsideRoute = true, method = pendingMethod)
    }

    fun dismissScan() {
        _scan.value = null
        pendingRaw = null
    }

    fun chooseAutoRoute(route: PatrolRoute) = viewModelScope.launch {
        val raw = pendingRaw ?: return@launch
        _autoRoutes.value = emptyList(); _ui.value = _ui.value.copy(busy = true)
        val fix = locator.current()
        _scan.value = c.rounds.autoStartAndScan(raw, fix, route.uuid, pendingMethod)
        pendingRaw = null
        SyncWorker.now(getApplication())
        reload()
    }

    fun cancelAutoRouteChoice() { pendingRaw = null; _autoRoutes.value = emptyList() }

    fun finish() {
        val round = _ui.value.active ?: return
        c.rounds.finish(round)
        ar.com.securityapp.campo.tracking.RoundTrackingService.stop(getApplication())
        SyncWorker.now(getApplication())
        reload()
    }

    fun syncNow() = SyncWorker.now(getApplication())

    /** Toma la ubicación actual y encola el cambio de coordenadas del punto. */
    fun locatePoint(point: PatrolPoint) = viewModelScope.launch {
        _ui.value = _ui.value.copy(busy = true, locationMessage = null)
        val fix = locator.current(timeoutMs = 12_000)
        val problem = when {
            fix == null -> "No se pudo obtener el GPS. Activá la ubicación y probá de nuevo."
            fix.mock -> "El celular tiene una ubicación simulada (app de GPS falso). Desactivala para ubicar el punto."
            fix.accuracyM > LOCATE_MAX_ACCURACY_M -> "El GPS todavía no es preciso (±${fix.accuracyM.toInt()} m; hace falta ±$LOCATE_MAX_ACCURACY_M m o menos). " +
                "Esperá unos segundos al aire libre, sin moverte, y volvé a tocar \"Tomar GPS\"."
            else -> null
        }
        if (problem != null || fix == null) {
            _ui.value = _ui.value.copy(busy = false, locationMessage = problem)
            return@launch
        }
        c.rounds.locatePoint(point, fix)
        SyncWorker.now(getApplication())
        reload()
        _ui.value = _ui.value.copy(busy = false, locationMessage = "Ubicación de ${point.code} guardada para sincronizar (±${fix.accuracyM.toInt()} m).")
    }

    private companion object {
        /** Igual que `Patrols::LOCATE_MAX_ACCURACY_M` en el servidor. */
        const val LOCATE_MAX_ACCURACY_M = 30
    }

    /** Ubicación actual para la guía al próximo punto (null sin GPS). */
    suspend fun here(): ar.com.securityapp.campo.data.Fix? = locator.current(timeoutMs = 8_000)

    fun clearLocationMessage() { _ui.value = _ui.value.copy(locationMessage = null) }

    fun needsSms(): Boolean = ar.com.securityapp.campo.data.GuardSettings.from(c.prefs, c.api).panicPhones.isNotEmpty()

    fun trackPoints(): Int = _ui.value.active?.let { c.db.trackCount(it.uuid) } ?: 0
}

/** Arranca (o retoma) el servicio del recorrido para la ronda en curso. */
object TrackingStarter {
    fun ensure(context: android.content.Context, round: PatrolRound, routeName: String?) {
        ar.com.securityapp.campo.tracking.RoundTrackingService.start(context, round.uuid, routeName)
    }
}
