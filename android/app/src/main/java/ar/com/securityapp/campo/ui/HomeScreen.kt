package ar.com.securityapp.campo.ui

import ar.com.securityapp.campo.ui.theme.*

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
import androidx.compose.material.icons.filled.Edit
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
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.ui.zIndex
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import ar.com.securityapp.campo.data.Me
import ar.com.securityapp.campo.R
import kotlinx.coroutines.launch
import kotlinx.coroutines.flow.first

/** Módulo del menú: se muestra solo si el rol tiene el permiso `ver`. Se habilitan de a uno en las próximas entregas. */
private data class Module(val key: String, val label: String, val hint: String, val icon: ImageVector)

/** Módulos que ya tienen pantalla en la app (el resto se habilita en las próximas entregas). */
private val READY = setOf("rondas", "observaciones", "acciones", "inspecciones", "incidentes", "libro")

private val MODULES = listOf(
    Module("rondas", "Rondas", "Recorridas con QR y NFC", Icons.Filled.LocationOn),
    Module("observaciones", "Observaciones", "Reportar actos y condiciones", Icons.Filled.Search),
    Module("inspecciones", "Inspecciones", "Checklists de equipos y sectores", Icons.Filled.CheckCircle),
    Module("acciones", "Acciones", "Correctivas a mi cargo", Icons.Filled.Build),
    Module("incidentes", "Incidentes", "Accidentes e incidentes", Icons.Filled.Warning),
    Module("libro", "Libro de guardia", "Novedades, ingresos y salidas, relevo", Icons.Filled.Edit),
)

/*
 * Desactivados por decisión del usuario (09/10/2026): no se usan todavía en la app; se activan más adelante
 * volviéndolos a agregar a MODULES.
 * Module("permisos_trabajo", "Permisos", "Permisos de trabajo", Icons.Filled.Create),
 * Module("epp", "EPP", "Entregas con firma", Icons.Filled.Face),
 */

/** Profundidad de cada pantalla para elegir la transición: inicio (0), pestañas (1) y módulos (2). */
private fun routeDepth(route: String): Int = when {
    route == "home" -> 0
    route == "avisos" || route == "settings" -> 1
    else -> 2
}

