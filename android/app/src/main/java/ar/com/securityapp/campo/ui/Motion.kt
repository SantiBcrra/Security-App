package ar.com.securityapp.campo.ui

import androidx.activity.compose.PredictiveBackHandler
import androidx.compose.animation.AnimatedContentTransitionScope
import androidx.compose.animation.ContentTransform
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.CubicBezierEasing
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.scaleIn
import androidx.compose.animation.slideInHorizontally
import androidx.compose.animation.slideOutHorizontally
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalHapticFeedback
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.launch
import kotlin.coroutines.cancellation.CancellationException

/*
 * Movimiento de la app: transiciones cortas (150–300 ms) con las curvas de Material, "atrás" predictivo y vibraciones
 * suaves en las acciones importantes. Si el celular tiene "Quitar animaciones", Compose las respeta solo.
 */

/** Curvas "emphasized" de Material 3: arranca rápido y frena suave. */
val EaseOut = CubicBezierEasing(0.05f, 0.7f, 0.1f, 1f)
val EaseIn = CubicBezierEasing(0.3f, 0f, 0.8f, 0.15f)

/**
 * Transición entre pantallas de la app:
 * - entrar a un módulo desde el inicio: entra desde la derecha y el inicio se corre un poco (como Android);
 * - volver al inicio: al revés;
 * - cambiar de pestaña (Avisos, Más…): fundido con un leve zoom (fade-through de Material).
 */
fun AnimatedContentTransitionScope<String>.routeTransition(depth: (String) -> Int): ContentTransform {
    val from = depth(initialState)
    val to = depth(targetState)
    return when {
        to > from -> (slideInHorizontally(tween(300, easing = EaseOut)) { it / 3 } + fadeIn(tween(220, delayMillis = 40)))
            .togetherWith(slideOutHorizontally(tween(300, easing = EaseOut)) { -it / 8 } + fadeOut(tween(140)))
        to < from -> (slideInHorizontally(tween(300, easing = EaseOut)) { -it / 8 } + fadeIn(tween(220, delayMillis = 40)))
            .togetherWith(slideOutHorizontally(tween(260, easing = EaseIn)) { it / 3 } + fadeOut(tween(160)))
        else -> (fadeIn(tween(220, delayMillis = 60)) + scaleIn(tween(260, easing = EaseOut), initialScale = 0.96f))
            .togetherWith(fadeOut(tween(110)))
    }
}

/** Avance del gesto "atrás" (0 a 1) para encoger la pantalla mientras el usuario desliza (Android 14 o más nuevo). */
class BackProgress(private val scope: CoroutineScope) {
    val value = Animatable(0f)
    fun set(p: Float) { scope.launch { value.snapTo(p) } }
    fun release() { scope.launch { value.animateTo(0f, tween(260, easing = EaseOut)) } }
}

val LocalBackProgress = staticCompositionLocalOf<BackProgress?> { null }

@Composable
fun rememberBackProgress(): BackProgress {
    val scope = rememberCoroutineScope()
    return remember { BackProgress(scope) }
}

/** La pantalla sigue al dedo durante el gesto atrás: se achica un poco, se corre y redondea las puntas. */
fun Modifier.predictiveBack(progress: BackProgress): Modifier = graphicsLayer {
    val p = progress.value.value
    if (p > 0f) {
        val scale = 1f - 0.08f * p
        scaleX = scale
        scaleY = scale
        translationX = size.width * 0.04f * p
        shape = androidx.compose.foundation.shape.RoundedCornerShape((28 * p).toInt())
        clip = true
    }
}

/**
 * Reemplazo de BackHandler con "atrás" predictivo: en Android 14 o más nuevo la pantalla acompaña el gesto y si el usuario
 * se arrepiente vuelve a su lugar; en versiones anteriores funciona igual que siempre.
 */
@Composable
fun AppBackHandler(enabled: Boolean = true, onBack: () -> Unit) {
    val progress = LocalBackProgress.current
    PredictiveBackHandler(enabled) { events ->
        try {
            events.collect { progress?.set(it.progress) }
            onBack()
        } catch (e: CancellationException) {
            throw e
        } finally {
            progress?.release()
        }
    }
}

/** Vibraciones cortas del sistema (respetan la configuración del celular). */
class Haptics(private val perform: (HapticFeedbackType) -> Unit) {
    /** Acción importante confirmada: empezar/terminar turno o ronda, guardar, enviar. */
    fun confirm() = perform(HapticFeedbackType.Confirm)
    /** Algo no se pudo hacer. */
    fun reject() = perform(HapticFeedbackType.Reject)
    /** Toque liviano: cambiar de pestaña, abrir el escáner, actualizar. */
    fun tick() = perform(HapticFeedbackType.ContextClick)
}

@Composable
fun rememberHaptics(): Haptics {
    val h = LocalHapticFeedback.current
    return remember(h) { Haptics { h.performHapticFeedback(it) } }
}

/** Las tarjetas grandes se hunden apenas al tocarlas (sensación de botón físico). */
@Composable
fun Modifier.pressScale(interaction: MutableInteractionSource): Modifier {
    val pressed by interaction.collectIsPressedAsState()
    val scale by animateFloatAsState(if (pressed) 0.97f else 1f, tween(if (pressed) 90 else 220, easing = EaseOut), label = "presion")
    return graphicsLayer { scaleX = scale; scaleY = scale }
}
