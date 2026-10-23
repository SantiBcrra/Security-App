package ar.com.securityapp.campo.ui

import android.content.Intent
import androidx.compose.animation.Crossfade
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.foundation.layout.size
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.unit.dp
import androidx.compose.runtime.getValue
import androidx.compose.animation.core.animateFloat
import ar.com.securityapp.campo.ui.theme.Accent
import ar.com.securityapp.campo.ui.theme.Brand

@Composable
fun AppRoot(vm: SessionViewModel, screen: Screen, openIntent: (Intent) -> Unit) {
    Crossfade(targetState = screen::class, label = "pantalla") { _ ->
        when (screen) {
            Screen.Loading -> LoadingScreen()
            is Screen.Login -> LoginScreen(vm, screen)
            is Screen.Consent -> ConsentScreen(vm, screen)
            is Screen.Home -> HomeScreen(vm, screen.me, openIntent)
            is Screen.UpdateRequired -> UpdateRequiredScreen(vm, screen, openIntent)
        }
    }
}

/** Mientras arranca: el mismo escudo de la pantalla de inicio del sistema, para que no haya saltos. */
@Composable
private fun LoadingScreen() {
    val alpha = androidx.compose.animation.core.rememberInfiniteTransition(label = "carga").animateFloat(
        initialValue = 0.55f, targetValue = 1f, label = "pulso",
        animationSpec = androidx.compose.animation.core.infiniteRepeatable(androidx.compose.animation.core.tween<Float>(900), androidx.compose.animation.core.RepeatMode.Reverse))
    Box(Modifier.fillMaxSize().background(androidx.compose.ui.graphics.Color(0xFF353C4F)), contentAlignment = Alignment.Center) {
        androidx.compose.foundation.Image(androidx.compose.ui.res.painterResource(ar.com.securityapp.campo.R.drawable.security_logo), null,
            Modifier.size(112.dp).graphicsLayer { this.alpha = alpha.value })
    }
}
