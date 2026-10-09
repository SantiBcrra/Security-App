package ar.com.securityapp.campo.ui

import android.content.Intent
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.GridItemSpan
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Build
import androidx.compose.material.icons.filled.CheckCircle
import androidx.compose.material.icons.filled.Create
import androidx.compose.material.icons.filled.Face
import androidx.compose.material.icons.filled.LocationOn
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import ar.com.securityapp.campo.data.Me
import ar.com.securityapp.campo.ui.theme.Accent
import ar.com.securityapp.campo.ui.theme.Brand
import ar.com.securityapp.campo.ui.theme.BrandDark
import ar.com.securityapp.campo.ui.theme.Danger
import ar.com.securityapp.campo.ui.theme.Muted
import kotlinx.coroutines.launch

/** Módulo del menú: se muestra solo si el rol tiene el permiso `ver`. Se habilitan de a uno en las próximas entregas. */
private data class Module(val key: String, val label: String, val hint: String, val icon: ImageVector)

/** Módulos que ya tienen pantalla en la app (el resto se habilita en las próximas entregas). */
private val READY = setOf("rondas")

private val MODULES = listOf(
    Module("rondas", "Rondas", "Recorridas con QR y NFC", Icons.Filled.LocationOn),
    Module("observaciones", "Observaciones", "Reportar actos y condiciones", Icons.Filled.Search),
    Module("inspecciones", "Inspecciones", "Checklists de equipos y sectores", Icons.Filled.CheckCircle),
    Module("acciones", "Acciones", "Correctivas a mi cargo", Icons.Filled.Build),
    Module("incidentes", "Incidentes", "Accidentes e incidentes", Icons.Filled.Warning),
    Module("permisos_trabajo", "Permisos", "Permisos de trabajo", Icons.Filled.Create),
    Module("epp", "EPP", "Entregas con firma", Icons.Filled.Face),
)

@Composable
fun HomeScreen(vm: SessionViewModel, me: Me, openIntent: (Intent) -> Unit) {
    val sync by vm.sync.collectAsStateWithLifecycle()
    val update by vm.update.collectAsStateWithLifecycle()
    var showSettings by remember { mutableStateOf(false) }
    var openModule by remember { mutableStateOf<String?>(null) }
    val snackbar = remember { SnackbarHostState() }
    val scope = rememberCoroutineScope()
    if (showSettings) {
        SettingsScreen(vm, me, onBack = { showSettings = false })
        return
    }
    when (openModule) {
        "rondas" -> { ar.com.securityapp.campo.ui.rounds.RondasScreen(onBack = { openModule = null; vm.refreshCounts() }); return }
    }
    val modules = MODULES.filter { me.can(it.key) }

    Scaffold(snackbarHost = { SnackbarHost(snackbar) }, containerColor = MaterialTheme.colorScheme.background, topBar = {
        Row(
            Modifier.fillMaxWidth().background(Brand).statusBarsPadding().padding(start = 16.dp, end = 4.dp, top = 8.dp, bottom = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f)) {
                Text(me.companyName, color = Color.White, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold,
                    maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text("${me.name} · ${me.role}", color = Color(0xFFB4BACB), style = MaterialTheme.typography.bodySmall, maxLines = 1, overflow = TextOverflow.Ellipsis)
            }
            IconButton({ vm.syncNow() }) {
                if (sync.running) CircularProgressIndicator(Modifier.size(20.dp), color = Accent, strokeWidth = 2.dp)
                else Icon(Icons.Filled.Refresh, "Sincronizar", tint = Color.White)
            }
            IconButton({ showSettings = true }) { Icon(Icons.Filled.Settings, "Ajustes", tint = Color.White) }
        }
    }) { padding ->
        LazyVerticalGrid(
            columns = GridCells.Fixed(2),
            contentPadding = PaddingValues(16.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
            modifier = Modifier.fillMaxSize().padding(padding),
        ) {
            update.release?.let { release ->
                item(span = { GridItemSpan(2) }) { UpdateCard(release.versionName, update, onInstall = { vm.installUpdate(openIntent) }) }
            }
            item(span = { GridItemSpan(2) }) { SyncCard(sync) }
            item(span = { GridItemSpan(2) }) {
                Text("Qué podés hacer", style = MaterialTheme.typography.titleSmall, color = Muted, modifier = Modifier.padding(top = 4.dp))
            }
            items(modules, key = { it.key }) { m ->
                ModuleTile(m, ready = m.key in READY) {
                    if (m.key in READY) openModule = m.key
                    else scope.launch { snackbar.showSnackbar("${m.label}: llega en la próxima versión de la app.") }
                }
            }
            if (modules.isEmpty()) {
                item(span = { GridItemSpan(2) }) {
                    Text("Tu rol todavía no tiene módulos habilitados. Consultá con el responsable de Seguridad e Higiene.", color = Muted)
                }
            }
        }
    }
}

@Composable
private fun ModuleTile(m: Module, ready: Boolean, onClick: () -> Unit) {
    Card(Modifier.fillMaxWidth().height(128.dp).clickable(onClick = onClick), colors = CardDefaults.cardColors(containerColor = Color.White),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp)) {
        Column(Modifier.fillMaxSize().padding(14.dp), verticalArrangement = Arrangement.SpaceBetween) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(42.dp).background(if (ready) Accent else Color(0xFFE8EAF0), RoundedCornerShape(12.dp)), contentAlignment = Alignment.Center) {
                    Icon(m.icon, null, tint = if (ready) BrandDark else Muted)
                }
                Spacer(Modifier.weight(1f))
                if (!ready) Text("Pronto", style = MaterialTheme.typography.labelSmall, color = Muted)
            }
            Column {
                Text(m.label, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
                Text(m.hint, style = MaterialTheme.typography.bodySmall, color = Muted, maxLines = 2, overflow = TextOverflow.Ellipsis)
            }
        }
    }
}

