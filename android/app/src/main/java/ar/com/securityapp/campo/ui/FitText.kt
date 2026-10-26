package ar.com.securityapp.campo.ui

import androidx.compose.foundation.text.BasicText
import androidx.compose.foundation.text.TextAutoSize
import androidx.compose.material3.LocalContentColor
import androidx.compose.material3.LocalTextStyle
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.sp
import androidx.compose.ui.unit.isSpecified
import androidx.compose.ui.graphics.takeOrElse

/**
 * Texto de una sola línea que se achica para entrar en el ancho disponible (celulares angostos o con la letra del sistema
 * agrandada) en lugar de cortarse o partir la palabra: "Vehículo" nunca queda "Vehícu", "Actividad" nunca "Activida / d".
 */
@Composable
fun FitText(text: String, modifier: Modifier = Modifier, style: TextStyle = LocalTextStyle.current, minSize: TextUnit = 10.sp) {
    val max = if (style.fontSize.isSpecified) style.fontSize else 14.sp
    val color = style.color.takeOrElse { LocalContentColor.current }
    BasicText(
        text, modifier,
        style = style.merge(TextStyle(color = color, textAlign = TextAlign.Center)),
        maxLines = 1, softWrap = false, overflow = TextOverflow.Clip,
        autoSize = TextAutoSize.StepBased(minFontSize = minSize, maxFontSize = max, stepSize = 0.5.sp),
    )
}
