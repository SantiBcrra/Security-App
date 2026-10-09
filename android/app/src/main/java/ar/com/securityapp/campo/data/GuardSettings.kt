package ar.com.securityapp.campo.data

import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.jsonObject

/** Ajustes de guardias que manda el servidor en el pull (Configuración → Guardias). Con valores por defecto si nunca sincronizó. */
data class GuardSettings(val panicPhones: List<String>, val trackSeconds: Int, val silentMinutes: Int) {
    companion object {
        fun from(prefs: Prefs, api: ApiClient): GuardSettings {
            val g = prefs.metaJson?.let { runCatching { api.json.parseToJsonElement(it).jsonObject.obj("guardias") }.getOrNull() }
            return GuardSettings(
                panicPhones = g?.arr("panico_telefonos")?.mapNotNull { (it as? JsonPrimitive)?.content }.orEmpty(),
                trackSeconds = g?.int("track_segundos")?.takeIf { it > 0 } ?: 60,
                silentMinutes = g?.int("minutos_sin_senal")?.takeIf { it > 0 } ?: 10,
            )
        }
    }
}
