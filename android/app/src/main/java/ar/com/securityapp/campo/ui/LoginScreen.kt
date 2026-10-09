package ar.com.securityapp.campo.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.systemBarsPadding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import ar.com.securityapp.campo.BuildConfig
import ar.com.securityapp.campo.ui.theme.Accent
import ar.com.securityapp.campo.ui.theme.Brand
import ar.com.securityapp.campo.ui.theme.BrandDark

@Composable
fun LoginScreen(vm: SessionViewModel, state: Screen.Login) {
    var empresa by rememberSaveable { mutableStateOf(vm.prefs.empresa) }
    var usuario by rememberSaveable { mutableStateOf(vm.prefs.usuario) }
    var password by rememberSaveable { mutableStateOf("") }
    var totp by rememberSaveable { mutableStateOf("") }
    var showPassword by remember { mutableStateOf(false) }
    var showServer by remember { mutableStateOf(false) }
    var server by remember { mutableStateOf(vm.prefs.serverUrl) }

    Box(Modifier.fillMaxSize().background(Brand).systemBarsPadding().imePadding()) {
        Column(
            Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp, vertical = 32.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
        ) {
            Box(Modifier.size(64.dp).background(Accent, RoundedCornerShape(18.dp)), contentAlignment = Alignment.Center) {
                Icon(Icons.Filled.Lock, contentDescription = null, tint = BrandDark, modifier = Modifier.size(32.dp))
            }
            Spacer(Modifier.height(14.dp))
            Text("Security App", color = Color.White, style = MaterialTheme.typography.headlineSmall, fontWeight = FontWeight.Bold)
            Text("Seguridad e Higiene en planta", color = Color(0xFFB4BACB), style = MaterialTheme.typography.bodyMedium)
            Spacer(Modifier.height(24.dp))
            Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = Color.White)) {
                Column(Modifier.padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Text("Ingresar", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.SemiBold)
                    OutlinedTextField(empresa, { empresa = it.lowercase().trim() }, Modifier.fillMaxWidth(), label = { Text("Empresa") },
                        supportingText = { Text("El identificador que te pasó tu empresa (ej. indumor)") }, singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Ascii, imeAction = ImeAction.Next))
                    OutlinedTextField(usuario, { usuario = it }, Modifier.fillMaxWidth(), label = { Text("Email o DNI") }, singleLine = true,
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email, imeAction = ImeAction.Next))
                    OutlinedTextField(password, { password = it }, Modifier.fillMaxWidth(), label = { Text("Contraseña") }, singleLine = true,
                        visualTransformation = if (showPassword) VisualTransformation.None else PasswordVisualTransformation(),
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = if (state.needTotp) ImeAction.Next else ImeAction.Done),
                        trailingIcon = { TextButton({ showPassword = !showPassword }) { Text(if (showPassword) "Ocultar" else "Ver") } })
                    if (state.needTotp) {
                        OutlinedTextField(totp, { totp = it.filter(Char::isDigit).take(6) }, Modifier.fillMaxWidth(), label = { Text("Código de verificación (6 números)") },
                            singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.NumberPassword, imeAction = ImeAction.Done))
                    }
                    state.error?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodyMedium) }
                    Button(
                        onClick = { vm.setServer(server); vm.login(empresa, usuario, password, totp) },
                        enabled = !state.busy,
                        modifier = Modifier.fillMaxWidth().height(52.dp),
                        colors = ButtonDefaults.buttonColors(containerColor = Brand),
                    ) {
                        if (state.busy) CircularProgressIndicator(Modifier.size(22.dp), color = Accent, strokeWidth = 2.dp) else Text("Ingresar", fontWeight = FontWeight.SemiBold)
                    }
                }
            }
            Spacer(Modifier.height(16.dp))
            Text("¿No tenés usuario? Pedíselo al responsable de Seguridad e Higiene.", color = Color(0xFFB4BACB), style = MaterialTheme.typography.bodySmall)
            if (BuildConfig.DEBUG) {
                TextButton({ showServer = !showServer }) { Text("Servidor (desarrollo)", color = Color(0xFFB4BACB)) }
                if (showServer) {
                    OutlinedTextField(server, { server = it }, Modifier.fillMaxWidth(), singleLine = true,
                        label = { Text("URL del servidor", color = Color(0xFFB4BACB)) },
                        supportingText = { Text("Emulador: http://10.0.2.2/securityapp · Celular: http://IP-de-la-Mac/securityapp", color = Color(0xFFB4BACB)) },
                        textStyle = MaterialTheme.typography.bodyMedium.copy(color = Color.White))
                }
            }
            Text("Versión ${BuildConfig.VERSION_NAME}", color = Color(0xFF8A91A5), style = MaterialTheme.typography.labelSmall)
        }
    }
}
