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
    val sync = SyncRepository(api, prefs, db)
    val updates = UpdateManager(appContext, api)
}