@Composable
fun HomeScreen(vm: SessionViewModel, me: Me, openIntent: (Intent) -> Unit) {
    val sync by vm.sync.collectAsStateWithLifecycle()
    val update by vm.update.collectAsStateWithLifecycle()
    var showSettings by remember(me.isPreview) { mutableStateOf(false) } // al entrar o salir de "Ver como" se vuelve al inicio
    var openModule by remember(me.isPreview) { mutableStateOf<String?>(null) }
    var openScanner by remember(me.isPreview) { mutableStateOf(false) }
    var showShifts by remember(me.isPreview) { mutableStateOf(false) }
    var logbookPage by remember(me.isPreview) { mutableStateOf<String?>(null) } // "cerrar" (desde Terminar turno) o "recibir"

    val snackbar = remember { SnackbarHostState() }
    val scope = rememberCoroutineScope()
    val haptics = rememberHaptics()
    val unread by vm.unread.collectAsStateWithLifecycle()
    val openAvisos by vm.openAvisos.collectAsStateWithLifecycle()
    val openShift by vm.openShift.collectAsStateWithLifecycle()
    val panic by vm.panic.collectAsStateWithLifecycle()
    // La pantalla de pánico que se está yendo sigue visible durante la transición aunque el estado ya sea null.
    val lastPanic = remember { mutableStateOf<SessionViewModel.PanicState?>(null) }
    if (panic != null) lastPanic.value = panic
    val nextRound = vm.nextScheduledRound()
    androidx.compose.runtime.LaunchedEffect(openShift) {
        if (openShift) { openModule = null; showSettings = false; showShifts = false; vm.openShift.value = false }
    }
    val notifPermission = androidx.activity.compose.rememberLauncherForActivityResult(
        androidx.activity.result.contract.ActivityResultContracts.RequestPermission()) { }
    androidx.compose.runtime.LaunchedEffect(Unit) {
        if (android.os.Build.VERSION.SDK_INT >= 33) notifPermission.launch(android.Manifest.permission.POST_NOTIFICATIONS)
    }

    // Una sola "ruta" visible: las barras de arriba y abajo quedan fijas y solo el contenido se anima.
    val route = when {
        showShifts -> "shifts"
        showSettings -> "settings"
        openAvisos -> "avisos"
        openModule != null -> "m:$openModule"
        panic != null -> "panic"
        else -> "home"
    }
    val selected = when (route) { "home", "panic" -> "home"; "avisos" -> "notices"; "settings" -> "more"; else -> "activity" }

    /** Sale de la pantalla actual (con lo que hacía cada una al volver) y deja el inicio. */
    fun leave() {
        if (openAvisos) { vm.openAvisos.value = false; vm.refreshUnread() }
        if (openModule != null) vm.refreshCounts()
        if (route == "panic") vm.closePanic()
        openModule = null; showSettings = false; showShifts = false; openScanner = false; logbookPage = null
    }
    val goHome = { leave() }
    val goActivity = { if (route == "m:rondas") openScanner = false else { leave(); openModule = "rondas" } }
    val dutyName by vm.dutyName.collectAsStateWithLifecycle()
    /** Celular compartido: rondas y libro quedan a nombre del guardia de turno, así que primero alguien tiene que empezar. */
    val needsDuty = { m: String -> me.sharedDevice && !me.isPreview && dutyName == null && m in setOf("rondas", "libro") }
    val askDuty = { scope.launch { snackbar.showSnackbar("Primero empezá tu turno (Empezar mi turno): así queda a tu nombre.") }; Unit }
    val goScan = { haptics.tick(); if (needsDuty("rondas")) askDuty() else if (route == "m:rondas") openScanner = true else { leave(); openModule = "rondas"; openScanner = true } }
    val goNotices = { val was = route == "avisos"; leave(); if (!was) vm.openAvisos.value = true }
    val goMore = { val was = route == "settings"; leave(); if (!was) showSettings = true }

    val back = rememberBackProgress()
    Scaffold(snackbarHost = { SnackbarHost(snackbar) }, containerColor = MaterialTheme.colorScheme.background, topBar = {
        HomeTopBar(vm, me, unread, sync.running, onNotices = goNotices, onSettings = goMore)
    }, bottomBar = {
        HomeBottomBar(selected = selected, onHome = goHome, onActivity = goActivity, onScan = goScan, onNotices = goNotices, onMore = goMore)
    }) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            if (me.isPreview) PreviewBanner(me) { vm.exitPreview() }
            androidx.compose.runtime.CompositionLocalProvider(LocalBackProgress provides back) {
                androidx.compose.animation.AnimatedContent(
                    targetState = route,
                    transitionSpec = { routeTransition(::routeDepth) },
                    label = "pantalla",
                    modifier = Modifier.fillMaxWidth().weight(1f).predictiveBack(back),
                ) { r ->
                    Box(Modifier.fillMaxSize().background(MaterialTheme.colorScheme.background)) {
                        when (r) {
                            "shifts" -> ar.com.securityapp.campo.ui.shifts.ShiftsScreen(onBack = goHome, showTopBar = false)
                            "settings" -> SettingsScreen(vm, me, onBack = goHome, showTopBar = false)
                            "avisos" -> AvisosScreen(onBack = goHome, showTopBar = false)
                            "m:rondas" -> ar.com.securityapp.campo.ui.rounds.RondasScreen(onBack = goHome, canManagePoints = me.can("rondas", "editar"), initialScan = openScanner, showTopBar = false)
                            "m:acciones" -> ar.com.securityapp.campo.ui.actions.AccionesScreen(onBack = goHome, showTopBar = false)
                            "m:inspecciones" -> ar.com.securityapp.campo.ui.inspections.InspeccionesScreen(onBack = goHome, canCreate = me.can("inspecciones", "crear"), showTopBar = false)
                            "m:incidentes" -> ar.com.securityapp.campo.ui.incidents.IncidentesScreen(onBack = goHome, canCreate = me.can("incidentes", "crear"), showTopBar = false)
                            "m:libro" -> ar.com.securityapp.campo.ui.logbook.LogbookScreen(onBack = goHome, canCreate = me.can("libro", "crear"),
                                showTopBar = false, startPage = logbookPage, onClosed = goHome)
                            "m:observaciones" -> ar.com.securityapp.campo.ui.observations.ObservacionesScreen(onBack = goHome, canCreate = me.can("observaciones", "crear"), showTopBar = false)
                            "panic" -> ar.com.securityapp.campo.panic.PanicScreen((lastPanic.value as? SessionViewModel.PanicState.Done)?.result, onClose = { vm.closePanic() })
                            "home" -> HomeContent(vm, me, sync, update, nextRound, snackbar, openIntent,
                                onShifts = { showShifts = true }, onLogbook = { page -> logbookPage = page; openModule = "libro" },
                                onRounds = { if (needsDuty("rondas")) askDuty() else openModule = "rondas" },
                                onModule = { if (needsDuty(it)) askDuty() else openModule = it })
                        }
                    }
                }
            }
        }
    }
}

