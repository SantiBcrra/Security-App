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
import ar.com.securityapp.campo.ui.theme.Accent
import ar.com.securityapp.campo.ui.theme.Brand

@Composable
fun AppRoot(vm: SessionViewModel, screen: Screen, openIntent: (Intent) -> Unit) {
    Crossfade(targetState = screen::class, label = "pantalla") { _ ->
        when (screen) {
            Screen.Loading -> Box(Modifier.fillMaxSize().background(Brand), contentAlignment = Alignment.Center) {
                CircularProgressIndicator(color = Accent)
            }
            is Screen.Login -> LoginScreen(vm, screen)
            is Screen.Consent -> ConsentScreen(vm, screen)
            is Screen.Home -> HomeScreen(vm, screen.me, openIntent)
            is Screen.UpdateRequired -> UpdateRequiredScreen(vm, screen, openIntent)
        }
    }
}
