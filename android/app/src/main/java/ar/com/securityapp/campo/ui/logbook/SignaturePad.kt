package ar.com.securityapp.campo.ui.logbook

import android.graphics.Bitmap
import android.util.Base64
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.gestures.detectDragGestures
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.onSizeChanged
import androidx.compose.ui.unit.IntSize
import androidx.compose.ui.unit.dp
import ar.com.securityapp.campo.ui.theme.Muted
import java.io.ByteArrayOutputStream

/** Firma con el dedo. Guarda los trazos; `toDataUrl()` arma el PNG (fondo transparente) que valida el servidor. */
class SignatureState {
    internal val strokes = mutableStateListOf<List<Offset>>()
    internal var current by mutableStateOf<List<Offset>>(emptyList())
    internal var size by mutableStateOf(IntSize.Zero)
    internal var version by mutableIntStateOf(0)

    val isEmpty get() = strokes.sumOf { it.size } < 8

    fun clear() { strokes.clear(); current = emptyList(); version++ }

    /** "data:image/png;base64,…" o null si está vacía. */
    fun toDataUrl(): String? {
        if (isEmpty || size.width == 0) return null
        val bmp = Bitmap.createBitmap(size.width, size.height, Bitmap.Config.ARGB_8888)
        val canvas = android.graphics.Canvas(bmp)
        val paint = android.graphics.Paint().apply {
            color = android.graphics.Color.rgb(20, 20, 30); strokeWidth = 5f; style = android.graphics.Paint.Style.STROKE
            strokeCap = android.graphics.Paint.Cap.ROUND; strokeJoin = android.graphics.Paint.Join.ROUND; isAntiAlias = true
        }
        for (s in strokes) {
            if (s.size == 1) canvas.drawPoint(s[0].x, s[0].y, paint)
            for (i in 1 until s.size) canvas.drawLine(s[i - 1].x, s[i - 1].y, s[i].x, s[i].y, paint)
        }
        val out = ByteArrayOutputStream()
        bmp.compress(Bitmap.CompressFormat.PNG, 100, out)
        return "data:image/png;base64," + Base64.encodeToString(out.toByteArray(), Base64.NO_WRAP)
    }
}

/** Un trazo que sale del recuadro queda en el borde (no se dibuja afuera ni en el PNG). */
private fun clamp(p: Offset, size: IntSize) = Offset(p.x.coerceIn(0f, size.width.toFloat()), p.y.coerceIn(0f, size.height.toFloat()))

@Composable
fun SignaturePad(state: SignatureState, label: String = "Tu firma") {
    Column {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Text(label, style = MaterialTheme.typography.labelLarge, modifier = Modifier.weight(1f))
            TextButton({ state.clear() }) { Text("Borrar") }
        }
        Canvas(Modifier.fillMaxWidth().height(170.dp).background(Color.White, RoundedCornerShape(8.dp)).border(1.dp, Color(0xFFCED4DA), RoundedCornerShape(8.dp))
            .clipToBounds()
            .onSizeChanged { state.size = it }
            .pointerInput(Unit) {
                detectDragGestures(
                    onDragStart = { state.current = listOf(clamp(it, size)) },
                    onDrag = { change, _ -> state.current = state.current + clamp(change.position, size) },
                    onDragEnd = { state.strokes.add(state.current); state.current = emptyList() },
                    onDragCancel = { state.strokes.add(state.current); state.current = emptyList() },
                )
            }) {
            state.version.let { } // redibuja al borrar
            for (s in state.strokes + listOf(state.current)) {
                for (i in 1 until s.size) drawLine(Color(0xFF14141E), s[i - 1], s[i], strokeWidth = 5f, cap = StrokeCap.Round)
            }
        }
        Text("Firmá con el dedo dentro del recuadro.", style = MaterialTheme.typography.bodySmall, color = Muted)
    }
}
