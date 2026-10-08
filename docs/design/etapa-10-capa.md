# Etapa 10 — Acciones correctivas y preventivas (CAPA) · Diseño

Estado: **aprobado** · entregas 1 y 2 hechas (decisiones del usuario: la observación se cierra sola; app de campo incluida en esta etapa).

## 1. Qué resuelve
Hoy una observación con "acción asignada" guarda tres campos sueltos:
`assigned_user_id`, `action_text` y `action_due_on`. Esto tiene tres límites:
- una sola acción por observación;
- no hay evidencia de cierre;
- no hay verificación de eficacia.

La Etapa 10 crea las **acciones** como módulo propio:
- pueden nacer de cualquier origen (observación, inspección, incidente, ronda, auditoría o manual);
- tienen responsable, fecha límite y prioridad;
- el cierre exige evidencia;
- un segundo paso verifica la eficacia;
- el cron manda recordatorios;
- hay un tablero de vencidas.

## 2. Tablas (base de cada empresa, migraciones tenant 0038+)

### `actions`
| columna | notas |
|---|---|
| `id`, `uuid` | PK interna + UUID público (puede venir del celular) |
| `number` | ACC-000001, por empresa, con `Sequences::next('action')` |
| `title`, `description` | qué hay que hacer |
| `type` | `correctiva` · `preventiva` · `mejora` |
| `priority` | `baja` · `media` · `alta` · `critica` |
| `origin_type`, `origin_id` | `observacion` · `inspeccion` · `incidente` · `ronda` · `auditoria` · `manual` (+ id interno del origen) |
| `site_id`, `sector_id` | heredados del origen o elegidos (alcance por sector) |
| `responsible_user_id`, `created_by` | |
| `due_on` | DATE (fecha límite, en la zona de la empresa) |
| `status` | ver §3 |
| `closed_at`, `closed_by`, `closure_text` | cierre (el texto es evidencia: no se edita) |
| `verify_due_on` | fecha límite para verificar (cierre + N días, setting) |
| `verified_at`, `verified_by`, `verification_text`, `effective` | resultado de la verificación |
| `created_at`, `updated_at`, `deleted_at` | sync por cursor |

Índices:
- `(status, due_on)`;
- `(responsible_user_id, status)`;
- `(origin_type, origin_id)`;
- `(sector_id)`;
- `(updated_at, id)`.

### `action_events`
Es la línea de tiempo, de **solo inserción** (igual que `observation_events`). Tipos: `created`,
`assigned`, `due_changed`, `started`, `comment`, `closed`, `verified`, `rejected`, `reopened`,
`cancelled`. Cada evento guarda el cambio en `data`, con antes y después.

### `action_attachments`
Es la evidencia de cierre (fotos o archivos):
- se guarda con `TenantFiles` + `ImageProcessor` (sha256, EXIF, miniatura) y es **inmutable**;
- `kind` puede ser `evidencia` (la del cierre) o `referencia` (la que se adjunta al crearla).

### `uploads` (ya existe)
Se le agrega `target_type` / `target_uuid` para poder subir fotos por partes también a una acción
(hoy solo acepta `observation_uuid`). Las subidas ya existentes quedan como `observacion`.

## 3. Estados

```
abierta ──(tomar)──▶ en_curso ──(cerrar: texto + ≥1 evidencia)──▶ cerrada
   │                    ▲                                            │
   │                    └──────(rechazar: no fue eficaz + motivo)────┤
   │                                                                 ▼
   └──(cancelar + motivo)──▶ cancelada                     verificada (eficaz)
```

Reglas:
- Se puede cerrar directamente desde `abierta`; el paso "tomar" es opcional.
- **Vencida no es un estado**: se calcula (`due_on` < hoy y estado `abierta` o `en_curso`). Así
  no hace falta un cron que cambie estados para que el tablero esté al día.
