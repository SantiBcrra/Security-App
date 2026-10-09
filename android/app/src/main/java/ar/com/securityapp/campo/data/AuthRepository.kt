package ar.com.securityapp.campo.data

import android.content.Context
import android.os.Build
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put

/** Ingreso, datos del usuario (/me), consentimiento y salida. */
class AuthRepository(
    private val context: Context,
    private val api: ApiClient,
    private val prefs: Prefs,
    private val tokens: TokenStore,
    private val db: LocalDb,
) {
    val isLoggedIn: Boolean get() = tokens.refresh != null

    /** Último /me guardado: permite abrir la app sin señal. */
    fun cachedMe(): Me? = prefs.meJson?.let { runCatching { Me.from(api.json.parseToJsonElement(it).jsonObject) }.getOrNull() }

    /** @throws ApiException con code totp_required / totp_invalid / invalid_credentials / locked */
    suspend fun login(empresa: String, usuario: String, password: String, totp: String?): Me {
        val who = empresa.trim().lowercase() + "|" + usuario.trim().lowercase()
        // Otra persona u otra empresa en este celular: se empieza con datos limpios.
        if (prefs.empresa.lowercase() + "|" + prefs.usuario.lowercase() != who) {
            db.clearAll()
            prefs.clearSession()
        }
        val data = api.post("/auth/login", buildJsonObject {
            put("empresa", empresa.trim().lowercase())
            put("usuario", usuario.trim())
            put("password", password)
            put("device_uuid", prefs.deviceUuid)
            put("device_name", deviceName())
            if (!totp.isNullOrBlank()) put("totp", totp.trim())
        }, auth = false).jsonObject
        tokens.save(data.str("access_token"), data.str("refresh_token"))
        prefs.empresa = empresa.trim().lowercase()
        prefs.usuario = usuario.trim()
        return refreshMe()
    }

    suspend fun refreshMe(): Me {
        val data = api.get("/me").jsonObject
        prefs.meJson = data.toString()
        return Me.from(data)
    }

    /** @return (versión, texto) del consentimiento vigente */
    suspend fun consentText(): Pair<String, String> {
        val data = api.get("/consent").jsonObject
        return data.str("version") to data.str("texto")
    }

    suspend fun acceptConsent(version: String): Me {
        api.post("/consent", buildJsonObject { put("version", version) })
        return refreshMe()
    }

    /** Cierra la sesión en el servidor (si hay señal) y borra todo lo del celular. */
    suspend fun logout() {
        runCatching { api.post("/auth/logout") }
        tokens.clear()
        db.clearAll()
        prefs.clearSession()
    }

    /** Sesión terminada por el servidor: se conservan los datos y la cola para cuando vuelva a ingresar. */
    fun forgetTokens() = tokens.clear()

    private fun deviceName(): String = ("${Build.MANUFACTURER} ${Build.MODEL}".trim() + " · app Android").take(110)
}
