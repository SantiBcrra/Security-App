package ar.com.securityapp.campo.push

import ar.com.securityapp.campo.CampoApp
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

/** Llega un aviso de Firebase (mensaje "data" de alta prioridad): se muestra en el canal que corresponda. */
class CampoMessagingService : FirebaseMessagingService() {
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    override fun onMessageReceived(message: RemoteMessage) {
        val d = message.data
        val critical = d["critical"] == "1"
        Notifs.show(this, d["entity"].orEmpty() + d["event"].orEmpty() + message.messageId.orEmpty(), d["title"] ?: "Security App", d["body"].orEmpty(), critical)
        (application as CampoApp).container.dataChanged.tryEmit(Unit)
    }

    override fun onNewToken(token: String) {
        val c = (application as CampoApp).container
        if (c.auth.isLoggedIn) scope.launch { PushRegistrar.sendToken(c, token) }
    }
}
