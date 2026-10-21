package ar.com.securityapp.campo.ui.rounds

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.view.MotionEvent
import android.widget.FrameLayout
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.layout
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import ar.com.securityapp.campo.data.PatrolPoint
import ar.com.securityapp.campo.data.PatrolRoute
import ar.com.securityapp.campo.ui.theme.Muted
import com.google.android.gms.maps.CameraUpdateFactory
import com.google.android.gms.maps.GoogleMap
import com.google.android.gms.maps.MapView
import com.google.android.gms.maps.model.BitmapDescriptorFactory
import com.google.android.gms.maps.model.LatLng
import com.google.android.gms.maps.model.LatLngBounds
import com.google.android.gms.maps.model.MarkerOptions
import com.google.android.gms.maps.model.PolylineOptions

/** Ronda en curso sobre el mapa: qué puntos ya se marcaron, cuál toca y el recorrido guardado en el celular. */
data class LiveRound(val roundUuid: String, val scanned: Set<String>, val next: String?)

/**
 * Mapa de la ruta (siempre satelital, con nombres de calles: decisión del usuario). Se puede mover, acercar y alejar con los
 * dedos: mientras se toca el mapa, la lista que lo contiene no se desplaza (`onTouching`). Botones +/−, "Centrar ruta" y
 * "Mi ubicación" (si hay permiso).
 */
@Composable
fun RouteMapCard(route: PatrolRoute, points: Map<String, PatrolPoint>, onTouching: (Boolean) -> Unit = {}, live: LiveRound? = null) {
    val mapped = route.points.mapNotNull { points[it] }.filter { it.lat != null && it.lng != null }
    val missing = route.points.size - mapped.size
    var recenter by remember(route.uuid) { mutableIntStateOf(0) }
    // Durante la ronda el mapa sigue al guardia; si mueve el mapa con el dedo deja de seguirlo hasta tocar "Seguirme".
    var follow by remember(live?.roundUuid) { mutableStateOf(live != null) }
    // Un poco más ancho que el resto de las tarjetas (≈ 95 % de la pantalla).
    Card(Modifier.widen(10.dp), colors = CardDefaults.cardColors(containerColor = androidx.compose.ui.graphics.Color.White)) {
        Column(Modifier.fillMaxWidth().padding(vertical = 8.dp)) {
            Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(if (live != null) "Ronda en curso" else "Mapa de la ruta", modifier = Modifier.weight(1f), color = Muted)
                if (live != null) TextButton({ follow = !follow }) { Text(if (follow) "◉ Siguiéndote" else "Seguirme") }
                if (mapped.isNotEmpty()) TextButton({ follow = false; recenter++ }) { Text("Ver ruta") }
            }
            if (mapped.isEmpty()) {
                Text("Los puntos todavía no tienen coordenadas para mostrar en el mapa.", color = Muted, modifier = Modifier.padding(12.dp))
            } else {
                GoogleRouteMap(mapped, recenter, onTouching, live, follow, onStopFollow = { follow = false }, modifier = Modifier.fillMaxWidth().height(380.dp))
                Text((if (live != null) "Verde = marcado · celeste = el que sigue · amarillo = falta. Línea azul = tu recorrido. " else "") +
                    "Mové el mapa con un dedo y acercá o alejá con dos." + if (missing > 0) " $missing punto(s) sin coordenadas." else "",
                    color = Muted, style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(horizontal = 12.dp, vertical = 6.dp))
            }
        }
    }
}

/** Ensancha el elemento `extra` de cada lado, por encima del margen de la lista. */
private fun Modifier.widen(extra: Dp) = layout { measurable, constraints ->
    val px = extra.roundToPx()
    val placeable = measurable.measure(constraints.copy(minWidth = constraints.maxWidth + 2 * px, maxWidth = constraints.maxWidth + 2 * px))
    layout(constraints.maxWidth, placeable.height) { placeable.place(-px, 0) }
}

