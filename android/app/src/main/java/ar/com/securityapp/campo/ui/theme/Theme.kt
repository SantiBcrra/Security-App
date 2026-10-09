package ar.com.securityapp.campo.ui.theme

import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.ui.unit.dp

/** Misma paleta que el panel web: pizarra #353c4f + amarillo seguridad #f2c014. Pensada para usar con guantes y al sol. */
val Brand = Color(0xFF353C4F)
val BrandDark = Color(0xFF232836)
val Accent = Color(0xFFF2C014)
val Surface = Color(0xFFF4F5F8)
val Danger = Color(0xFFDC3545)
val Ok = Color(0xFF1E7B45)
val Muted = Color(0xFF6B7182)

private val Colors = lightColorScheme(
    primary = Brand,
    onPrimary = Color.White,
    secondary = Accent,
    onSecondary = BrandDark,
    tertiary = Accent,
    background = Surface,
    onBackground = Color(0xFF252A37),
    surface = Color.White,
    onSurface = Color(0xFF252A37),
    surfaceVariant = Color(0xFFE8EAF0),
    onSurfaceVariant = Muted,
    error = Danger,
)

@Composable
fun SecurityAppTheme(content: @Composable () -> Unit) {
    MaterialTheme(
        colorScheme = Colors,
        shapes = Shapes(small = RoundedCornerShape(10.dp), medium = RoundedCornerShape(14.dp), large = RoundedCornerShape(18.dp)),
        content = content,
    )
}
