package ar.com.securityapp.campo.data

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.net.Uri
import androidx.exifinterface.media.ExifInterface
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.put
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.File
import java.io.IOException
import java.io.RandomAccessFile
import java.security.MessageDigest
import java.util.UUID
import kotlin.math.max

/**
 * Fotos de evidencia: se achican a 1600 px (JPEG), quedan en el celular con su SHA-256 y se suben por partes
 * (`POST /uploads` → `PUT /uploads/{id}?offset=` → `/complete`), reanudables si se corta la señal. Solo se suben cuando
 * el registro al que pertenecen (p. ej. la observación) ya llegó al servidor.
 */
class Uploads(private val context: Context, private val api: ApiClient, private val db: LocalDb, private val tokens: TokenStore) {

    /** Achica y guarda la foto en la cola. @return el uuid de la subida */
    suspend fun add(source: Uri, target: String, targetUuid: String, itemKey: String? = null): LocalDb.Upload = withContext(Dispatchers.IO) {
        val dir = File(context.filesDir, "uploads").apply { mkdirs() }
        val uuid = UUID.randomUUID().toString()
        val file = File(dir, "$uuid.jpg")
        shrink(source, file)
        val up = LocalDb.Upload(uuid, target, targetUuid, file.absolutePath, "foto.jpg", file.length(), sha256(file), 0, "pending", null, itemKey)
        db.addUpload(up)
        up
    }

    /** @param ready qué registros ya existen en el servidor (target_uuid) */
    suspend fun push(ready: (LocalDb.Upload) -> Boolean): Int {
        var done = 0
        for (up in db.uploads("pending")) {
            if (!ready(up)) continue
            val file = File(up.path)
            if (!file.exists()) {
                db.updateUpload(up.uploadUuid, "rejected", up.received, "La foto se borró del celular.")
                continue
            }
            val state = try {
                api.post("/uploads", buildJsonObject {
                    put("upload_uuid", up.uploadUuid); put("${up.target}_uuid", up.targetUuid); put("name", up.name); put("size", up.size); put("sha256", up.sha256)
                    up.itemKey?.let { put("item_key", it) }
                }).jsonObject
            } catch (e: ApiException) {
                if (e.status in 400..499) { db.updateUpload(up.uploadUuid, "rejected", up.received, e.message); continue } else throw e
            }
            var received = state.long("received_bytes")
            var status = state.str("status")
            while (status == "receiving" && received < up.size) {
                val (st, rec) = putChunk(up, file, received)
                status = st
                received = rec
                db.updateUpload(up.uploadUuid, "pending", received)
            }
            if (status == "receiving") {
                try {
                    status = api.post("/uploads/${up.uploadUuid}/complete").jsonObject.str("status")
                } catch (e: ApiException) {
                    if (e.status == 409) continue // faltan partes: se completa en la próxima sincronización
                    if (e.status == 422 && (e.message?.contains("hash") == true || e.message?.contains("dañada") == true)) continue // se reintenta entera
                    if (e.status in 400..499) { db.updateUpload(up.uploadUuid, "rejected", received, e.message); continue } else throw e
                }
            }
            if (status == "completed") {
                db.updateUpload(up.uploadUuid, "done", up.size)
                file.delete()
                done++
            }
        }
        return done
    }

    /** Una parte de hasta 512 KB, cuerpo crudo. 409 = el servidor tiene otra cantidad: sigue desde ahí. */
    private suspend fun putChunk(up: LocalDb.Upload, file: File, offset: Long): Pair<String, Long> = withContext(Dispatchers.IO) {
        val len = minOf(CHUNK.toLong(), up.size - offset).toInt()
        val bytes = ByteArray(len)
        RandomAccessFile(file, "r").use { it.seek(offset); it.readFully(bytes) }
        val url = api.apiUrl("/uploads/${up.uploadUuid}").toHttpUrl().newBuilder().addQueryParameter("offset", offset.toString()).build()
        val req = Request.Builder().url(url).header("Accept", "application/json").header("Authorization", "Bearer ${tokens.access}")
            .put(bytes.toRequestBody("application/octet-stream".toMediaType())).build()
        try {
            api.http.newCall(req).execute().use { res ->
                val body = runCatching { api.json.parseToJsonElement(res.body?.string() ?: "").jsonObject }.getOrNull()
                val data = body?.get("data") as? JsonObject // 200 y 409 traen el estado de la subida
                if (res.code == 401) throw ApiException(401, null, "Sesión vencida.")
                if (data == null && res.code != 409) throw ApiException(res.code, null, "Error al subir la foto (${res.code}).")
                val d = data ?: JsonObject(emptyMap())
                d.str("status").ifEmpty { "receiving" } to (if (d.containsKey("received_bytes")) d.long("received_bytes") else offset)
            }
        } catch (e: IOException) {
            throw OfflineException()
        }
    }

    private fun shrink(source: Uri, out: File) {
        val bytes = context.contentResolver.openInputStream(source)?.use { it.readBytes() } ?: throw IOException("No se pudo leer la foto.")
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeByteArray(bytes, 0, bytes.size, bounds)
        var sample = 1
        while (max(bounds.outWidth, bounds.outHeight) / (sample * 2) >= SIDE) sample *= 2
        var bmp = BitmapFactory.decodeByteArray(bytes, 0, bytes.size, BitmapFactory.Options().apply { inSampleSize = sample })
            ?: throw IOException("La imagen no es válida.")
        val rotation = runCatching { ExifInterface(bytes.inputStream()).rotationDegrees }.getOrDefault(0)
        val scale = minOf(1f, SIDE.toFloat() / max(bmp.width, bmp.height))
        if (scale < 1f || rotation != 0) {
            val m = Matrix().apply { postScale(scale, scale); postRotate(rotation.toFloat()) }
            bmp = Bitmap.createBitmap(bmp, 0, 0, bmp.width, bmp.height, m, true)
        }
        out.outputStream().use { bmp.compress(Bitmap.CompressFormat.JPEG, 85, it) }
    }

    private fun sha256(file: File): String {
        val md = MessageDigest.getInstance("SHA-256")
        file.inputStream().use { input -> val buf = ByteArray(64 * 1024); while (true) { val n = input.read(buf); if (n < 0) break; md.update(buf, 0, n) } }
        return md.digest().joinToString("") { "%02x".format(it) }
    }

    private companion object {
        const val CHUNK = 512 * 1024
        const val SIDE = 1600
    }
}