@Composable
private fun SyncCard(sync: SyncState) {
    Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = Color.White)) {
        Column(Modifier.padding(16.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(10.dp).background(if (sync.error) Danger else Color(0xFF2EB872), RoundedCornerShape(5.dp)))
                Spacer(Modifier.width(8.dp))
                Text(
                    if (sync.lastSync == 0L) "Todavía no se sincronizó"
                    else "Sincronizado " + ago(sync.lastSync),
                    style = MaterialTheme.typography.titleSmall, fontWeight = FontWeight.SemiBold,
                )
            }
            if (sync.pending > 0) Text("${sync.pending} envío(s) esperando señal", style = MaterialTheme.typography.bodySmall,
                color = BrandDark, fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 4.dp))
            sync.message?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = if (sync.error) Danger else Muted, modifier = Modifier.padding(top = 4.dp)) }
            if (sync.counts.isNotEmpty()) {
                val labels = mapOf("sectors" to "sectores", "equipment" to "equipos", "employees" to "empleados", "patrol_routes" to "rutas",
                    "patrol_points" to "puntos de ronda", "actions" to "acciones", "inspection_schedule" to "inspecciones programadas")
                Text("En el celular: " + sync.counts.filter { it.value > 0 }.map { "${it.value} ${labels[it.key]}" }.joinToString(" · ").ifEmpty { "sin datos todavía" },
                    style = MaterialTheme.typography.bodySmall, color = Muted, modifier = Modifier.padding(top = 4.dp))
            }
        }
    }
}

/** "hace un momento", "hace 5 min", "hace 2 h", "el 09/10 16:20" (siempre en español, aunque el celular esté en otro idioma). */
private fun ago(ms: Long): String {
    val min = (System.currentTimeMillis() - ms) / 60_000
    return when {
        min < 1 -> "hace un momento"
        min < 60 -> "hace $min min"
        min < 24 * 60 -> "hace ${min / 60} h"
        else -> "el " + java.text.SimpleDateFormat("dd/MM HH:mm", java.util.Locale("es", "AR")).format(java.util.Date(ms))
    }
}

@Composable
private fun UpdateCard(versionName: String, update: UpdateState, onInstall: () -> Unit) {
    Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = Color(0xFFFDF3CF))) {
        Column(Modifier.padding(16.dp)) {
            Text("Hay una versión nueva ($versionName)", fontWeight = FontWeight.SemiBold, color = BrandDark)
            update.release?.notes?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = BrandDark) }
            update.error?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Danger, modifier = Modifier.padding(top = 4.dp)) }
            if (update.downloading) {
                LinearProgressIndicator(progress = { update.progress }, modifier = Modifier.fillMaxWidth().padding(top = 8.dp), color = Brand)
            } else {
                Button(onInstall, Modifier.padding(top = 8.dp), colors = ButtonDefaults.buttonColors(containerColor = Brand)) { Text("Actualizar") }
            }
        }
    }
}
