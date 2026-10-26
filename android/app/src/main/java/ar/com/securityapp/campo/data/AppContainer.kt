package ar.com.securityapp.campo.data

import android.content.Context
import kotlinx.coroutines.flow.MutableSharedFlow

/** Dependencias compartidas (sin librerías de inyección: la app es chica). */
class AppContainer(context: Context) {
    val appContext: Context = context.applicationContext
    val prefs = Prefs(appContext)
    val tokens = TokenStore(appContext)
    val db = LocalDb(appContext)
    /** Se emite cuando el servidor da la sesión por terminada (refresh vencido o revocado). */
    val sessionLost = MutableSharedFlow<Unit>(extraBufferCapacity = 1)
    val api = ApiClient(prefs, tokens) { sessionLost.tryEmit(Unit) }
    val auth = AuthRepository(appContext, api, prefs, tokens, db)
    val outbox = Outbox(api, db) { prefs.dutyGuardUuid }
    val uploads = Uploads(appContext, api, db, tokens)
    val observations = ObservationsRepository(db, outbox, uploads, api)
    val notifications = NotificationsRepository(appContext, api)
    val actions = ActionsRepository(db, outbox, uploads, api)
    val inspections = InspectionsRepository(db, outbox, uploads, api)
    val incidents = IncidentsRepository(db, outbox, uploads, api)
    val sync = SyncRepository(appContext, api, prefs, db, outbox, uploads, inspections)
    val rounds = RoundsRepository(db, outbox, api)
    val shifts = ShiftsRepository(db, outbox, api, prefs)
    val logbook = LogbookRepository(db, outbox, uploads, api, prefs)
    val post = PostRepository(db, outbox, api, prefs, shifts)
    /** Celular compartido del puesto: nombre del guardia de turno (lo muestra el encabezado). */
    val dutyName = kotlinx.coroutines.flow.MutableStateFlow(prefs.dutyGuardName)
    /** Se emite después de cada sincronización (la UI vuelve a leer la base local). */
    val dataChanged = MutableSharedFlow<Unit>(extraBufferCapacity = 4)
    val updates = UpdateManager(appContext, api)

    /**
     * Fin de una vista previa ("Ver como"): se borra lo bajado como el otro usuario (y lo que se simuló, que nunca se
     * envió) para que la próxima sincronización baje de nuevo lo propio. Para entrar se exige no tener nada pendiente.
     */
    fun discardPreviewData() {
        api.previewToken = null
        db.clearAll()
        prefs.cursor = null
        prefs.lastSync = 0L
        prefs.metaJson = null
        prefs.previewActive = false
    }
}
