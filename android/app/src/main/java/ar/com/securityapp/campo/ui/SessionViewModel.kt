package ar.com.securityapp.campo.ui

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import ar.com.securityapp.campo.BuildConfig
import ar.com.securityapp.campo.CampoApp
import ar.com.securityapp.campo.data.ApiException
import ar.com.securityapp.campo.data.Me
import ar.com.securityapp.campo.data.OfflineException
import ar.com.securityapp.campo.data.UpdateManager
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.launch
import ar.com.securityapp.campo.data.obj
import ar.com.securityapp.campo.data.str
import kotlinx.serialization.json.jsonObject

/** Qué pantalla se ve: ingreso → consentimiento → inicio. Una versión obligatoria bloquea todo hasta actualizar. */
sealed interface Screen {
    data object Loading : Screen
    data class Login(val needTotp: Boolean = false, val error: String? = null, val busy: Boolean = false) : Screen
    data class Consent(val version: String, val text: String, val busy: Boolean = false, val error: String? = null) : Screen
    data class Home(val me: Me) : Screen
    data class UpdateRequired(val release: UpdateManager.Release) : Screen
}

data class SyncState(val running: Boolean = false, val lastSync: Long = 0L, val message: String? = null, val error: Boolean = false,
                     val counts: Map<String, Int> = emptyMap(), val pending: Int = 0)

data class UpdateState(val release: UpdateManager.Release? = null, val downloading: Boolean = false, val progress: Float = 0f, val error: String? = null)

class SessionViewModel(app: Application) : AndroidViewModel(app) {
    private val c = (app as CampoApp).container
    private val _screen = MutableStateFlow<Screen>(Screen.Loading)
    val screen: StateFlow<Screen> = _screen
    private val _sync = MutableStateFlow(SyncState(lastSync = c.prefs.lastSync))
    val sync: StateFlow<SyncState> = _sync
    private val _update = MutableStateFlow(UpdateState())
    val update: StateFlow<UpdateState> = _update
    val prefs get() = c.prefs
    private val _unread = MutableStateFlow(0)
    val unread: StateFlow<Int> = _unread
    /** Se abrió la app tocando una notificación: mostrar Avisos. */
    val openAvisos = MutableStateFlow(false)

    init {
        viewModelScope.launch { c.sessionLost.collect { _screen.value = Screen.Login(error = "Tu sesión venció o fue cerrada. Ingresá de nuevo.") } }
        viewModelScope.launch { c.dataChanged.collect { refreshCounts() } }
        c.api.onPreviewLost = { viewModelScope.launch { exitPreview("La vista previa terminó (dura 30 minutos). Podés volver a entrar desde Ajustes.") } }
        start()
    }

    private fun start() = viewModelScope.launch {
        if (c.prefs.previewActive) c.discardPreviewData()
        checkUpdate()
        if (_screen.value is Screen.UpdateRequired) return@launch
        if (!c.auth.isLoggedIn) {
            _screen.value = Screen.Login()
            return@launch
        }
        val me = try {
            c.auth.refreshMe()
        } catch (e: OfflineException) {
            c.auth.cachedMe()
        } catch (e: ApiException) {
            null
        }
        if (me == null) _screen.value = Screen.Login() else afterLogin(me)
    }

    fun login(empresa: String, usuario: String, password: String, totp: String?) = viewModelScope.launch {
        val current = _screen.value as? Screen.Login ?: Screen.Login()
        if (empresa.isBlank() || usuario.isBlank() || password.isBlank()) {
            _screen.value = current.copy(error = "Completá empresa, usuario y contraseña.")
            return@launch
        }
        _screen.value = current.copy(busy = true, error = null)
        try {
            afterLogin(c.auth.login(empresa, usuario, password, totp))
        } catch (e: ApiException) {
            val needTotp = e.code == "totp_required" || e.code == "totp_invalid" || current.needTotp
            _screen.value = Screen.Login(needTotp = needTotp, error = if (e.code == "totp_required") null else e.message)
        } catch (e: OfflineException) {
            _screen.value = current.copy(busy = false, error = "No hay conexión con el servidor. Para ingresar la primera vez necesitás señal.")
        }
    }

    private suspend fun afterLogin(me: Me) {
        if (!me.consentAccepted) {
            try {
                val (version, text) = c.auth.consentText()
                _screen.value = Screen.Consent(version, text)
            } catch (e: Exception) {
                _screen.value = Screen.Login(error = "No se pudo cargar el consentimiento: ${e.message}")
            }
            return
        }
        _screen.value = Screen.Home(me)
        ar.com.securityapp.campo.sync.SyncWorker.schedulePeriodic(getApplication())
        syncNow()
        viewModelScope.launch { runCatching { ar.com.securityapp.campo.push.PushRegistrar.register(getApplication(), c) } }
    }

