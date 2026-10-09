package ar.com.securityapp.campo.data

import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonArray
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put
import java.util.UUID

/**
 * Cola de envío: todo lo que se hace en el celular (iniciar ronda, escanear, finalizar…) se guarda primero acá y se
 * manda cuando hay señal. Cada operación tiene su op_id: reenviarla nunca duplica (el servidor recuerda la respuesta).
 * Un rechazo del servidor (validación) no se reintenta solo: queda visible en Ajustes con el motivo.
 */
class Outbox(private val api: ApiClient, private val db: LocalDb) {

    data class PushResult(val sent: Int, val failed: Int)

    fun enqueue(type: String, data: JsonObject): String {
        val opId = UUID.randomUUID().toString()
        db.addOp(LocalDb.Op(opId, type, data.toString(), "pending", 0, null, System.currentTimeMillis()))
        return opId
    }

    fun pendingCount(): Int = db.pendingOps()

    fun failed(): List<LocalDb.Op> = db.ops("failed")

    /** Reintento manual con otro op_id (el servidor guarda la respuesta de cada op_id, también los rechazos). */
    fun retry(op: LocalDb.Op) {
        db.deleteOp(op.opId)
        db.addOp(op.copy(opId = UUID.randomUUID().toString(), status = "pending", error = null, attempts = 0))
    }

    fun discard(op: LocalDb.Op) = db.deleteOp(op.opId)

    /** @throws OfflineException si se corta la conexión (lo que no salió sigue en la cola) */
    suspend fun push(onResult: (LocalDb.Op, JsonObject) -> Unit = { _, _ -> }): PushResult {
        var sent = 0
        var failed = 0
        for (batch in db.ops("pending").chunked(BATCH)) {
            batch.forEach { db.touchOp(it.opId) }
            val body = buildJsonObject {
                put("operations", buildJsonArray {
                    for (op in batch) add(buildJsonObject {
                        put("op_id", op.opId)
                        put("type", op.type)
                        put("data", api.json.parseToJsonElement(op.data))
                    })
                })
            }
            val results = (api.post("/sync/push", body).jsonObject["results"] as? JsonArray).orEmpty()
            for (r in results) {
                val res = r as? JsonObject ?: continue
                val op = batch.firstOrNull { it.opId == res.str("op_id") } ?: continue
                if (res.str("status") == "ok") {
                    db.deleteOp(op.opId)
                    sent++
                } else {
                    db.failOp(op.opId, res.strOrNull("error") ?: "Rechazado por el servidor.")
                    failed++
                }
                onResult(op, res)
            }
        }
        return PushResult(sent, failed)
    }

    private companion object {
        const val BATCH = 20
    }
}
