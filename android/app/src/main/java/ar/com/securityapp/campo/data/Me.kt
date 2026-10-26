package ar.com.securityapp.campo.data

import kotlinx.serialization.json.JsonObject

/** Quién está usando la app (de GET /me): nombre, rol, empresa, permisos por módulo y consentimiento. */
data class Me(
    val name: String,
    val role: String,
    val companyName: String,
    val companySlug: String,
    val permissions: Map<String, Set<String>>,
    val consentVersion: String,
    val consentAccepted: Boolean,
    /** "Ver como": nombre del administrador que está mirando la app como este usuario (null = uso normal). */
    val previewBy: String? = null,
    /** Celular compartido del puesto: varios guardias usan este celular y cada uno se identifica con su PIN al empezar el turno. */
    val sharedDevice: Boolean = false,
) {
    val isPreview: Boolean get() = previewBy != null

    fun can(module: String, action: String = "ver"): Boolean = permissions[module]?.contains(action) == true

    companion object {
        fun from(o: JsonObject): Me {
            val user = o.obj("usuario") ?: JsonObject(emptyMap())
            val company = o.obj("empresa") ?: JsonObject(emptyMap())
            val consent = o.obj("consentimiento") ?: JsonObject(emptyMap())
            val perms = (o.obj("permisos") ?: JsonObject(emptyMap())).mapValues { (_, v) ->
                (v as? JsonObject)?.arr("acciones")?.mapNotNull { (it as? kotlinx.serialization.json.JsonPrimitive)?.content }?.toSet() ?: emptySet()
            }
            val previewBy = o.obj("vista_previa")?.strOrNull("por")
            return Me(
                name = user.str("nombre"), role = user.obj("rol")?.str("nombre") ?: "",
                companyName = company.str("nombre"), companySlug = company.str("slug"),
                permissions = perms, consentVersion = consent.str("version"), consentAccepted = consent.bool("aceptado") || previewBy != null, previewBy = previewBy,
                sharedDevice = o.bool("celular_puesto"),
            )
        }
    }
}
