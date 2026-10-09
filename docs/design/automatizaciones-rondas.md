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
