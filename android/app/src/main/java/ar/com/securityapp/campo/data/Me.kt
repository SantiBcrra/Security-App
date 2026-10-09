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
) {
    fun can(module: String, action: String = "ver"): Boolean = permissions[module]?.contains(action) == true

    companion object {
        fun from(o: JsonObject): Me {
            val user = o.obj("usuario") ?: JsonObject(emptyMap())
            val company = o.obj("empresa") ?: JsonObject(emptyMap())
            val consent = o.obj("consentimiento") ?: JsonObject(emptyMap())
            val perms = (o.obj("permisos") ?: JsonObject(emptyMap())).mapValues { (_, v) ->
                (v as? JsonObject)?.arr("acciones")?.mapNotNull { (it as? kotlinx.serialization.json.JsonPrimitive)?.content }?.toSet() ?: emptySet()
            }
            return Me(
                name = user.str("nombre"), role = user.obj("rol")?.str("nombre") ?: "",
                companyName = company.str("nombre"), companySlug = company.str("slug"),
                permissions = perms, consentVersion = consent.str("version"), consentAccepted = consent.bool("aceptado"),
            )
        }
    }
}
