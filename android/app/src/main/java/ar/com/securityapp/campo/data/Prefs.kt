package ar.com.securityapp.campo.data

import android.content.Context
import ar.com.securityapp.campo.BuildConfig
import java.util.UUID

/** Ajustes simples del dispositivo (nada secreto: los tokens van cifrados en TokenStore). */
class Prefs(context: Context) {
    private val sp = context.getSharedPreferences("campo", Context.MODE_PRIVATE)

    /** Identificador propio de este celular (lo usa el servidor para la sesión por dispositivo). */
    val deviceUuid: String
        get() = sp.getString("device_uuid", null) ?: UUID.randomUUID().toString().also { sp.edit().putString("device_uuid", it).apply() }

    /** Servidor: el de la compilación; en desarrollo se puede cambiar (p. ej. la IP de la Mac para un celular real). */
    var serverUrl: String
        get() = if (BuildConfig.DEBUG) sp.getString("server_url", null) ?: BuildConfig.SERVER_URL else BuildConfig.SERVER_URL
        set(value) { sp.edit().putString("server_url", value.trim().trimEnd('/')).apply() }

    var empresa: String
        get() = sp.getString("empresa", "") ?: ""
        set(value) { sp.edit().putString("empresa", value).apply() }

    var usuario: String
        get() = sp.getString("usuario", "") ?: ""
        set(value) { sp.edit().putString("usuario", value).apply() }

    /** Respuesta de /me guardada para poder abrir la app sin señal. */
    var meJson: String?
        get() = sp.getString("me", null)
        set(value) { sp.edit().putString("me", value).apply() }

    /** `meta` del último pull (ajustes de la empresa: teléfonos de pánico, intervalos de la ronda). */
    var metaJson: String?
        get() = sp.getString("meta", null)
        set(value) { sp.edit().putString("meta", value).apply() }

    /** Último predio elegido en el libro de guardia. */
    var logbookSite: String?
        get() = sp.getString("logbook_site", null)
        set(value) { sp.edit().putString("logbook_site", value).apply() }

    var cursor: String?
        get() = sp.getString("cursor", null)
        set(value) { sp.edit().putString("cursor", value).apply() }

    var lastSync: Long
        get() = sp.getLong("last_sync", 0L)
        set(value) { sp.edit().putLong("last_sync", value).apply() }

    /** Hay una vista previa ("Ver como") en curso: si la app se cierra en el medio, al abrirla se descarta todo lo bajado. */
    var previewActive: Boolean
        get() = sp.getBoolean("preview_active", false)
        set(value) { sp.edit().putBoolean("preview_active", value).apply() }

    /** Tema de la app: "system" (sigue al celular), "light" u "dark". No se borra al cerrar sesión. */
    var themeMode: String
        get() = sp.getString("theme_mode", "system") ?: "system"
        set(value) { sp.edit().putString("theme_mode", value).apply() }

    /** Borra lo de la sesión (al salir o al cambiar de usuario). Conserva el dispositivo, el servidor y la empresa. */
    fun clearSession() {
        sp.edit().remove("me").remove("cursor").remove("last_sync").remove("meta").apply()
    }
}
