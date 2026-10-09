package ar.com.securityapp.campo

import android.app.Application
import ar.com.securityapp.campo.data.AppContainer

/** Punto de entrada de la app: arma las dependencias una sola vez. */
class CampoApp : Application() {
    lateinit var container: AppContainer
        private set

    override fun onCreate() {
        super.onCreate()
        container = AppContainer(this)
        ar.com.securityapp.campo.push.Notifs.ensureChannels(this)
        ar.com.securityapp.campo.push.PushRegistrar.initFromCache(this)
    }
}
