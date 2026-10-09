package ar.com.securityapp.campo.ui

import android.content.Intent
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import ar.com.securityapp.campo.BuildConfig
import ar.com.securityapp.campo.ui.theme.Accent
import ar.com.securityapp.campo.ui.theme.Brand
import ar.com.securityapp.campo.ui.theme.BrandDark

/** Versión obligatoria (min_version_code): la app no deja seguir hasta actualizar. */
@Composable
fun UpdateRequiredScreen(vm: SessionViewModel, state: Screen.UpdateRequired, openIntent: (Intent) -> Unit) {
    val update by vm.update.collectAsStateWithLifecycle()
    Column(Modifier.fillMaxSize().background(Brand).padding(24.dp), verticalArrangement = Arrangement.Center, horizontalAlignment = Alignment.CenterHorizontally) {
        Text("Hay que actualizar la app", color = Color.White, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold, textAlign = TextAlign.Center)
        Text("Tenés la versión ${BuildConfig.VERSION_NAME}. Para seguir usándola instalá la ${state.release.versionName}.",
            color = Color(0xFFB4BACB), textAlign = TextAlign.Center, modifier = Modifier.padding(top = 8.dp))
        state.release.notes?.let { Text(it, color = Color.White, modifier = Modifier.padding(top = 12.dp), textAlign = TextAlign.Center) }
        update.error?.let { Text(it, color = Accent, modifier = Modifier.padding(top = 12.dp), textAlign = TextAlign.Center) }
        if (update.downloading) {
            LinearProgressIndicator(progress = { update.progress }, modifier = Modifier.fillMaxWidth().padding(top = 20.dp), color = Accent)
        } else {
            Button({ vm.installUpdate(openIntent) }, Modifier.fillMaxWidth().padding(top = 20.dp).height(52.dp),
                colors = ButtonDefaults.buttonColors(containerColor = Accent, contentColor = BrandDark)) { Text("Actualizar ahora", fontWeight = FontWeight.Bold) }
        }
    }
}
