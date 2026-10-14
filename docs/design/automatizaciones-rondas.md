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
