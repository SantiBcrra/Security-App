package ar.com.securityapp.campo.data

import android.content.Context
import ar.com.securityapp.campo.push.Notifs
import ar.com.securityapp.campo.push.PushRegistrar
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put

data class AppNotification(val uuid: String, val title: String, val body: String, val critical: Boolean, val read: Boolean, val at: String,
                           val alertUuid: String?, val alertAcked: Boolean, val alertAckedBy: String?, val panicUuid: String?)

/** Avisos del usuario (los mismos de la campanita de la web). Sin Firebase, se muestran como notificación al sincronizar. */
class NotificationsRepository(private val context: Context, private val api: ApiClient) {
    private val sp = context.getSharedPreferences("avisos", Context.MODE_PRIVATE)

    suspend fun list(): Pair<List<AppNotification>, Int> {
        val data = api.get("/notifications").jsonObject
        val items = data.arr("items").mapNotNull { it as? JsonObject }.map { n ->
            AppNotification(n.str("uuid"), n.str("title"), n.str("body"), n.bool("critical"), n.bool("read"), n.str("at"), n.strOrNull("alert_uuid"),
                n.bool("alert_acked"), n.strOrNull("alert_acked_by"), n.strOrNull("panic_uuid"))
        }
        return items to data.int("unread")
    }

    suspend fun markAllRead() { api.post("/notifications/read") }

    /** "Recibido" de una alerta de riesgo inminente (corta el escalamiento). */
    suspend fun ackAlert(uuid: String) { api.post("/alerts/$uuid/ack") }

    /** "Atendido" de un pánico (supervisores / SyH). */
    suspend fun ackPanic(uuid: String, comment: String) { api.post("/panics/$uuid/ack", buildJsonObject { put("comment", comment) }) }

    /** Respaldo sin Firebase (o Huawei sin Google): avisos nuevos sin leer → notificación del celular, una sola vez cada uno. */
    suspend fun notifyNew() {
        if (PushRegistrar.enabled(context)) return
        val (items, _) = runCatching { list() }.getOrNull() ?: return
        val shown = sp.getStringSet("shown", emptySet())!!.toMutableSet()
        val fresh = items.filter { !it.read && it.uuid !in shown }
        if (shown.isEmpty() && fresh.size > 3) { // primera vez: no inundar con avisos viejos
            sp.edit().putStringSet("shown", items.map { it.uuid }.toSet()).apply()
            return
        }
        for (n in fresh.take(5)) Notifs.show(context, n.uuid, n.title, n.body, n.critical)
        sp.edit().putStringSet("shown", (shown + items.map { it.uuid }).toList().takeLast(300).toSet()).apply()
    }
}
