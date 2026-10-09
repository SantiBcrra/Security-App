package ar.com.securityapp.campo.data

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Tokens de la sesión cifrados con una clave del Android Keystore (no se puede extraer del celular).
 * Access token: 15 min. Refresh token: rotativo, 60 días; usar uno viejo revoca el dispositivo en el servidor.
 */
class TokenStore(context: Context) {
    private val sp = context.getSharedPreferences("tokens", Context.MODE_PRIVATE)

    var access: String?
        get() = read("access")
        private set(value) = write("access", value)

    var refresh: String?
        get() = read("refresh")
        private set(value) = write("refresh", value)

    fun save(access: String, refresh: String) {
        this.access = access
        this.refresh = refresh
    }

    fun clear() {
        sp.edit().clear().apply()
    }

    private fun key(): SecretKey {
        val ks = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (ks.getKey(ALIAS, null) as? SecretKey)?.let { return it }
        val gen = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        gen.init(KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
            .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
            .build())
        return gen.generateKey()
    }

    private fun write(name: String, value: String?) {
        if (value == null) {
            sp.edit().remove(name).apply()
            return
        }
        val cipher = Cipher.getInstance("AES/GCM/NoPadding").apply { init(Cipher.ENCRYPT_MODE, key()) }
        val data = cipher.iv + cipher.doFinal(value.toByteArray())
        sp.edit().putString(name, Base64.encodeToString(data, Base64.NO_WRAP)).apply()
    }

    private fun read(name: String): String? {
        val stored = sp.getString(name, null) ?: return null
        return try {
            val data = Base64.decode(stored, Base64.NO_WRAP)
            val cipher = Cipher.getInstance("AES/GCM/NoPadding")
            cipher.init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, data, 0, 12))
            String(cipher.doFinal(data, 12, data.size - 12))
        } catch (e: Exception) {
            null // clave regenerada o dato dañado: hay que volver a ingresar
        }
    }

    private companion object {
        const val ALIAS = "securityapp_tokens"
    }
}
