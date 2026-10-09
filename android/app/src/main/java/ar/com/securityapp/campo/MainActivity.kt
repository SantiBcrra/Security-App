package ar.com.securityapp.campo

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import androidx.compose.runtime.getValue
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import ar.com.securityapp.campo.ui.AppRoot
import ar.com.securityapp.campo.ui.SessionViewModel
import ar.com.securityapp.campo.ui.theme.SecurityAppTheme

class MainActivity : ComponentActivity() {
    private val vm: SessionViewModel by viewModels()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            SecurityAppTheme {
                val screen by vm.screen.collectAsStateWithLifecycle()
                AppRoot(vm, screen) { intent -> startActivity(intent) }
            }
        }
    }

    override fun onResume() {
        super.onResume()
        vm.refreshUpdate() // al volver del instalador o de los ajustes
    }
}