- `cerrada` significa "pendiente de verificación".
- La verificación la hace **otro usuario**, distinto del que cerró, con el permiso `verificar`
  (ver §4). Hay dos resultados:
  - **Eficaz** → `verificada`.
  - **No eficaz** → vuelve a `en_curso`. Se pide motivo y se puede poner una nueva fecha. La
    evidencia anterior queda en la historia.
- Las transiciones son declarativas en `ActionWorkflow`, como `ObservationWorkflow`. Todo pasa
  por `ActionService::transition()`: permiso, estado bloqueado con `FOR UPDATE`, evento y
  auditoría.

## 4. Permisos y alcance
- Módulo `acciones` (ya existe). Acciones: `ver`, `crear`, `editar`, `cerrar`, `exportar` + una
  nueva, **`verificar`**. Esta solo se ofrece en el módulo `acciones`: el editor de roles no la
  muestra en los otros módulos.
- Alcance:
  - `todo`: toda la empresa.
  - `sectores`: acciones de sus sectores, más las que tiene como responsable.
  - `propios`: solo las suyas, como responsable o como creador.
- **El responsable siempre puede ver, tomar y cerrar su acción**, aunque su rol no lo diga (como
  el "asignado" de hoy). No puede verificarla.
- Roles base. Una migración `.php` agrega lo que falte a los roles ya creados, como se hizo con
  las rondas:

| Rol | Permisos en `acciones` |
|---|---|
| admin_empresa | todo |
| responsable_hys | todo + verificar |
| supervisor | ver, crear, editar, cerrar · sus sectores |
| reportante | ver · propios (como responsable toma y cierra las suyas igual; no puede cancelar) |
| auditor | ver, exportar |

## 5. Integración con Observaciones
- En la observación, **"Asignar acción" crea una acción** con `origin_type = observacion`:
  - los campos son los mismos de hoy más prioridad y tipo;
  - una observación puede tener **varias acciones**;
  - el detalle de la observación lista sus acciones con su estado.
- La observación queda en `accion_asignada` mientras tenga acciones abiertas.
- ✅ **Cierre de la observación** (decidido): cuando **todas** sus acciones quedan
  `verificada` o `cancelada`, la observación pasa sola a `cerrada`. Lleva un evento del sistema
  ("todas las acciones verificadas") y el cierre manual sigue disponible.
- **Migración de datos** (`.php`, idempotente):
  - observaciones con `action_text`:
    - en estado `accion_asignada` → acción `abierta`, con el mismo responsable y la misma fecha;
    - en estado `cerrada` → acción `verificada`, marcada como "migrada" (sin evidencia, con
      una nota en el evento).
  - Las columnas viejas no se borran: quedan como **resumen automático de la acción abierta que
    vence primero** (las mantiene `ObservationService::syncActions()`), así los listados, los avisos
    actuales y la app de campo siguen funcionando sin cambios hasta la entrega 2.
- La app de campo hoy muestra `assigned_name` / `action_due_on` de la observación. Pasa a
  mostrar la acción vigente.

## 6. Notificaciones y cron
- **Generalizar `Notifier::dispatch($evento, $sujeto)`**, donde el sujeto es un arreglo común:
  - campos: `type`, `id`, `uuid`, `label` (OBS-000012 / ACC-000034), `title`, `sector_id`,
    `severity_level`, `assignee_id`, `reporter_id`, `url`, `app_url`;
  - `Subject::observation()` y `Subject::action()` lo arman;
  - `Recipients` usa `assignee` → responsable y `reporter` → creador;
  - las observaciones siguen igual por fuera.
- Eventos nuevos:

| Evento | A quién (regla sembrada) | Cuándo |
|---|---|---|
| `action.assigned` | responsable | al crear o reasignar |
| `action.due_soon` | responsable | N días antes (setting `acciones.aviso_dias`, 3), una vez |
| `action.overdue` | responsable · desde el día 3 también supervisores del sector + SyH | cada día mientras siga vencida |
| `action.closed` | verificadores (rol SyH) | al cerrar |
| `action.verify_overdue` | verificadores | verificación atrasada (`acciones.dias_verificacion`, 15) |
| `action.verified` / `action.rejected` | responsable + creador | al verificar |