/** Contenedor que, al tocarlo, le pide a la lista que no se quede con el gesto (así el mapa se mueve y hace zoom). */
private class TouchFrame(context: Context, private val onTouching: (Boolean) -> Unit) : FrameLayout(context) {
    override fun dispatchTouchEvent(ev: MotionEvent): Boolean {
        when (ev.actionMasked) {
            MotionEvent.ACTION_DOWN -> { parent?.requestDisallowInterceptTouchEvent(true); onTouching(true) }
            MotionEvent.ACTION_UP, MotionEvent.ACTION_CANCEL -> { parent?.requestDisallowInterceptTouchEvent(false); onTouching(false) }
        }
        return super.dispatchTouchEvent(ev)
    }
}

@SuppressLint("MissingPermission")
@Composable
private fun GoogleRouteMap(points: List<PatrolPoint>, recenter: Int, onTouching: (Boolean) -> Unit, live: LiveRound?, follow: Boolean,
                           onStopFollow: () -> Unit, modifier: Modifier = Modifier) {
    val context = LocalContext.current
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    val touching by rememberUpdatedState(onTouching)
    val stopFollow by rememberUpdatedState(onStopFollow)
    val following by rememberUpdatedState(follow)
    val mapView = remember { MapView(context).also { it.onCreate(null) } }
    val frame = remember { TouchFrame(context) { touching(it) }.also { it.addView(mapView) } }
    var map by remember { mutableStateOf<GoogleMap?>(null) }
    var destroyed by remember { mutableStateOf(false) }

    DisposableEffect(lifecycle, mapView) {
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_START -> mapView.onStart()
                Lifecycle.Event.ON_RESUME -> mapView.onResume()
                Lifecycle.Event.ON_PAUSE -> mapView.onPause()
                Lifecycle.Event.ON_STOP -> mapView.onStop()
                Lifecycle.Event.ON_DESTROY -> if (!destroyed) { destroyed = true; mapView.onDestroy() }
                else -> Unit
            }
        }
        lifecycle.addObserver(observer)
        onDispose {
            lifecycle.removeObserver(observer)
            touching(false)
            if (!destroyed) { destroyed = true; mapView.onDestroy() }
        }
    }
    AndroidView(factory = { frame }, modifier = modifier) {
        mapView.getMapAsync { ready ->
            if (map == null) {
                ready.mapType = GoogleMap.MAP_TYPE_HYBRID // satélite con nombres de calles; no se puede cambiar
                ready.uiSettings.apply {
                    isZoomGesturesEnabled = true; isScrollGesturesEnabled = true; isRotateGesturesEnabled = true; isTiltGesturesEnabled = false
                    isZoomControlsEnabled = true; isCompassEnabled = true; isMapToolbarEnabled = false
                }
                if (ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) == PackageManager.PERMISSION_GRANTED) {
                    ready.isMyLocationEnabled = true
                    ready.uiSettings.isMyLocationButtonEnabled = true
                }
                // Si el guardia arrastra o hace zoom con los dedos, deja de seguirlo (lo retoma con "Seguirme").
                ready.setOnCameraMoveStartedListener { reason -> if (reason == GoogleMap.OnCameraMoveStartedListener.REASON_GESTURE) stopFollow() }
            }
            map = ready
        }
    }
    val db = remember { (context.applicationContext as ar.com.securityapp.campo.CampoApp).container.db }
    var track by remember(live?.roundUuid) { mutableStateOf<List<LatLng>>(emptyList()) }
    var trackLine by remember { mutableStateOf<com.google.android.gms.maps.model.Polyline?>(null) }
    var fitted by remember { mutableStateOf(false) }
    // Recorrido guardado por el servicio de la ronda: se relee cada 10 s.
    LaunchedEffect(live?.roundUuid) {
        val uuid = live?.roundUuid ?: return@LaunchedEffect
        while (true) {
            track = db.trackOf(uuid).map { LatLng(it.first, it.second) }
            kotlinx.coroutines.delay(10_000)
        }
    }
    LaunchedEffect(map, track) {
        val ready = map ?: return@LaunchedEffect
        trackLine?.remove()
        trackLine = if (track.size > 1) ready.addPolyline(PolylineOptions().addAll(track).width(9f).color(0xFF2D9CDB.toInt()).zIndex(2f)) else null
    }
    // Seguimiento: ubicación cada 3 s mientras el mapa está en pantalla durante la ronda.
    DisposableEffect(live?.roundUuid, map) {
        val ready = map
        if (live == null || ready == null || ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) != PackageManager.PERMISSION_GRANTED) {
            return@DisposableEffect onDispose { }
        }
        val client = com.google.android.gms.location.LocationServices.getFusedLocationProviderClient(context)
        val callback = object : com.google.android.gms.location.LocationCallback() {
            override fun onLocationResult(result: com.google.android.gms.location.LocationResult) {
                val loc = result.lastLocation ?: return
                if (following) runCatching {
                    ready.animateCamera(CameraUpdateFactory.newLatLngZoom(LatLng(loc.latitude, loc.longitude), maxOf(ready.cameraPosition.zoom, 18f)), 600, null)
                }
            }
        }
        val req = com.google.android.gms.location.LocationRequest.Builder(com.google.android.gms.location.Priority.PRIORITY_HIGH_ACCURACY, 3_000L)
            .setMinUpdateIntervalMillis(2_000L).build()
        client.requestLocationUpdates(req, callback, android.os.Looper.getMainLooper())
        onDispose { client.removeLocationUpdates(callback) }
    }
    LaunchedEffect(map, points, live?.scanned, live?.next) {
        val ready = map ?: return@LaunchedEffect
        ready.clear()
        trackLine = null
        val coordinates = points.map { LatLng(it.lat!!, it.lng!!) }
        if (coordinates.size > 1) {
            ready.addPolyline(PolylineOptions().addAll(coordinates).width(14f).color(0x99000000.toInt())) // borde para que se vea sobre el satélite
            ready.addPolyline(PolylineOptions().addAll(coordinates).width(7f).color(0xFFF2C014.toInt()))
        }
        coordinates.forEachIndexed { index, latLng ->
            val p = points[index]
            val hue = when {
                live != null && p.uuid in live.scanned -> BitmapDescriptorFactory.HUE_GREEN
                live != null && p.uuid == live.next -> BitmapDescriptorFactory.HUE_AZURE
                p.critical -> BitmapDescriptorFactory.HUE_RED
                else -> BitmapDescriptorFactory.HUE_YELLOW
            }
            ready.addMarker(MarkerOptions().position(latLng).title("${index + 1}. ${p.code} · ${p.name}" + if (live != null && p.uuid in live.scanned) " ✓" else "")
                .icon(BitmapDescriptorFactory.defaultMarker(hue)))
        }
        if (track.size > 1) trackLine = ready.addPolyline(PolylineOptions().addAll(track).width(9f).color(0xFF2D9CDB.toInt()).zIndex(2f))
        if (!fitted) { fit(ready, coordinates, animate = false); fitted = true } // solo la primera vez: al marcar un punto no salta la vista
    }
    LaunchedEffect(recenter) {
        if (recenter > 0) map?.let { fit(it, points.map { p -> LatLng(p.lat!!, p.lng!!) }, animate = true) }
    }
}

private fun fit(map: GoogleMap, coordinates: List<LatLng>, animate: Boolean) {
    if (coordinates.isEmpty()) return
    val update = if (coordinates.size == 1) CameraUpdateFactory.newLatLngZoom(coordinates.first(), 18f)
    else CameraUpdateFactory.newLatLngBounds(LatLngBounds.builder().apply { coordinates.forEach { include(it) } }.build(), 72)
    runCatching { if (animate) map.animateCamera(update) else map.moveCamera(update) }
}