    /** Sin Firebase, los avisos nuevos también suenan con la app abierta (pánico, riesgo inminente). */
    fun refreshUnread() = viewModelScope.launch {
        runCatching { _unread.value = c.notifications.list().second }
        runCatching { c.notifications.notifyNew() }
    }

    fun acceptConsent() = viewModelScope.launch {
        val s = _screen.value as? Screen.Consent ?: return@launch
        _screen.value = s.copy(busy = true, error = null)
        try {
            afterLogin(c.auth.acceptConsent(s.version))
        } catch (e: Exception) {
            _screen.value = s.copy(busy = false, error = e.message ?: "No se pudo guardar. Probá de nuevo con señal.")
        }
    }

    fun syncNow() = viewModelScope.launch {
        if (_sync.value.running) return@launch
        _sync.value = _sync.value.copy(running = true, message = null, error = false)
        val hadPending = c.outbox.pendingCount() // sin el estado del celular, que sale en cada sincronización
        _sync.value = try {
            val full = c.sync.syncAll()
            val r = full.pulled
            (_screen.value as? Screen.Home)?.let { runCatching { _screen.value = Screen.Home(c.auth.refreshMe()) } }
            // Mensajes simples para quien usa la app (el detalle técnico está en Ajustes, solo para administradores).
            val parts = listOfNotNull(
                if (hadPending > 0 && full.failed == 0 && c.outbox.pendingCount() == 0) "Lo que tenías pendiente ya se envió." else null,
                if (full.failed > 0) "${full.failed} envío(s) rechazado(s): ver Ajustes." else null,
            )
            c.dataChanged.tryEmit(Unit)
            refreshUnread()
            runCatching { ar.com.securityapp.campo.tracking.SlotAlarms.schedule(getApplication(), c) }
            SyncState(lastSync = c.prefs.lastSync, message = parts.joinToString(" ").ifEmpty { "Todo al día." }, error = full.failed > 0,
                counts = counts(), pending = c.outbox.pendingCount())
        } catch (e: OfflineException) {
            SyncState(lastSync = c.prefs.lastSync, message = "Sin señal: se usa lo guardado en el celular y se envía cuando vuelva la conexión.", error = true,
                counts = counts(), pending = c.outbox.pendingCount())
        } catch (e: ApiException) {
            SyncState(lastSync = c.prefs.lastSync, message = e.message, error = true, counts = counts(), pending = c.outbox.pendingCount())
        }
    }

    // ── "Ver como" (vista previa de un administrador) ──────────────
    data class PreviewUser(val uuid: String, val name: String, val role: String)
    private val _preview = MutableStateFlow<PreviewState>(PreviewState())
    val preview: StateFlow<PreviewState> = _preview
    data class PreviewState(val busy: Boolean = false, val error: String? = null, val notice: String? = null, val userUuid: String? = null)

    fun canPreview(me: Me) = !me.isPreview && me.can("usuarios", "editar")

    suspend fun previewUsers(): List<PreviewUser> =
        (c.api.get("/preview/users").jsonObject["usuarios"] as? kotlinx.serialization.json.JsonArray).orEmpty().mapNotNull { it as? kotlinx.serialization.json.JsonObject }
            .map { PreviewUser(it.str("uuid"), it.str("nombre"), it.obj("rol")?.str("nombre") ?: "") }

    /** Para entrar no puede haber nada sin enviar: al salir se borra lo bajado y se vuelve a bajar lo propio. */
    fun previewBlocker(): String? = when {
        c.rounds.active() != null -> "Tenés una ronda en curso. Finalizala antes de ver la app como otro usuario."
        c.outbox.pendingCount() > 0 || c.outbox.failed().isNotEmpty() || c.db.uploads("pending").isNotEmpty() ->
            "Tenés registros sin enviar. Sincronizá (o revisá los rechazados) antes de ver la app como otro usuario."
        else -> null
    }

    fun enterPreview(user: PreviewUser) = viewModelScope.launch {
        previewBlocker()?.let { _preview.value = PreviewState(error = it); return@launch }
        _preview.value = PreviewState(busy = true)
        try {
            val data = c.api.post("/preview/start", kotlinx.serialization.json.buildJsonObject { put("user_uuid", kotlinx.serialization.json.JsonPrimitive(user.uuid)) }).jsonObject
            androidx.work.WorkManager.getInstance(getApplication()).cancelAllWork()
            c.prefs.previewActive = true
            c.discardPreviewData()
            c.prefs.previewActive = true
            c.api.previewToken = data.str("access_token")
            _preview.value = PreviewState(userUuid = user.uuid)
            _sync.value = SyncState()
            _screen.value = Screen.Home(c.auth.refreshMe())
            syncNow()
        } catch (e: Exception) {
            c.api.previewToken = null
            if (c.prefs.previewActive) { c.discardPreviewData(); start() }
            _preview.value = PreviewState(error = if (e is OfflineException) "Hace falta señal para ver la app como otro usuario." else e.message)
        }
    }

