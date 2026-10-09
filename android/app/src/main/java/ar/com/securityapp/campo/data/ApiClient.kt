package ar.com.securityapp.campo.data

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException
import java.util.concurrent.TimeUnit

/** Error con mensaje del servidor (validación, permisos, sesión). `code` es el código de la API (p. ej. totp_required). */
class ApiException(val status: Int, val code: String?, message: String) : Exception(message)

/** Sin conexión o el servidor no respondió: lo hecho queda en el celular y se reintenta. */
class OfflineException(message: String = "Sin conexión con el servidor.") : Exception(message)

/**
 * Cliente de la API v1 (`{ ok, data, error }`). Renueva el access token solo (una vez por vez, con un mutex: dos
 * renovaciones simultáneas con el mismo refresh harían que el servidor revoque el dispositivo).
 */
class ApiClient(private val prefs: Prefs, private val tokens: TokenStore, private val onSessionLost: () -> Unit) {
    val json = Json { ignoreUnknownKeys = true; explicitNulls = false }
    val http: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(45, TimeUnit.SECONDS)
        .writeTimeout(45, TimeUnit.SECONDS)
        .build()
    private val refreshLock = Mutex()

    fun apiUrl(path: String): String = prefs.serverUrl.trimEnd('/') + "/api/v1" + path

    suspend fun get(path: String, query: Map<String, String> = emptyMap(), auth: Boolean = true): JsonElement =
        call("GET", path, query, null, auth)

    suspend fun post(path: String, body: JsonObject? = null, auth: Boolean = true): JsonElement =
        call("POST", path, emptyMap(), body, auth)

    private suspend fun call(method: String, path: String, query: Map<String, String>, body: JsonObject?, auth: Boolean, retried: Boolean = false): JsonElement {
        val usedAccess = tokens.access
        val (status, payload) = withContext(Dispatchers.IO) {
            val url = apiUrl(path).toHttpUrl().newBuilder().apply { query.forEach { (k, v) -> addQueryParameter(k, v) } }.build()
            val builder = Request.Builder().url(url).header("Accept", "application/json")
            if (auth && usedAccess != null) builder.header("Authorization", "Bearer $usedAccess")
            val rb = body?.toString()?.toRequestBody(JSON_TYPE)
            builder.method(method, if (method == "GET") null else (rb ?: "{}".toRequestBody(JSON_TYPE)))
            try {
                http.newCall(builder.build()).execute().use { res -> res.code to (res.body?.string() ?: "") }
            } catch (e: IOException) {
                android.util.Log.w("SecurityApp", "Sin conexión con $url", e)
                throw OfflineException()
            }
        }
        val parsed = runCatching { json.parseToJsonElement(payload).jsonObject }.getOrNull()
            ?: throw ApiException(status, null, "Respuesta inesperada del servidor ($status).")
        if (parsed["ok"]?.toString() == "true") return parsed["data"] ?: JsonNull
        val error = (parsed["error"] as? JsonObject)
        val code = error?.get("code")?.let { if (it is JsonNull) null else it.toString().trim('"') }
        val message = error?.get("message")?.toString()?.trim('"') ?: "Error del servidor ($status)."
        if (status == 401 && auth && !retried && code != "totp_required" && code != "totp_invalid") {
            if (refreshTokens(usedAccess)) return call(method, path, query, body, auth, retried = true)
            tokens.clear()
            onSessionLost()
        }
        throw ApiException(status, code, message)
    }

    /** @return true si hay un access token nuevo (renovado ahora o por otra llamada mientras esperaba). */
    private suspend fun refreshTokens(usedAccess: String?): Boolean = refreshLock.withLock {
        if (tokens.access != null && tokens.access != usedAccess) return true
        val refresh = tokens.refresh ?: return false
        return try {
            val data = post("/auth/refresh", buildJsonObject { put("refresh_token", refresh) }, auth = false).jsonObject
            tokens.save(data.str("access_token"), data.str("refresh_token"))
            true
        } catch (e: ApiException) {
            false
        }
    }

    private companion object {
        val JSON_TYPE = "application/json; charset=utf-8".toMediaType()
    }
}
