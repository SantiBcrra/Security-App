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

/**
 * Mapa de la ruta (siempre satelital, con nombres de calles: decisión del usuario). Se puede mover, acercar y alejar con los
 * dedos: mientras se toca el mapa, la lista que lo contiene no se desplaza (`onTouching`). Botones +/−, "Centrar ruta" y
 * "Mi ubicación" (si hay permiso).
 */
@Composable
fun RouteMapCard(route: PatrolRoute, points: Map<String, PatrolPoint>, onTouching: (Boolean) -> Unit = {}) {
    val mapped = route.points.mapNotNull { points[it] }.filter { it.lat != null && it.lng != null }
    val missing = route.points.size - mapped.size
    var recenter by remember(route.uuid) { mutableIntStateOf(0) }
    // Un poco más ancho que el resto de las tarjetas (≈ 95 % de la pantalla).
    Card(Modifier.widen(10.dp), colors = CardDefaults.cardColors(containerColor = androidx.compose.ui.graphics.Color.White)) {
        Column(Modifier.fillMaxWidth().padding(vertical = 8.dp)) {
            Row(Modifier.fillMaxWidth().padding(horizontal = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                Text("Mapa de la ruta", modifier = Modifier.weight(1f), color = Muted)
                if (mapped.isNotEmpty()) TextButton({ recenter++ }) { Text("Centrar ruta") }
            }
            if (mapped.isEmpty()) {
                Text("Los puntos todavía no tienen coordenadas para mostrar en el mapa.", color = Muted, modifier = Modifier.padding(12.dp))
            } else {
                GoogleRouteMap(mapped, recenter, onTouching, Modifier.fillMaxWidth().height(380.dp))
                Text("Mové el mapa con un dedo y acercá o alejá con dos." + if (missing > 0) " $missing punto(s) sin coordenadas." else "",
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
private fun GoogleRouteMap(points: List<PatrolPoint>, recenter: Int, onTouching: (Boolean) -> Unit, modifier: Modifier = Modifier) {
    val context = LocalContext.current
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    val touching by rememberUpdatedState(onTouching)
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
            }
            map = ready
        }
    }
    LaunchedEffect(map, points) {
        val ready = map ?: return@LaunchedEffect
        ready.clear()
        val coordinates = points.map { LatLng(it.lat!!, it.lng!!) }
        if (coordinates.size > 1) {
            ready.addPolyline(PolylineOptions().addAll(coordinates).width(14f).color(0x99000000.toInt())) // borde para que se vea sobre el satélite
            ready.addPolyline(PolylineOptions().addAll(coordinates).width(7f).color(0xFFF2C014.toInt()))
        }
        coordinates.forEachIndexed { index, latLng ->
            ready.addMarker(MarkerOptions().position(latLng).title("${index + 1}. ${points[index].code} · ${points[index].name}")
                .icon(BitmapDescriptorFactory.defaultMarker(if (points[index].critical) BitmapDescriptorFactory.HUE_RED else BitmapDescriptorFactory.HUE_YELLOW)))
        }
        fit(ready, coordinates, animate = false)
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