    fun exitPreview(notice: String? = null) = viewModelScope.launch {
        if (c.api.previewToken == null && !c.prefs.previewActive) return@launch
        val who = _preview.value.userUuid
        ar.com.securityapp.campo.tracking.RoundTrackingService.stop(getApplication())
        androidx.work.WorkManager.getInstance(getApplication()).cancelAllWork()
        c.discardPreviewData()
        runCatching { ar.com.securityapp.campo.tracking.SlotAlarms.schedule(getApplication(), c) } // sin datos: cancela los recordatorios del otro
        runCatching { c.api.post("/preview/stop", kotlinx.serialization.json.buildJsonObject { who?.let { put("user_uuid", kotlinx.serialization.json.JsonPrimitive(it)) } }) }
        _preview.value = PreviewState(notice = notice)
        _sync.value = SyncState()
        _screen.value = Screen.Loading
        start()
    }

    fun clearPreviewNotice() { _preview.value = _preview.value.copy(error = null, notice = null) }

    fun refreshCounts() {
        _sync.value = _sync.value.copy(counts = counts(), pending = c.outbox.pendingCount(), lastSync = c.prefs.lastSync)
    }

    fun failedOps() = c.outbox.failed()

    fun retryOp(op: ar.com.securityapp.campo.data.LocalDb.Op) {
        c.outbox.retry(op)
        ar.com.securityapp.campo.sync.SyncWorker.now(getApplication())
    }

    fun discardOp(op: ar.com.securityapp.campo.data.LocalDb.Op) = c.outbox.discard(op)

    private fun counts(): Map<String, Int> = listOf("sectors", "equipment", "employees", "patrol_routes", "patrol_points", "actions", "inspection_schedule")
        .associateWith { c.db.count(it) }

    // ── pánico ─────────────────────────────────────────────────────
    /** null = no hay pánico en curso; Pending = enviando; Done = resultado. */
    sealed interface PanicState { data object Pending : PanicState; data class Done(val result: ar.com.securityapp.campo.panic.PanicManager.Result) : PanicState }
    private val _panic = MutableStateFlow<PanicState?>(null)
    val panic: StateFlow<PanicState?> = _panic
    private val panicManager by lazy { ar.com.securityapp.campo.panic.PanicManager(getApplication(), c) }

    fun triggerPanic() {
        if (_panic.value is PanicState.Pending) return
        _panic.value = PanicState.Pending
        viewModelScope.launch { _panic.value = PanicState.Done(panicManager.trigger()); refreshCounts() }
    }

    fun closePanic() { _panic.value = null }

    /** Hay envíos sin confirmar: al salir se perderían. */
    fun pendingCount(): Int = c.outbox.pendingCount()

    fun logout() = viewModelScope.launch {
        c.auth.logout()
        _sync.value = SyncState()
        _screen.value = Screen.Login()
    }

    fun setServer(url: String) {
        if (BuildConfig.DEBUG && url.isNotBlank()) c.prefs.serverUrl = url
    }

    // ── actualizaciones ────────────────────────────────────────────

    private suspend fun checkUpdate() {
        val r = c.updates.check() ?: return
        _update.value = UpdateState(release = r.takeIf { it.isNewer })
        // En desarrollo (paquete .debug) no se fuerza: la versión publicada es otra app.
        if (r.isRequired && !BuildConfig.DEBUG) _screen.value = Screen.UpdateRequired(r)
    }

    fun refreshUpdate() = viewModelScope.launch { checkUpdate() }

    /** @return true si ya se abrió el instalador; false si antes hay que dar el permiso de instalar. */
    fun installUpdate(openIntent: (android.content.Intent) -> Unit) = viewModelScope.launch {
        val release = _update.value.release ?: (_screen.value as? Screen.UpdateRequired)?.release ?: return@launch
        if (!c.updates.canInstall()) {
            _update.value = _update.value.copy(release = release, error = "Primero permití que Security App instale actualizaciones y volvé a tocar \"Actualizar\".")
            openIntent(c.updates.installPermissionIntent())
            return@launch
        }
        _update.value = _update.value.copy(release = release, downloading = true, progress = 0f, error = null)
        try {
            val file = c.updates.download(release) { p -> _update.value = _update.value.copy(progress = p) }
            _update.value = _update.value.copy(downloading = false)
            openIntent(c.updates.installIntent(file))
        } catch (e: Exception) {
            _update.value = _update.value.copy(downloading = false, error = e.message)
        }
    }
}