- Las reglas actuales `observation.assigned` / `observation.overdue` se migran a los eventos de
  acciones, con los mismos destinatarios y canales.
- `Digests::overdue()` deja de revisar las observaciones. Su lugar lo toma
  `Actions\Reminders::run()` dentro de `CronRunner`, con `NotificationMarks` para que no se repita
  en el día.
- El resumen diario o semanal suma "acciones vencidas" y "pendientes de verificar".

## 7. Pantallas del panel
- **`/panel/acciones`** (menú "Acciones"):
  - lista con filtros: estado, solo vencidas, responsable, sector, origen y prioridad;
  - pestaña rápida **"Mis acciones"**;
  - vencidas en rojo, con los días de atraso.
- **`/panel/acciones/nueva`**: alta manual (origen `manual` o `auditoria`).
- **`/panel/acciones/{uuid}`**:
  - muestra los datos, un link al origen, la línea de tiempo y la evidencia;
  - tiene los botones según el estado: tomar, comentar, reasignar o cambiar la fecha (con
    motivo, y queda en el evento), cerrar con evidencia, verificar o rechazar y cancelar.
- **`/panel/acciones/tablero`**:
  - vencidas **por sector** y **por responsable**: cantidad, atraso máximo y atraso promedio;
  - abiertas por prioridad;
  - pendientes de verificar;
  - tiempo promedio de cierre (los gráficos quedan para la Etapa 16).
- Exportación a CSV (permiso `exportar`) y página imprimible.
- Inicio de `/panel`: un recuadro "Mis acciones pendientes".

## 8. App de campo (PWA)
- ✅ **Incluida en esta etapa** (decidido): "Mis acciones" dentro de la pestaña Reportes. El
  responsable la ve, la toma y **la cierra con texto + fotos sin señal**.
  - Para eso: entidad `actions` en `Sync\Pull` (las mías como responsable y lo que diga mi
    alcance), operaciones `action.start` / `action.close` en `Sync\Push` y fotos por partes con
    `target_type = accion`.
  - La verificación queda solo en la web.

## 9. Otros orígenes (para las etapas siguientes)
`ActionService::createFromOrigin($tipo, $id, $datos)` es el punto único que van a usar:
- las inspecciones (Etapa 11: ítem que no cumple);
- los incidentes (Etapa 12: investigación);
- las rondas: un punto crítico salteado puede generar una acción cuando se agreguen las
  incidencias de ronda.

## 10. Entregas (por partes, cada una con tests y commit)
1. **Base**:
   - tablas, `ActionWorkflow` y `ActionService`;
   - permisos (`verificar`);
   - panel (lista, alta, detalle, transiciones con evidencia);
   - integración con observaciones y migración de las acciones actuales.
2. **Avisos y tablero**:
   - `Notifier` generalizado, eventos y reglas, recordatorios por cron;
   - tablero de vencidas, CSV, imprimible y recuadro en inicio.
3. **App de campo**: "Mis acciones" offline con cierre y fotos.

## 11. Tests
- Transiciones válidas e inválidas.
- Cierre sin evidencia → error.
- El mismo usuario no puede verificar lo que cerró.
- Rechazar → vuelve a `en_curso` y conserva la evidencia anterior.
- Alcance: todo, sectores y propios, y el responsable siempre ve la suya.
- Numeración ACC sin huecos ni duplicados.
- Migración de las observaciones existentes: idempotente, correr dos veces no duplica.
- Cierre automático de la observación.
- Recordatorios una vez por día y escalamiento al día 3.
- Sync: idempotencia de `action.close` y fotos por partes con target `accion`.
- Aislamiento entre empresas.