/** Inicio: actualización, turno, pánico, próxima ronda, sincronización y módulos. Deslizar hacia abajo sincroniza. */
@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
private fun HomeContent(
    vm: SessionViewModel, me: Me, sync: SyncState, update: UpdateState, nextRound: SessionViewModel.ScheduledRound?,
    snackbar: SnackbarHostState, openIntent: (Intent) -> Unit,
    onShifts: () -> Unit, onLogbook: (String?) -> Unit, onRounds: () -> Unit, onModule: (String) -> Unit,
) {
    val scope = rememberCoroutineScope()
    val haptics = rememberHaptics()
    var pulled by remember { mutableStateOf(false) }
    // El responsable de una acción la ve aunque su rol no tenga el módulo (como en la web).
    val modules = MODULES.filter { me.can(it.key) || (it.key == "acciones" && (sync.counts["actions"] ?: 0) > 0) }
    fun refresh() {
        haptics.tick()
        pulled = true
        scope.launch {
            vm.syncNow()
            kotlinx.coroutines.withTimeoutOrNull(2_000) { vm.sync.first { it.running } }
            val done = vm.sync.first { !it.running }
            pulled = false
            if (done.error) {
                haptics.reject()
                val r = snackbar.showSnackbar(done.message ?: "No se pudo sincronizar.", actionLabel = "Reintentar",
                    duration = androidx.compose.material3.SnackbarDuration.Long)
                if (r == androidx.compose.material3.SnackbarResult.ActionPerformed) refresh()
            } else {
                snackbar.showSnackbar(if (done.pending > 0) "Sincronizado · ${done.pending} envío(s) esperando señal" else "Todo sincronizado")
            }
        }
    }
    Column(Modifier.fillMaxSize()) {
        val previewState by vm.preview.collectAsStateWithLifecycle()
        previewState.notice?.let { n ->
            Card(Modifier.fillMaxWidth().padding(start = 16.dp, end = 16.dp, top = 12.dp), colors = CardDefaults.cardColors(containerColor = WarnBg)) {
                Row(Modifier.padding(start = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                    Text(n, style = MaterialTheme.typography.bodySmall, color = BrandDark, modifier = Modifier.weight(1f))
                    androidx.compose.material3.TextButton({ vm.clearPreviewNotice() }) { Text("OK") }
                }
            }
        }
        androidx.compose.material3.pulltorefresh.PullToRefreshBox(isRefreshing = pulled, onRefresh = ::refresh, modifier = Modifier.fillMaxWidth().weight(1f)) {
            LazyVerticalGrid(
                columns = GridCells.Fixed(2),
                contentPadding = PaddingValues(16.dp),
                horizontalArrangement = Arrangement.spacedBy(12.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
                modifier = Modifier.fillMaxSize(),
            ) {
                update.release?.let { release ->
                    item(span = { GridItemSpan(2) }) { UpdateCard(release.versionName, update, onInstall = { vm.installUpdate(openIntent) }) }
                }
                if (me.sharedDevice) {
                    // Celular compartido del puesto: quién está de turno, empezar con nombre y PIN, cambio de guardia, terminar.
                    item(span = { GridItemSpan(2) }) { ar.com.securityapp.campo.ui.shifts.PostDutyCard() }
                    item(span = { GridItemSpan(2) }) { ar.com.securityapp.campo.panic.PanicButton(onTrigger = { vm.triggerPanic() }) }
                } else if (me.can("rondas", "crear")) {
                    item(span = { GridItemSpan(2) }) { ar.com.securityapp.campo.ui.shifts.ShiftCard(onOpen = onShifts,
                        onLogbook = if (me.can("libro", "crear")) ({ page -> onLogbook(page) }) else null) }
                    item(span = { GridItemSpan(2) }) { ar.com.securityapp.campo.panic.PanicButton(onTrigger = { vm.triggerPanic() }) }
                }
                if (nextRound != null && me.can("rondas")) {
                    item(span = { GridItemSpan(2) }) { ScheduledRoundCard(nextRound, onRounds) }
                }
                item(span = { GridItemSpan(2) }) { SyncCard(sync) }
                item(span = { GridItemSpan(2) }) {
                    Text("Qué podés hacer", style = MaterialTheme.typography.titleSmall, color = Muted, modifier = Modifier.padding(top = 4.dp))
                }
                items(modules, key = { it.key }) { m ->
                    ModuleTile(m, ready = m.key in READY) {
                        if (m.key in READY) onModule(m.key)
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
            // Celular compartido del puesto: arriba el guardia de turno (todo queda a su nombre) y abajo el celular.
            val duty = if (me.sharedDevice) vm.dutyName.collectAsStateWithLifecycle().value else null
            Text(duty ?: if (me.sharedDevice) "Nadie de turno" else me.name, color = Color.White, style = MaterialTheme.typography.titleMedium,
                fontWeight = FontWeight.Bold, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(if (me.sharedDevice) "${if (duty != null) "De turno · " else ""}${me.name}" else me.role, color = Color(0xFFB4BACB),
                style = MaterialTheme.typography.bodySmall, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        IconButton(onNotices) {
            androidx.compose.material3.BadgedBox(badge = { if (unread > 0) androidx.compose.material3.Badge(containerColor = Accent, contentColor = OnAccent) { Text(if (unread > 99) "99+" else "$unread") } }) {
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
    // Pestaña elegida: píldora amarilla de la marca (se anima sola al cambiar) y texto fuerte; las demás en gris.
    val itemColors = androidx.compose.material3.NavigationBarItemDefaults.colors(selectedIconColor = OnAccent, indicatorColor = Accent,
        selectedTextColor = MaterialTheme.colorScheme.onSurface, unselectedIconColor = Muted, unselectedTextColor = Muted)
    Box(Modifier.fillMaxWidth().background(Color.Black).navigationBarsPadding().zIndex(10f)) {
        NavigationBar(containerColor = BarBg, tonalElevation = 0.dp, windowInsets = WindowInsets(0, 0, 0, 0),
            modifier = Modifier.fillMaxWidth().height(80.dp).align(Alignment.BottomCenter).zIndex(1f)) {
            NavigationBarItem(selected = selected == "home", onClick = onHome, icon = { Icon(Icons.Filled.Home, "Inicio") }, label = { FitText("Inicio", style = MaterialTheme.typography.labelMedium) }, colors = itemColors)
            NavigationBarItem(selected = selected == "activity", onClick = onActivity, icon = { Icon(Icons.Filled.CheckCircle, "Actividad") }, label = { FitText("Actividad", style = MaterialTheme.typography.labelMedium) }, colors = itemColors)
            Spacer(Modifier.weight(1f))
            NavigationBarItem(selected = selected == "notices", onClick = onNotices, icon = { Icon(Icons.Filled.Notifications, "Avisos") }, label = { FitText("Avisos", style = MaterialTheme.typography.labelMedium) }, colors = itemColors)
            NavigationBarItem(selected = selected == "more", onClick = onMore, icon = { Icon(Icons.Filled.Menu, "Más") }, label = { FitText("Más", style = MaterialTheme.typography.labelMedium) }, colors = itemColors)
        }
        // Botón central de escaneo: círculo amarillo que sobresale de la barra (borde del color de la barra para el "recorte"),
        // ícono estándar de escanear QR en blanco y la palabra "Escanear" alineada con las otras pestañas.
        val interaction = remember { androidx.compose.foundation.interaction.MutableInteractionSource() }
        Box(Modifier.align(Alignment.TopCenter).offset(y = (-26).dp).size(68.dp).zIndex(2f).pressScale(interaction)
            .shadow(8.dp, androidx.compose.foundation.shape.CircleShape, ambientColor = Color.Black, spotColor = Color.Black)
            .background(BarBg, androidx.compose.foundation.shape.CircleShape).padding(4.dp)
            .background(Accent, androidx.compose.foundation.shape.CircleShape)
            .clip(androidx.compose.foundation.shape.CircleShape)
            .clickable(interactionSource = interaction, indication = androidx.compose.material3.ripple(color = Color.White), onClick = onScan)
            .semantics { role = androidx.compose.ui.semantics.Role.Button },
            contentAlignment = Alignment.Center) {
            Icon(painterResource(R.drawable.ic_qr_scan), "Escanear QR", Modifier.size(32.dp), tint = Color.White)
        }
        FitText("Escanear", style = MaterialTheme.typography.labelMedium.copy(color = MaterialTheme.colorScheme.onSurface, fontWeight = FontWeight.SemiBold),
            modifier = Modifier.align(Alignment.BottomCenter).padding(bottom = 16.dp).width(76.dp).zIndex(2f))
    }
}

/** "Ver como": siempre visible arriba, con la salida a mano. */
@Composable
private fun PreviewBanner(me: Me, onExit: () -> Unit) {
    Row(Modifier.fillMaxWidth().background(Accent).padding(start = 16.dp, end = 4.dp, top = 6.dp, bottom = 6.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text("VISTA PREVIA · ${me.name}", color = OnAccent, fontWeight = FontWeight.Bold, style = MaterialTheme.typography.labelLarge,
                maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text("Rol ${me.role}. Solo lectura: las acciones de turnos y rondas están bloqueadas.", color = OnAccent,
                style = MaterialTheme.typography.bodySmall)
        }
        androidx.compose.material3.Button(onExit, colors = androidx.compose.material3.ButtonDefaults.buttonColors(containerColor = OnAccent, contentColor = Color.White)) { Text("Salir") }
    }
}

@Composable
private fun ModuleTile(m: Module, ready: Boolean, onClick: () -> Unit) {
    val interaction = remember { androidx.compose.foundation.interaction.MutableInteractionSource() }
    Card(Modifier.fillMaxWidth().height(128.dp).pressScale(interaction)
        .clickable(interactionSource = interaction, indication = androidx.compose.material3.ripple(), onClick = onClick), colors = CardDefaults.cardColors(containerColor = CardBg),
        elevation = CardDefaults.cardElevation(defaultElevation = 1.dp)) {
        Column(Modifier.fillMaxSize().padding(14.dp), verticalArrangement = Arrangement.SpaceBetween) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(42.dp).background(if (ready) Accent else NeutralBg, RoundedCornerShape(12.dp)), contentAlignment = Alignment.Center) {
                    Icon(m.icon, null, tint = if (ready) OnAccent else Muted)
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
    Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = CardBg)) {
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

@Composable
private fun ScheduledRoundCard(round: SessionViewModel.ScheduledRound, onOpen: () -> Unit) {
    val now = java.time.Instant.now()
    val minutes = java.time.Duration.between(now, round.slot.dueAt).toMinutes()
    val whenText = when {
        minutes <= 0 -> "Es la hora de empezar"
        minutes < 60 -> "Empieza en $minutes min"
        else -> "Empieza en ${minutes / 60} h ${minutes % 60} min"
    }
    Card(Modifier.fillMaxWidth().clickable(onClick = onOpen), colors = CardDefaults.cardColors(containerColor = WarnBg)) {
        Row(Modifier.padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("Próxima ronda", style = MaterialTheme.typography.labelLarge, color = BrandDark)
                Text(round.routeName, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                Text(whenText, style = MaterialTheme.typography.bodyMedium, color = BrandDark)
            }
            Button(onOpen, colors = ButtonDefaults.buttonColors(containerColor = Brand, contentColor = androidx.compose.ui.graphics.Color.White)) { Text("Preparar") }
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
    Card(Modifier.fillMaxWidth(), colors = CardDefaults.cardColors(containerColor = WarnBg)) {
        Column(Modifier.padding(16.dp)) {
            Text("Hay una versión nueva ($versionName)", fontWeight = FontWeight.SemiBold, color = BrandDark)
            update.release?.notes?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = BrandDark) }
            update.error?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = Danger, modifier = Modifier.padding(top = 4.dp)) }
            if (update.downloading) {
                LinearProgressIndicator(progress = { update.progress }, modifier = Modifier.fillMaxWidth().padding(top = 8.dp), color = Ink)
            } else {
                Button(onInstall, Modifier.padding(top = 8.dp), colors = ButtonDefaults.buttonColors(containerColor = Brand, contentColor = androidx.compose.ui.graphics.Color.White)) { Text("Actualizar") }
            }
        }
    }
}
