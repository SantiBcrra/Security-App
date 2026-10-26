# Turnos de guardias, ausencias, control de salida del predio y horas trabajadas

Decisiones del usuario (16/10/2026): inicio de turno **manual**; esquemas **fijos y rotativos**; la salida del predio la
**justifica el guardia**; las ausencias las **aprueba el supervisor** (el guardia puede pedirlas desde la app); un guardia
puede cubrir **varios predios**; reporte de **horas reales**. Configuración recomendada aplicada.

## Principio de privacidad (Ley 25.326 / LCT)
La ubicación se registra **solo mientras el guardia está en turno** (desde que toca "Empezar turno" hasta "Terminar turno"
o el cierre automático). Fuera del turno la app no pide ni guarda nada. Mientras está en turno hay una notificación fija
"En turno" (servicio en primer plano de Android: no hace falta el permiso de ubicación "todo el tiempo"). Solo se guardan
posiciones **cuando está fuera del predio** (para el informe de la salida); dentro del predio se controla, no se guarda.
Las posiciones detalladas (turnos y rondas) se borran a los `turnos.dias_posiciones` (90) días; las horas, las salidas y
sus motivos quedan. El guardia ve su propio historial en la app. El consentimiento sube de versión (`Consent::VERSION`).
⚠ Si los guardias son de una empresa de seguridad contratada, preverlo en el contrato. Texto final: revisarlo legales/RR.HH.

## Modelo (tenant 0072)
- `guard_shifts` (programación): nombre, `site_id`, `user_ids` (JSON, uno o varios guardias), `pattern` weekly | rotation,
  `days` (weekly), `rotation_on`/`rotation_off`/`anchor_date` (rotation: 12×36 = 1 día sí / 1 no de 12 h; 2×2, 4×4…),
  `start_time`/`end_time` (cruza la medianoche si fin ≤ inicio), vigencia, `grace_minutes`, activo.
- `guard_shift_slots` (cada turno de un guardia): `shift_id` (null = turno no programado / reemplazo), `user_id`, `site_id`,
  `start_at`/`end_at` (UTC), `status` programado | en_curso | cumplido | ausente (no vino) | justificado (ausencia aprobada) |
  anulado, `started_at`/`ended_at` reales (hora del celular), `end_mode` manual | auto, ubicación de inicio y si estaba en el
  predio, `out_seconds` (tiempo fuera), `replaces_slot_id`, `absence_id`, `last_contact_at`.
- `guard_absences`: guardia, desde/hasta (días locales), tipo (franco, enfermedad, vacaciones, licencia, personal, otro),
  motivo, `status` solicitada | aprobada | rechazada | cancelada, quién pidió / decidió, reemplazo opcional.
- `guard_closures`: días sin servicio (feriado, planta cerrada) para un predio o todos.
- `guard_shift_exits`: salidas del predio (uuid del celular, salió / volvió, motivo, nota, distancia máxima, avisos).
- `guard_shift_positions`: posiciones mientras está fuera del predio (se borran a los 90 días).

## Reglas
- El cron (`ShiftPlanner::run`) genera turnos de ayer a +6 días (idempotente por programación + guardia + inicio). No genera
  para días sin servicio; si el guardia tiene ausencia aprobada, el turno nace `justificado` (y su reemplazo, si hay, tiene
  su propio turno).
- **Empezar turno** (app): desde 60 min antes del inicio hasta el fin. GPS obligatorio; nunca se rechaza: se registra si
  estaba en el predio (radio del predio + `turnos.tolerancia_m` 100 m, sin acusar a un GPS impreciso) y si no, aviso. Si no
  hay turno programado se puede empezar uno "no programado" en un predio (queda marcado).
- **Sin empezar**: el celular le avisa al guardia a la hora y al terminar la tolerancia (`grace` 15 min); el servidor avisa
  a SyH + supervisores a la hora + 2×tolerancia (`guard.shift_not_started`, una vez) y lo marca **ausente** si llega el fin
  sin empezar (`guard.shift_missed`).
- **Terminar turno**: manual. Si no lo termina, el celular le recuerda al fin, y el servidor lo cierra solo a fin +
  `turnos.cierre_auto_minutos` (120) con la hora programada de fin (`end_mode = auto`, aviso informativo `guard.shift_no_end`).
