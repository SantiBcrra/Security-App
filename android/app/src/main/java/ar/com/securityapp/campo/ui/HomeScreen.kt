package ar.com.securityapp.campo.ui

import android.content.Intent
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.Image
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
import androidx.compose.material.icons.filled.Notifications
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.Settings
import androidx.compose.material.icons.filled.Warning
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Menu
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.FloatingActionButton
import androidx.compose.material3.Surface
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
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.foundation.layout.offset
import androidx.compose.ui.zIndex
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import ar.com.securityapp.campo.data.Me
import ar.com.securityapp.campo.ui.theme.Accent
import ar.com.securityapp.campo.ui.theme.Brand
import ar.com.securityapp.campo.ui.theme.BrandDark
import ar.com.securityapp.campo.ui.theme.Danger
import ar.com.securityapp.campo.ui.theme.Muted
import ar.com.securityapp.campo.R
import kotlinx.coroutines.launch

/** Módulo del menú: se muestra solo si el rol tiene el permiso `ver`. Se habilitan de a uno en las próximas entregas. */
private data class Module(val key: String, val label: String, val hint: String, val icon: ImageVector)

/** Módulos que ya tienen pantalla en la app (el resto se habilita en las próximas entregas). */
private val READY = setOf("rondas", "observaciones", "acciones", "inspecciones", "incidentes")

private val MODULES = listOf(
    Module("rondas", "Rondas", "Recorridas con QR y NFC", Icons.Filled.LocationOn),
    Module("observaciones", "Observaciones", "Reportar actos y condiciones", Icons.Filled.Search),
    Module("inspecciones", "Inspecciones", "Checklists de equipos y sectores", Icons.Filled.CheckCircle),
    Module("acciones", "Acciones", "Correctivas a mi cargo", Icons.Filled.Build),
    Module("incidentes", "Incidentes", "Accidentes e incidentes", Icons.Filled.Warning),
)

/*
 * Desactivados por decisión del usuario (09/10/2026): no se usan todavía en la app; se activan más adelante
 * volviéndolos a agregar a MODULES.
 * Module("permisos_trabajo", "Permisos", "Permisos de trabajo", Icons.Filled.Create),
 * Module("epp", "EPP", "Entregas con firma", Icons.Filled.Face),
 */

