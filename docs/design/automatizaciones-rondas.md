# Automatizaciones de rondas — Fase 1

## Objetivo

Centralizar la configuración de las automatizaciones que usarán el panel, el cron y la app Android. Esta entrega no dispara todavía las alertas nuevas: deja persistidos los parámetros y los entrega a Android para que las fases siguientes puedan usarlos sin cambiar el contrato.

## Decisiones

- La configuración vive en `settings`, por empresa; no se agrega una tabla ni una migración.
- Solo quienes tienen `configuracion.editar` pueden modificarla.
- Los destinatarios y canales siguen siendo reglas de `notification_rules`; no se duplican en estos ajustes.
- Los valores se limitan en servidor para evitar intervalos inválidos.
- Android recibe los valores en `sync/pull.meta.automatizaciones_rondas` y los aplica también sin conexión.
- El cron continuará siendo el ejecutor de las comprobaciones que dependan del servidor.

## Parámetros

`rondas.automatizaciones`, `rondas.sin_progreso_minutos`, `rondas.inicio_gracia_minutos`, `rondas.atraso_minutos`, `rondas.bateria_baja_porcentaje`, `rondas.sin_sync_minutos` y `rondas.guia_android`.

La alerta de fuera de perímetro ya utiliza la regla `guard.off_site`; sus destinatarios y canales se editan en la misma pantalla de notificaciones.

## Pantalla y contrato

- Panel: `/panel/configuracion/notificaciones`, tarjeta “Automatizaciones de rondas”.
- API: los valores viajan en `GET /api/v1/sync/pull`, dentro de `meta.automatizaciones_rondas`.
- Auditoría: cada guardado genera `settings.update`.

## Próximas entregas

1. Sin progreso entre marcas.
2. Rondas programadas y guía automática.
3. Rondas atrasadas y escalamiento.
4. Estado del dispositivo.
5. Resúmenes y métricas.

## Fases 2 y 4 — hechas

Regla común: **primero avisa la app al guardia** (calculado en el celular, funciona sin señal) y, si sigue igual después de
**otro plazo igual**, **avisa el servidor a SyH y supervisores** (reglas de notificación editables).

| | Guardia (app) | SyH y supervisores (cron) |
|---|---|---|
| Sin marcar puntos (N = `rondas.sin_progreso_minutos`) | a los N min sin marcar, una vez por hueco | a los 2×N, solo si el celular sigue mandando su posición (si no, ya avisa "guardia sin señal") — `guard.no_progress` |
| Atrasada (ruta con duración esperada E; T = `rondas.atraso_minutos`) | a los E + T | a los E + 2×T, con los puntos que faltan — `guard.round_late` |
| Abandonada | — | en curso hace más de 12 h: el sistema la cierra (completa/incompleta) con su última actividad |

- Servidor: `App\Services\RoundAutomation::run()` en `CronRunner`; cada alerta queda en `patrol_round_alerts` (tenant 0067) y se
  ve en `/panel/rondas/ronda/{uuid}` junto con la duración real contra la esperada.
- Android: `tracking/RoundWatch.kt`, revisado cada minuto por `RoundTrackingService` mientras hay ronda en curso.
- Rondas libres y rutas sin duración esperada no se controlan por atraso.

## Fase 5 — hecha: estado del celular

La app manda su estado (batería, cargando, GPS, permiso de ubicación, desde cuándo está apagado, registros sin enviar,
versión) en cada sincronización (`device.status`); el servidor guarda el último por celular.

| Problema | Guardia (app, enseguida) | SyH y supervisores (cron, una vez por ronda) |
|---|---|---|
| Batería baja sin cargar | ≤ `rondas.bateria_baja_porcentaje` (20 %) | ≤ la mitad (10 %): se va a apagar — `guard.device` |
| GPS apagado / sin permiso | apenas se apaga | si sigue apagado 5 min — `guard.device` |
| GPS prendido sin posiciones | sin posiciones nuevas en 5 min | — (se ve en el recorrido) |
| Registros sin enviar | más viejos que `rondas.sin_sync_minutos` | — (si no hay contacto, ya avisa "guardia sin señal") |

Panel: "Celulares de los guardias" en `/panel/rondas` y estado del celular en el detalle de la ronda en curso.

## Fase 3 — hecha: programación de rondas

Decisiones del usuario: horarios fijos **y** por intervalo dentro de una franja, configurable por días, con todas las opciones
lógicas, y registrar las no realizadas.

- **Programación** (por ruta, puede haber varias): horarios fijos ("22:00, 02:00, 06:00") o "cada N min de HH:MM a HH:MM"
  (si "hasta" < "desde" cruza la medianoche; cuenta el día en que empieza la franja; incluye la hora final), días de la semana,
  vigencia desde/hasta, fechas excluidas (feriados), guardias a cargo (si no se eligen, los asignados a la ruta), gracia y
  demora máxima para empezar tarde (con intervalo, no puede superar el intervalo).
- **Cada ronda programada** queda registrada: pendiente → realizada a tiempo (empezó hasta la hora ± gracia) / realizada tarde
  / no realizada (no empezó antes de la demora máxima). Nunca se inicia sola: la empieza el guardia.
- **Avisos**: al guardia (app, alarmas del sistema aunque esté cerrada) a la hora y al terminar la gracia; a SyH y
  supervisores a la hora + 2×gracia si no empezó (`guard.round_not_started`) y al quedar no realizada (`guard.round_missed`).
- **App**: "Próximas rondas programadas" (hora, estado, Iniciar) y guía al próximo punto durante la ronda (distancia, rumbo y
  "Cómo llegar" con el mapa del celular).
- **Panel**: `/panel/rondas/programacion` — rondas del día con su estado y % a tiempo, navegación por fecha, programaciones con
  editar / pausar. Editar o pausar recalcula solo lo futuro: lo pasado queda como registro.

## Fase 6 — hecha: resúmenes y métricas

- **Pantalla** `/panel/rondas/metricas` (Rondas → Métricas): período (por defecto los últimos 7 días), ruta y guardia.
  - Tarjetas: rondas (completas / incompletas / en curso), % de programadas a tiempo, % de marcas en el lugar y con NFC,
    atrasadas, abandonadas, pánicos y guardias sin actividad.
  - **Por guardia**: rondas, incompletas, atrasadas, duración media, programadas a tiempo y no realizadas, marcas fuera de
    lugar / dudosas, alertas y última ronda. Los asignados a rutas activas que no hicieron ninguna ronda aparecen marcados.
  - **Por punto**, ordenado por problemas: veces salteado, marcas fuera de lugar y dudosas (un punto que falla seguido suele
    tener el QR o la etiqueta dañados o las coordenadas mal cargadas).
  - **Historial de alertas** con su resolución: sin marcar, atrasada, abandonada, batería, GPS, marcas fuera de lugar,
    programadas no realizadas y pánicos (quién y en cuántos minutos lo atendió, o SIN ATENDER).
- **CSV** de guardias, puntos y alertas (permiso exportar).
- **Resumen diario** a SyH y supervisores (app + email, regla editable `guard.daily_summary`): a partir de las 8 h, lo del día
  anterior; solo si hubo rondas o programadas.
- Las lecturas fallidas de QR/NFC no se registran en el servidor (la app no sabe de qué punto eran); los puntos problemáticos
  se detectan por salteados, fuera de lugar y dudosas.
