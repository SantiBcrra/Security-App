package ar.com.securityapp.campo.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Shapes
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.Immutable
import androidx.compose.runtime.ReadOnlyComposable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp

/*
 * Misma paleta que el panel web: pizarra #353c4f + amarillo seguridad #f2c014. Pensada para usar con guantes y al sol.
 * Modo oscuro (para las guardias de noche: no encandila en la garita): sigue al celular o lo elige el usuario en Ajustes.
 * Los colores de las pantallas se piden por nombre (CardBg, Muted, WarnBg…) y cambian solos con el tema.
 */

/** Amarillo de la marca: igual en los dos temas. */
val Accent = Color(0xFFF2C014)

/** Texto e íconos sobre el amarillo (siempre oscuro, en los dos temas). */
val OnAccent = Color(0xFF232836)

@Immutable
data class AppColors(
    val dark: Boolean,
    /** Barras superiores, botones principales y fondos de marca. */
    val brand: Color,
    /** Texto destacado ("pendiente", títulos sobre fondos amarillos claros). */
    val strong: Color,
    /** Texto en el color de la marca (estados, enlaces). */
    val ink: Color,
    val muted: Color,
    val danger: Color,
    val ok: Color,
    /** Fondo de tarjetas y opciones sin elegir. */
    val card: Color,
    val warnBg: Color,
    val dangerBg: Color,
    val dangerSoft: Color,
    val okBg: Color,
    val neutralBg: Color,
    val border: Color,
    /** Navegación inferior. */
    val bar: Color,
)

private val LightApp = AppColors(
    dark = false, brand = Color(0xFF353C4F), strong = Color(0xFF232836), ink = Color(0xFF353C4F), muted = Color(0xFF6B7182),
    danger = Color(0xFFDC3545), ok = Color(0xFF1E7B45), card = Color.White, warnBg = Color(0xFFFDF3CF), dangerBg = Color(0xFFFDE3E5),
    dangerSoft = Color(0xFFFFF4F4), okBg = Color(0xFFE3F3EA), neutralBg = Color(0xFFE8EAF0), border = Color(0xFFD5D8E0), bar = Color.White,
)

private val DarkApp = AppColors(
    dark = true, brand = Color(0xFF3F4862), strong = Color(0xFFF1DFA0), ink = Color(0xFFC3CCE4), muted = Color(0xFFA0A6B5),
    danger = Color(0xFFE5505C), ok = Color(0xFF34A86A), card = Color(0xFF1D2029), warnBg = Color(0xFF3A3320), dangerBg = Color(0xFF41242A),
    dangerSoft = Color(0xFF33222A), okBg = Color(0xFF1D3428), neutralBg = Color(0xFF2A2F3A), border = Color(0xFF3B414E), bar = Color(0xFF1D2029),
)

val LocalAppColors = staticCompositionLocalOf { LightApp }

// Nombres cortos que usan las pantallas (cambian con el tema).
val Brand: Color @Composable @ReadOnlyComposable get() = LocalAppColors.current.brand
val BrandDark: Color @Composable @ReadOnlyComposable get() = LocalAppColors.current.strong
val Ink: Color @Composable @ReadOnlyComposable get() = LocalAppColors.current.ink
val Muted: Color @Composable @ReadOnlyComposable get() = LocalAppColors.current.muted
val Danger: Color @Composable @ReadOnlyComposable get() = LocalAppColors.current.danger
val Ok: Color @Composable @ReadOnlyComposable get() = LocalAppColors.current.ok
val CardBg: Color @Composable @ReadOnlyComposable get() = LocalAppColors.current.card
val WarnBg: Color @Composable @ReadOnlyComposable get() = LocalAppColors.current.warnBg
val DangerBg: Color @Composable @ReadOnlyComposable get() = LocalAppColors.current.dangerBg
val DangerSoft: Color @Composable @ReadOnlyComposable get() = LocalAppColors.current.dangerSoft
val OkBg: Color @Composable @ReadOnlyComposable get() = LocalAppColors.current.okBg
val NeutralBg: Color @Composable @ReadOnlyComposable get() = LocalAppColors.current.neutralBg
val BorderC: Color @Composable @ReadOnlyComposable get() = LocalAppColors.current.border
val BarBg: Color @Composable @ReadOnlyComposable get() = LocalAppColors.current.bar
val IsDark: Boolean @Composable @ReadOnlyComposable get() = LocalAppColors.current.dark

private val LightScheme = lightColorScheme(
    primary = LightApp.brand, onPrimary = Color.White,
    secondary = Accent, onSecondary = OnAccent, tertiary = Accent,
    background = Color(0xFFF4F5F8), onBackground = Color(0xFF252A37),
    surface = Color.White, onSurface = Color(0xFF252A37),
    surfaceVariant = Color(0xFFE8EAF0), onSurfaceVariant = LightApp.muted,
    surfaceContainerLowest = Color.White, surfaceContainerLow = Color(0xFFF7F8FA), surfaceContainer = Color(0xFFF1F2F6),
    surfaceContainerHigh = Color(0xFFECEEF2), surfaceContainerHighest = Color(0xFFE6E8EE),
    outline = Color(0xFFB7BCC8), outlineVariant = LightApp.border,
    error = LightApp.danger,
)

private val DarkScheme = darkColorScheme(
    primary = Accent, onPrimary = OnAccent,
    // Botones con el color de la marca: así Android sabe que llevan letra blanca.
    primaryContainer = DarkApp.brand, onPrimaryContainer = Color.White,
    secondary = Accent, onSecondary = OnAccent, tertiary = Accent,
    background = Color(0xFF121419), onBackground = Color(0xFFE7E9EE),
    surface = DarkApp.card, onSurface = Color(0xFFE7E9EE),
    surfaceVariant = Color(0xFF272B35), onSurfaceVariant = DarkApp.muted,
    surfaceContainerLowest = Color(0xFF0E1015), surfaceContainerLow = Color(0xFF181B22), surfaceContainer = Color(0xFF1D2029),
    surfaceContainerHigh = Color(0xFF242833), surfaceContainerHighest = Color(0xFF2B303C),
    outline = Color(0xFF5A6070), outlineVariant = DarkApp.border,
    error = DarkApp.danger, onError = Color.White,
)

/** Tema elegido: "system" (sigue al celular), "light" u "dark". Se guarda en Prefs; Ajustes lo cambia en vivo. */
object ThemeMode {
    var value by mutableStateOf("system")
}

@Composable
fun SecurityAppTheme(content: @Composable () -> Unit) {
    val dark = when (ThemeMode.value) { "dark" -> true; "light" -> false; else -> isSystemInDarkTheme() }
    CompositionLocalProvider(LocalAppColors provides if (dark) DarkApp else LightApp) {
        MaterialTheme(
            colorScheme = if (dark) DarkScheme else LightScheme,
            shapes = Shapes(small = RoundedCornerShape(10.dp), medium = RoundedCornerShape(14.dp), large = RoundedCornerShape(18.dp)),
            content = content,
        )
    }
}