- **Salida del predio** (app, servicio "En turno"): ubicación cada 60 s con precisión equilibrada; si una lectura queda fuera
  se confirma con GPS preciso. Fuera del radio + tolerancia durante `turnos.minutos_fuera` (3) → se abre la salida
  (`shift.exit`) y se le pregunta "Saliste del predio, ¿por qué?" (persecución / robo, comisaría, emergencia médica, me lo
  pidió el encargado, trámite del servicio, otro + nota). También "Salgo por una emergencia" antes de salir.
  - Con motivo → aviso informativo a SyH + supervisores (`guard.shift_exit`, con mapa).
  - Sin motivo a los `turnos.minutos_sin_motivo` (10) → alarma `guard.shift_exit_alarm` (una vez por salida).
  - Al volver (`shift.return`) se cierra la salida y suma `out_seconds`.
- **Sin contacto** durante el turno (sin sincronizar `turnos.minutos_sin_contacto`, 30) o GPS apagado 5 min →
  `guard.shift_silent` (una vez por hueco).
- **Ausencias**: el guardia las pide en la app (`absence.request`) o las carga el supervisor (aprobadas directo). Aprobar
  (permiso `rondas.editar`) justifica sus turnos del rango; con reemplazo se crea el turno del reemplazo. Sin reemplazo →
  un solo aviso "Puesto sin cubrir" (`guard.shift_uncovered`). Avisos: `guard.absence_requested` (supervisores),
  `guard.absence_decided` (al guardia).
- **Rondas programadas**: si todos los guardias a cargo de una ronda programada están ausentes con ausencia aprobada, la
  ronda queda `justificada` sin alarmas (salvo que un reemplazo la haga).
- **Horas** (`ShiftReport`): por guardia y período: turnos, horas programadas, horas reales (inicio–fin real), llegadas
  tarde, salidas anticipadas, tiempo fuera del predio, ausencias justificadas / injustificadas. CSV.

## Pantallas
Panel → Rondas → pestaña **Turnos** (Hoy · Programación · Ausencias · Horas trabajadas) y detalle de cada turno (línea de
tiempo, mapa con el predio y las salidas). Configuración → notificaciones → tarjeta "Turnos". Plantas: mapa para ubicar el
predio y su radio.

App Android: tarjeta "Mi turno" en el inicio (Empezar / Terminar, estado dentro/fuera), servicio "En turno", pregunta de
salida, alarmas de inicio/fin, "Mis turnos" (historial y horas) y "Pedir ausencia".

## Horario por día (franjas) — 23/10/2026
Pedido del usuario: "lunes a viernes de 20 a 8 y el sábado de 14 hasta las 8 del lunes". Tercer tipo de programación
(`pattern = custom`, columna `guard_shifts.blocks`, migración tenant 0076): lista de franjas semanales, cada una con día y hora
de entrada y día y hora de salida. Cada franja genera un turno (el del fin de semana dura 42 h); máximo 72 h por franja; no se
pueden superponer (se controla también el paso del domingo al lunes). Para ausencias y días sin servicio cuenta el día de
entrada. Atajos en el formulario: noches Lun a Vie + fin de semana, Lun a Vie 08–17 + Sáb 08–13, 24 h todos los días,
fin de semana completo.

## Turnos por puesto y celular compartido — 25/10/2026
Pedido del usuario: los guardias son de una empresa de seguridad tercerizada que se organiza sola (una semana pueden ser 5
personas, otra 3). Indumor sabe qué horario hay que cubrir, no quién viene. La empresa tiene **un solo celular** para todos.

- **Programación por puesto**: horario (cualquier tipo, incluido "horario por día") + grupo de guardias + celular compartido
  opcional. No hay turnos por persona: hay franjas a cubrir. El que llega toca "Empezar mi turno" y toma el puesto; el siguiente
  lo releva. "Dejar el puesto sin persona" (con motivo: llegó el encargado, el dueño…) justifica el hueco.
- **Avisos**: puesto sin cubrir (nadie empezó), relevo que no llegó (alguien terminó y nadie siguió), muchas horas seguidas.
  Sin "no vino" ni ausencias: no se sabe quién tenía que venir.
- **Celular compartido**: la app queda abierta con la cuenta del puesto. Cada guardia elige su nombre y pone su PIN (lo carga
  el supervisor en la ficha del usuario); desde ahí rondas, libro, pánico y observaciones quedan a su nombre. El celular nunca
  cierra sesión entre guardias (cerrar sesión borraría lo pendiente). El PIN identifica a la persona; no es una contraseña: el
  acceso lo protege la cuenta del puesto. El consentimiento de ubicación lo acepta cada guardia la primera vez.
- **Reporte de cobertura**: horas a cubrir contra cubiertas, huecos con y sin justificación; las horas por guardia siguen en
  "Horas trabajadas" (sirve para controlar o liquidar a la empresa de seguridad).
