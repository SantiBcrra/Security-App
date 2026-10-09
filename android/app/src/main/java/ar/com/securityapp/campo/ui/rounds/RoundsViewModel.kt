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
    val points: Map<String, PatrolPoint> = emptyMap(),
    val active: PatrolRound? = null,
    val activeRoute: PatrolRoute? = null,
    val progress: List<ProgressItem> = emptyList(),
    val freeScans: List<PatrolPoint> = emptyList(),
    val history: List<PatrolRound> = emptyList(),
    val pending: Int = 0,
    val failed: Int = 0,
    val busy: Boolean = false,
)

class RoundsViewModel(app: Application) : AndroidViewModel(app) {
    private val c = (app as CampoApp).container
    private val locator = Locator(app)
    private val _ui = MutableStateFlow(RoundsUi())
    val ui: StateFlow<RoundsUi> = _ui
    /** Resultado del último escaneo para mostrar (y pedir confirmación si el punto no es de la ruta). */
    private val _scan = MutableStateFlow<ScanResult?>(null)
    val scan: StateFlow<ScanResult?> = _scan
    private var pendingRaw: String? = null

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
        val progress = route?.points?.mapIndexed { i, uuid ->
            val isDone = uuid in done
            val next = !isDone && !nextMarked
            if (next) nextMarked = true
            ProgressItem(i + 1, points[uuid], uuid, isDone, next)
        }.orEmpty()
        _ui.value = RoundsUi(
            routes = r.routes(), points = points, active = active, activeRoute = route, progress = progress,
            freeScans = if (route == null) scans.mapNotNull { points[it.pointUuid] } else emptyList(),
            history = r.rounds().filter { it.status != "en_curso" }.take(15),
            pending = c.outbox.pendingCount(), failed = c.outbox.failed().size,
        )
    }

    fun start(route: PatrolRoute?) = viewModelScope.launch {
        _ui.value = _ui.value.copy(busy = true)
        val fix = locator.current(timeoutMs = 6_000)
        val round = c.rounds.start(route?.uuid, fix)
        TrackingStarter.ensure(getApplication(), round, route?.name)
        SyncWorker.now(getApplication())
        reload()
    }

    fun onQr(raw: String, allowOutsideRoute: Boolean = false) = viewModelScope.launch {
        _ui.value = _ui.value.copy(busy = true)
        val fix = locator.current()
        val result = c.rounds.scan(raw, fix, allowOutsideRoute)
        pendingRaw = if (result is ScanResult.NotInRoute) raw else null
        _scan.value = result
        if (result is ScanResult.Ok) SyncWorker.now(getApplication())
        reload()
    }

    /** El guardia confirmó registrar un punto que no es de la ruta en curso. */
    fun confirmOutsideRoute() {
        val raw = pendingRaw ?: return
        _scan.value = null
        onQr(raw, allowOutsideRoute = true)
    }

    fun dismissScan() {
        _scan.value = null
        pendingRaw = null
    }

    fun finish() {
        val round = _ui.value.active ?: return
        c.rounds.finish(round)
        ar.com.securityapp.campo.tracking.RoundTrackingService.stop(getApplication())
        SyncWorker.now(getApplication())
        reload()
    }

    fun syncNow() = SyncWorker.now(getApplication())

    fun needsSms(): Boolean = ar.com.securityapp.campo.data.GuardSettings.from(c.prefs, c.api).panicPhones.isNotEmpty()

    fun trackPoints(): Int = _ui.value.active?.let { c.db.trackCount(it.uuid) } ?: 0
}

/** Arranca (o retoma) el servicio del recorrido para la ronda en curso. */
object TrackingStarter {
    fun ensure(context: android.content.Context, round: PatrolRound, routeName: String?) {
        ar.com.securityapp.campo.tracking.RoundTrackingService.start(context, round.uuid, routeName)
    }
}