@Composable
fun HomeScreen(vm: SessionViewModel, me: Me, openIntent: (Intent) -> Unit) {
    val sync by vm.sync.collectAsStateWithLifecycle()
    val update by vm.update.collectAsStateWithLifecycle()
    var showSettings by remember(me.isPreview) { mutableStateOf(false) } // al entrar o salir de "Ver como" se vuelve al inicio
    var openModule by remember(me.isPreview) { mutableStateOf<String?>(null) }
    var openScanner by remember(me.isPreview) { mutableStateOf(false) }
    var showShifts by remember(me.isPreview) { mutableStateOf(false) }
    val snackbar = remember { SnackbarHostState() }
    val scope = rememberCoroutineScope()
    val unread by vm.unread.collectAsStateWithLifecycle()
    val openAvisos by vm.openAvisos.collectAsStateWithLifecycle()
    val openShift by vm.openShift.collectAsStateWithLifecycle()
    androidx.compose.runtime.LaunchedEffect(openShift) {
        if (openShift) { openModule = null; showSettings = false; showShifts = false; vm.openShift.value = false }
    }
    if (showShifts) {
        HomeWithNavigation(vm, me, selected = "activity", onHome = { showShifts = false }, onActivity = { showShifts = false; openModule = "rondas" },
            onScan = { showShifts = false; openModule = "rondas"; openScanner = true },
            onNotices = { showShifts = false; vm.openAvisos.value = true }, onMore = { showShifts = false; showSettings = true }) {
            ar.com.securityapp.campo.ui.shifts.ShiftsScreen(onBack = { showShifts = false }, showTopBar = false)
        }
        return
    }
    if (showSettings) {
        HomeWithNavigation(vm, me, selected = "more", onHome = { showSettings = false }, onActivity = { showSettings = false; openModule = "rondas" },
            onScan = { showSettings = false; openModule = "rondas"; openScanner = true },
            onNotices = { showSettings = false; vm.openAvisos.value = true }, onMore = { showSettings = false }) {
            SettingsScreen(vm, me, onBack = { showSettings = false }, showTopBar = false)
        }
        return
    }
    val notifPermission = androidx.activity.compose.rememberLauncherForActivityResult(
        androidx.activity.result.contract.ActivityResultContracts.RequestPermission()) { }
    androidx.compose.runtime.LaunchedEffect(Unit) {
        if (android.os.Build.VERSION.SDK_INT >= 33) notifPermission.launch(android.Manifest.permission.POST_NOTIFICATIONS)
    }
    if (openAvisos) {
        HomeWithNavigation(vm, me, selected = "notices", onHome = { vm.openAvisos.value = false }, onActivity = { vm.openAvisos.value = false; openModule = "rondas" },
            onScan = { vm.openAvisos.value = false; openModule = "rondas"; openScanner = true },
            onNotices = { vm.openAvisos.value = false }, onMore = { vm.openAvisos.value = false; showSettings = true }) {
            AvisosScreen(onBack = { vm.openAvisos.value = false; vm.refreshUnread() }, showTopBar = false)
        }
        return
    }
    when (openModule) {
        "rondas" -> {
            HomeWithNavigation(vm, me, selected = "activity", onHome = { openModule = null; openScanner = false }, onActivity = { openScanner = false },
                onScan = { openScanner = true }, onNotices = { openModule = null; openScanner = false; vm.openAvisos.value = true },
                onMore = { openModule = null; openScanner = false; showSettings = true }) {
                ar.com.securityapp.campo.ui.rounds.RondasScreen(onBack = { openModule = null; openScanner = false; vm.refreshCounts() }, canManagePoints = me.can("rondas", "editar"), initialScan = openScanner, showTopBar = false)
            }
            return
        }
        "acciones" -> { HomeWithNavigation(vm, me, selected = "activity", onHome = { openModule = null }, onActivity = { openModule = "rondas" },
            onScan = { openModule = "rondas"; openScanner = true }, onNotices = { openModule = null; vm.openAvisos.value = true },
            onMore = { openModule = null; showSettings = true }) {
                ar.com.securityapp.campo.ui.actions.AccionesScreen(onBack = { openModule = null; vm.refreshCounts() }, showTopBar = false)
            }; return }
        "inspecciones" -> {
            HomeWithNavigation(vm, me, selected = "activity", onHome = { openModule = null }, onActivity = { openModule = "rondas" },
                onScan = { openModule = "rondas"; openScanner = true }, onNotices = { openModule = null; vm.openAvisos.value = true },
                onMore = { openModule = null; showSettings = true }) {
                ar.com.securityapp.campo.ui.inspections.InspeccionesScreen(onBack = { openModule = null; vm.refreshCounts() }, canCreate = me.can("inspecciones", "crear"), showTopBar = false)
            }
            return
        }
        "incidentes" -> {
            HomeWithNavigation(vm, me, selected = "activity", onHome = { openModule = null }, onActivity = { openModule = "rondas" },
                onScan = { openModule = "rondas"; openScanner = true }, onNotices = { openModule = null; vm.openAvisos.value = true },
                onMore = { openModule = null; showSettings = true }) {
                ar.com.securityapp.campo.ui.incidents.IncidentesScreen(onBack = { openModule = null; vm.refreshCounts() }, canCreate = me.can("incidentes", "crear"), showTopBar = false)
            }
            return
        }
        "observaciones" -> {
            HomeWithNavigation(vm, me, selected = "activity", onHome = { openModule = null }, onActivity = { openModule = "rondas" },
                onScan = { openModule = "rondas"; openScanner = true }, onNotices = { openModule = null; vm.openAvisos.value = true },
                onMore = { openModule = null; showSettings = true }) {
                ar.com.securityapp.campo.ui.observations.ObservacionesScreen(onBack = { openModule = null; vm.refreshCounts() }, canCreate = me.can("observaciones", "crear"), showTopBar = false)
            }
            return
        }
    }
    // El responsable de una acción la ve aunque su rol no tenga el módulo (como en la web).
    val modules = MODULES.filter { me.can(it.key) || (it.key == "acciones" && (sync.counts["actions"] ?: 0) > 0) }
    val panic by vm.panic.collectAsStateWithLifecycle()
    panic?.let { state ->
        HomeWithNavigation(vm, me, selected = "home", onHome = { vm.closePanic() }, onActivity = { vm.closePanic(); openModule = "rondas" },
            onScan = { vm.closePanic(); openModule = "rondas"; openScanner = true },
            onNotices = { vm.closePanic(); vm.openAvisos.value = true }, onMore = { vm.closePanic(); showSettings = true }) {
            ar.com.securityapp.campo.panic.PanicScreen((state as? SessionViewModel.PanicState.Done)?.result, onClose = { vm.closePanic() })
        }
        return
    }

    Scaffold(snackbarHost = { SnackbarHost(snackbar) }, containerColor = MaterialTheme.colorScheme.background, topBar = {
        HomeTopBar(vm, me, unread, sync.running, onNotices = { vm.openAvisos.value = true }, onSettings = { showSettings = true })
    }, bottomBar = {
        HomeBottomBar(
            selected = "home",
            onHome = { openModule = null; showSettings = false },
            onActivity = { openModule = "rondas"; openScanner = false },
            onScan = { openModule = "rondas"; openScanner = true },
            onNotices = { vm.openAvisos.value = true },
            onMore = { showSettings = true },
        )
    }) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
        if (me.isPreview) PreviewBanner(me) { vm.exitPreview() }
        val previewState by vm.preview.collectAsStateWithLifecycle()
        previewState.notice?.let { n ->
            Card(Modifier.fillMaxWidth().padding(start = 16.dp, end = 16.dp, top = 12.dp), colors = CardDefaults.cardColors(containerColor = Color(0xFFFDF3CF))) {
                Row(Modifier.padding(start = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(n, style = MaterialTheme.typography.bodySmall, color = BrandDark, modifier = Modifier.weight(1f))
                    androidx.compose.material3.TextButton({ vm.clearPreviewNotice() }) { Text("OK") }
                }
            }
        }
        LazyVerticalGrid(
            columns = GridCells.Fixed(2),
            contentPadding = PaddingValues(16.dp),
            horizontalArrangement = Arrangement.spacedBy(12.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
            modifier = Modifier.fillMaxWidth().weight(1f),
        ) {
            update.release?.let { release ->
                item(span = { GridItemSpan(2) }) { UpdateCard(release.versionName, update, onInstall = { vm.installUpdate(openIntent) }) }
            }
            if (me.can("rondas", "crear")) {
                item(span = { GridItemSpan(2) }) { ar.com.securityapp.campo.ui.shifts.ShiftCard(onOpen = { showShifts = true }) }
                item(span = { GridItemSpan(2) }) { ar.com.securityapp.campo.panic.PanicButton(onTrigger = { vm.triggerPanic() }) }
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
}

@Composable
private fun HomeTopBar(vm: SessionViewModel, me: Me, unread: Int, syncing: Boolean, onNotices: () -> Unit, onSettings: () -> Unit) {
    Row(Modifier.fillMaxWidth().background(Brand).statusBarsPadding().padding(start = 16.dp, end = 4.dp, top = 8.dp, bottom = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        Image(painterResource(R.drawable.security_logo), "Logo de Security App", Modifier.size(38.dp).padding(end = 8.dp))
        Column(Modifier.weight(1f)) {
            Text(me.companyName, color = Color.White, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text("${me.name} · ${me.role}", color = Color(0xFFB4BACB), style = MaterialTheme.typography.bodySmall, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        IconButton(onNotices) {
            androidx.compose.material3.BadgedBox(badge = { if (unread > 0) androidx.compose.material3.Badge(containerColor = Accent, contentColor = BrandDark) { Text(if (unread > 99) "99+" else "$unread") } }) {
                Icon(Icons.Filled.Notifications, "Avisos", tint = Color.White)
            }
        }
        IconButton(vm::syncNow) { if (syncing) CircularProgressIndicator(Modifier.size(20.dp), color = Accent, strokeWidth = 2.dp) else Icon(Icons.Filled.Refresh, "Sincronizar", tint = Color.White) }
        IconButton(onSettings) { Icon(Icons.Filled.Settings, "Ajustes", tint = Color.White) }
    }
}

/** Navegación rápida inspirada en billeteras móviles: el QR queda siempre al alcance del pulgar. */
@Composable
private fun HomeBottomBar(selected: String, onHome: () -> Unit, onActivity: () -> Unit, onScan: () -> Unit, onNotices: () -> Unit, onMore: () -> Unit) {
    Box(Modifier.fillMaxWidth().height(80.dp).background(Color.White).zIndex(10f)) {
        NavigationBar(containerColor = Color.White, tonalElevation = 6.dp,
            modifier = Modifier.fillMaxWidth().align(Alignment.BottomCenter).zIndex(1f)) {
            NavigationBarItem(selected = selected == "home", onClick = onHome, icon = { Icon(Icons.Filled.Home, "Inicio") }, label = { Text("Inicio") })
            NavigationBarItem(selected = selected == "activity", onClick = onActivity, icon = { Icon(Icons.Filled.CheckCircle, "Actividad") }, label = { Text("Actividad") })
            Spacer(Modifier.weight(1f))
            NavigationBarItem(selected = selected == "notices", onClick = onNotices, icon = { Icon(Icons.Filled.Notifications, "Avisos") }, label = { Text("Avisos") })
            NavigationBarItem(selected = selected == "more", onClick = onMore, icon = { Icon(Icons.Filled.Menu, "Más") }, label = { Text("Más") })
        }
        Surface(
            modifier = Modifier.align(Alignment.TopCenter).offset(y = (-18).dp).size(72.dp).zIndex(2f),
            shape = androidx.compose.foundation.shape.CircleShape,
            color = Color.White,
            shadowElevation = 5.dp,
        ) {
            FloatingActionButton(onClick = onScan, containerColor = Accent, contentColor = BrandDark, modifier = Modifier.padding(6.dp)) {
                Icon(painterResource(R.drawable.ic_qr), "Escanear QR", Modifier.size(32.dp))
            }
        }
    }
}

/** Contenedor común para que la navegación inferior siga visible al entrar a cualquier módulo. */
@Composable
private fun HomeWithNavigation(
    vm: SessionViewModel,
    me: Me,
    selected: String,
    onHome: () -> Unit,
    onActivity: () -> Unit,
    onScan: () -> Unit,
    onNotices: () -> Unit,
    onMore: () -> Unit,
    content: @Composable () -> Unit,
) {
    Scaffold(
        containerColor = MaterialTheme.colorScheme.background,
        topBar = { HomeTopBar(vm, me, vm.unread.collectAsStateWithLifecycle().value, vm.sync.collectAsStateWithLifecycle().value.running, onNotices, onMore) },
        bottomBar = { HomeBottomBar(selected, onHome, onActivity, onScan, onNotices, onMore) },
    ) { padding ->
        Box(Modifier.fillMaxSize().padding(padding)) { content() }
    }
}

/** "Ver como": siempre visible arriba, con la salida a mano. */
@Composable
private fun PreviewBanner(me: Me, onExit: () -> Unit) {
    Row(Modifier.fillMaxWidth().background(Accent).padding(start = 16.dp, end = 4.dp, top = 6.dp, bottom = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text("VISTA PREVIA · ${me.name}", color = BrandDark, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.labelLarge,
                maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text("Rol ${me.role}. Podés probar todo: no se guarda ni se envía nada.", color = BrandDark,
                style = MaterialTheme.typography.bodySmall)
        }
        androidx.compose.material3.Button(onExit, colors = androidx.compose.material3.ButtonDefaults.buttonColors(containerColor = BrandDark)) { Text("Salir") }
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
