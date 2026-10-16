package ar.com.securityapp.campo.ui.rounds

import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Card
import androidx.compose.material3.CardDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.unit.dp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import ar.com.securityapp.campo.data.PatrolPoint
import ar.com.securityapp.campo.data.PatrolRoute
import ar.com.securityapp.campo.ui.theme.Muted
import com.google.android.gms.maps.CameraUpdateFactory
import com.google.android.gms.maps.GoogleMap
import com.google.android.gms.maps.MapView
import com.google.android.gms.maps.model.LatLng
import com.google.android.gms.maps.model.LatLngBounds
import com.google.android.gms.maps.model.MarkerOptions
import com.google.android.gms.maps.model.PolylineOptions

/** Mapa de la ruta: los puntos vienen de la sincronización y no requiere geocodificación. */
@Composable
fun RouteMapCard(route: PatrolRoute, points: Map<String, PatrolPoint>) {
    val mapped = route.points.mapNotNull { points[it] }.filter { it.lat != null && it.lng != null }
    val missing = route.points.size - mapped.size
    var satellite by remember(route.uuid) { mutableStateOf(false) }
    Card(colors = CardDefaults.cardColors(containerColor = androidx.compose.ui.graphics.Color.White)) {
        Column(Modifier.fillMaxWidth().padding(12.dp)) {
            Row(Modifier.fillMaxWidth()) {
                Text("Mapa de la ruta", modifier = Modifier.weight(1f), color = Muted)
                TextButton({ satellite = !satellite }, enabled = mapped.isNotEmpty()) {
                    Text(if (satellite) "Calles" else "Satélite")
                }
            }
            if (mapped.isEmpty()) {
                Text("Los puntos todavía no tienen coordenadas para mostrar en el mapa.", color = Muted)
            } else {
                GoogleRouteMap(mapped, satellite, Modifier.fillMaxWidth().height(240.dp))
                if (missing > 0) Text("$missing punto(s) sin coordenadas", color = Muted, modifier = Modifier.padding(top = 6.dp))
            }
        }
    }
}

@Composable
private fun GoogleRouteMap(points: List<PatrolPoint>, satellite: Boolean, modifier: Modifier = Modifier) {
    val context = LocalContext.current
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    val mapView = remember { MapView(context).also { it.onCreate(null) } }
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
            if (!destroyed) { destroyed = true; mapView.onDestroy() }
        }
    }
    AndroidView(factory = { mapView }, modifier = modifier) { view ->
        view.getMapAsync { ready -> map = ready }
    }
    LaunchedEffect(map, points, satellite) {
        val ready = map ?: return@LaunchedEffect
        ready.mapType = if (satellite) GoogleMap.MAP_TYPE_SATELLITE else GoogleMap.MAP_TYPE_NORMAL
        ready.clear()
        val coordinates = points.map { LatLng(it.lat!!, it.lng!!) }
        coordinates.forEachIndexed { index, latLng ->
            ready.addMarker(MarkerOptions().position(latLng).title("${index + 1}. ${points[index].name}"))
        }
        if (coordinates.size == 1) {
            ready.moveCamera(CameraUpdateFactory.newLatLngZoom(coordinates.first(), 17f))
        } else {
            val bounds = LatLngBounds.builder().apply { coordinates.forEach { include(it) } }.build()
            ready.moveCamera(CameraUpdateFactory.newLatLngBounds(bounds, 48))
        }
        if (coordinates.size > 1) ready.addPolyline(PolylineOptions().addAll(coordinates).width(8f).color(0xFF374151.toInt()))
    }
}
