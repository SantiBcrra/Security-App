import java.util.Properties

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.kotlin.serialization)
}

/*
 * Versión para los celulares (release): la firma y la dirección del servidor salen de android/keystore.properties, que NO
 * se sube al repo (ver keystore.properties.example). Perder la clave = no se puede actualizar la app instalada: respaldarla.
 */
val releaseProps = Properties().apply {
    rootProject.file("keystore.properties").takeIf { it.exists() }?.inputStream()?.use { load(it) }
}

android {
    namespace = "ar.com.securityapp.campo"
    compileSdk {
        version = release(37)
    }

    defaultConfig {
        // El applicationId NO se puede cambiar una vez instalada la app en los celulares.
        applicationId = "ar.com.securityapp.campo"
        minSdk = 26
        // 35 a propósito: al apuntar a Android 17 (37) el sistema bloquea las conexiones a la red local (la Mac con XAMPP
        // en 10.0.2.2 o por Wi-Fi) y la app no llega al servidor de desarrollo. Sin Google Play no hay exigencia de subirlo.
        targetSdk = 35
        // Subir los dos en cada versión publicada en /admin/app-android (versionCode siempre crece).
        versionCode = 10
        versionName = "1.0.0"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
    }

    signingConfigs {
        create("release") {
            releaseProps.getProperty("storeFile")?.let { storeFile = file(it) }
            storePassword = releaseProps.getProperty("storePassword")
            keyAlias = releaseProps.getProperty("keyAlias")
            keyPassword = releaseProps.getProperty("keyPassword")
        }
    }

    buildTypes {
        debug {
            // Emulador → XAMPP de la Mac. Para un celular real se cambia desde la pantalla de ingreso (solo en debug).
            buildConfigField("String", "SERVER_URL", "\"http://10.0.2.2/securityapp\"")
            applicationIdSuffix = ".debug"
            versionNameSuffix = "-debug"
        }
        release {
            // Dirección del sistema en producción (serverUrl en keystore.properties), por ejemplo https://seguridad.miempresa.com.ar
            buildConfigField("String", "SERVER_URL", "\"${releaseProps.getProperty("serverUrl", "").trimEnd('/')}\"")
            if (releaseProps.getProperty("storeFile") != null) signingConfig = signingConfigs.getByName("release")
            // Sin R8 a propósito: con la optimización (AGP 9) la versión final se cerraba al abrir (borraba constructores que
            // WorkManager y ML Kit usan por reflexión). Es una app de seguridad: corre el mismo código probado en debug.
            optimization {
                enable = false
            }
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_11
        targetCompatibility = JavaVersion.VERSION_11
    }
    buildFeatures {
        compose = true
        buildConfig = true
    }
}

dependencies {
    implementation(platform(libs.androidx.compose.bom))
    implementation(libs.androidx.activity.compose)
    implementation(libs.androidx.compose.material3)
    implementation(libs.androidx.compose.material.icons)
    implementation(libs.androidx.compose.ui)
    implementation(libs.androidx.compose.ui.graphics)
    implementation(libs.androidx.compose.ui.tooling.preview)
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.lifecycle.runtime.ktx)
    implementation(libs.androidx.lifecycle.runtime.compose)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.navigation.compose)
    implementation(libs.androidx.work.runtime.ktx)
    implementation(libs.okhttp)
    implementation(libs.kotlinx.serialization.json)
    implementation(libs.kotlinx.coroutines.android)
    implementation(libs.kotlinx.coroutines.play.services)
    implementation(libs.androidx.camera.camera2)
    implementation(libs.androidx.camera.lifecycle)
    implementation(libs.androidx.camera.view)
    implementation(libs.mlkit.barcode.scanning)
    implementation(libs.play.services.location)
    implementation(libs.firebase.messaging)
    implementation(libs.androidx.exifinterface)
    testImplementation(libs.junit)
    androidTestImplementation(platform(libs.androidx.compose.bom))
    androidTestImplementation(libs.androidx.compose.ui.test.junit4)
    androidTestImplementation(libs.androidx.espresso.core)
    androidTestImplementation(libs.androidx.junit)
    debugImplementation(libs.androidx.compose.ui.test.manifest)
    debugImplementation(libs.androidx.compose.ui.tooling)
}

// Sin firma o sin servidor no se arma la versión para los celulares (evita publicar un APK que no se puede actualizar o
// que apunta a ninguna parte).
gradle.taskGraph.whenReady {
    if (allTasks.any { it.path.startsWith(":app:") && it.name.contains("Release") && (it.name.startsWith("assemble") || it.name.startsWith("package")) }) {
        val missing = listOf("storeFile", "storePassword", "keyAlias", "keyPassword", "serverUrl").filter { releaseProps.getProperty(it).isNullOrBlank() }
        if (missing.isNotEmpty()) throw GradleException("Falta en android/keystore.properties: ${missing.joinToString()} (ver keystore.properties.example).")
        if (!releaseProps.getProperty("serverUrl").startsWith("https://")) throw GradleException("serverUrl tiene que empezar con https://")
    }
}
