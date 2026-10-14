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

/** Automatizaciones de rondas (Configuración → Notificaciones, `meta.automatizaciones_rondas` del pull). */
data class RoundAutomationSettings(val active: Boolean, val noProgressMinutes: Int, val lateMinutes: Int, val batteryPercent: Int, val syncMinutes: Int) {
    companion object {
        fun from(prefs: Prefs, api: ApiClient): RoundAutomationSettings {
            val a = prefs.metaJson?.let { runCatching { api.json.parseToJsonElement(it).jsonObject.obj("automatizaciones_rondas") }.getOrNull() }
            return RoundAutomationSettings(
                active = a?.let { it["activas"] == null || it.bool("activas") } ?: true,
                noProgressMinutes = a?.int("sin_progreso_minutos")?.takeIf { it > 0 } ?: 10,
                lateMinutes = a?.int("atraso_minutos")?.takeIf { it > 0 } ?: 10,
                batteryPercent = a?.int("bateria_baja_porcentaje")?.takeIf { it > 0 } ?: 20,
                syncMinutes = a?.int("sin_sync_minutos")?.takeIf { it > 0 } ?: 15,
            )
        }
    }
}
