package ar.com.securityapp.campo.data

import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.core.content.FileProvider
import ar.com.securityapp.campo.BuildConfig
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.jsonObject
import okhttp3.Request
import java.io.File
import java.io.IOException
import java.security.MessageDigest

/**
 * Actualización propia (sin Google Play): pregunta al servidor la última versión publicada en /admin/app-android,
 * baja el APK, verifica el SHA-256 y abre el instalador de Android (que exige la misma firma que la app instalada).
 */
class UpdateManager(private val context: Context, private val api: ApiClient) {

    data class Release(val versionCode: Int, val versionName: String, val minVersionCode: Int, val sha256: String, val sizeBytes: Long,
                       val url: String, val notes: String?) {
        val isNewer: Boolean get() = versionCode > BuildConfig.VERSION_CODE
        val isRequired: Boolean get() = minVersionCode > BuildConfig.VERSION_CODE
    }

    /** null = no hay versiones publicadas o no hay señal. */
    suspend fun check(): Release? = try {
        val o = api.get("/app/android", auth = false).jsonObject
        Release(o.int("version_code"), o.str("version_name"), o.int("min_version_code"), o.str("sha256"), o.long("size_bytes"), o.str("url"), o.strOrNull("notes"))
    } catch (e: Exception) {
        null
    }

    /** Baja el APK a la caché y verifica que sea exactamente el publicado. */
    suspend fun download(release: Release, onProgress: (Float) -> Unit): File = withContext(Dispatchers.IO) {
        val dir = File(context.cacheDir, "updates").apply { mkdirs() }
        dir.listFiles()?.forEach { it.delete() }
        val file = File(dir, "securityapp-${release.versionCode}.apk")
        val digest = MessageDigest.getInstance("SHA-256")
        try {
            api.http.newCall(Request.Builder().url(release.url).build()).execute().use { res ->
                if (!res.isSuccessful) throw IOException("El servidor respondió ${res.code}.")
                val body = res.body ?: throw IOException("Descarga vacía.")
                val total = body.contentLength().takeIf { it > 0 } ?: release.sizeBytes
                var done = 0L
                body.byteStream().use { input ->
                    file.outputStream().use { out ->
                        val buf = ByteArray(64 * 1024)
                        while (true) {
                            val n = input.read(buf)
                            if (n < 0) break
                            out.write(buf, 0, n)
                            digest.update(buf, 0, n)
                            done += n
                            if (total > 0) onProgress(done.toFloat() / total)
                        }
                    }
                }
            }
        } catch (e: IOException) {
            file.delete()
            throw OfflineException("No se pudo descargar la actualización: ${e.message}")
        }
        val hash = digest.digest().joinToString("") { "%02x".format(it) }
        if (!hash.equals(release.sha256, ignoreCase = true)) {
            file.delete()
            throw ApiException(0, "hash", "La descarga llegó dañada. Probá de nuevo.")
        }
        file
    }

    /** Android pide una vez permiso para instalar apps desde Security App; después abre el instalador. */
    fun canInstall(): Boolean = Build.VERSION.SDK_INT < Build.VERSION_CODES.O || context.packageManager.canRequestPackageInstalls()

    fun installPermissionIntent(): Intent =
        Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:${context.packageName}")).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)

    fun installIntent(file: File): Intent {
        val uri = FileProvider.getUriForFile(context, "${context.packageName}.files", file)
        return Intent(Intent.ACTION_VIEW).setDataAndType(uri, "application/vnd.android.package-archive")
            .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
    }
}
