package ar.com.securityapp.campo.push

import android.content.Context
import ar.com.securityapp.campo.data.AppContainer
import ar.com.securityapp.campo.data.obj
import ar.com.securityapp.campo.data.str
import com.google.firebase.FirebaseApp
import com.google.firebase.FirebaseOptions
import com.google.firebase.messaging.FirebaseMessaging
import kotlinx.coroutines.tasks.await
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put

/**
 * Conexión con Firebase sin google-services.json dentro de la app: los datos (públicos) del proyecto los configura el
 * super-admin en el servidor y bajan acá. Si la plataforma no configuró Firebase, la app usa los avisos por sincronización.
 */
object PushRegistrar {
    private const val PREFS = "push"

    /** Al abrir la app: si ya hay configuración guardada, Firebase queda listo para recibir aunque la app esté cerrada. */
    fun initFromCache(context: Context) {
        val sp = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val app = sp.getString("app_id", null) ?: return
        init(context, app, sp.getString("api_key", "")!!, sp.getString("project", "")!!, sp.getString("sender", "")!!)
    }

    fun enabled(context: Context): Boolean = FirebaseApp.getApps(context).isNotEmpty()

    /** Pide la configuración al servidor, inicializa Firebase y registra el token de este celular. */
    suspend fun register(context: Context, c: AppContainer): Boolean {
        val cfg = runCatching { c.api.get("/push/fcm-config", mapOf("package" to context.packageName)).jsonObject.obj("fcm") }.getOrNull()
        val sp = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        if (cfg == null) return enabled(context)
        val appId = cfg.str("application_id")
        if (appId.isEmpty()) return false
        sp.edit().putString("app_id", appId).putString("api_key", cfg.str("api_key")).putString("project", cfg.str("project_id"))
            .putString("sender", cfg.str("sender_id")).apply()
        init(context, appId, cfg.str("api_key"), cfg.str("project_id"), cfg.str("sender_id"))
        val token = runCatching { FirebaseMessaging.getInstance().token.await() }.getOrNull() ?: return false
        return sendToken(c, token)
    }

    suspend fun sendToken(c: AppContainer, token: String): Boolean =
        runCatching { c.api.post("/push/fcm-token", buildJsonObject { put("token", token) }) }.isSuccess

    private fun init(context: Context, appId: String, apiKey: String, project: String, sender: String) {
        if (FirebaseApp.getApps(context).isNotEmpty()) return
        runCatching {
            FirebaseApp.initializeApp(context, FirebaseOptions.Builder().setApplicationId(appId).setApiKey(apiKey).setProjectId(project)
                .setGcmSenderId(sender).build())
        }
    }
}
