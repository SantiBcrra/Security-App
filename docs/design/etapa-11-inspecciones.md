# Etapa 11 — Inspecciones y checklists · Diseño

Estado: **aprobado** · entregas 1, 2 y 3 hechas (Etapa 11 completa). Decisiones del usuario: una acción por ítem; falla crítica = solo aviso (sin "fuera de
servicio"); el operario hace el pre-uso; frecuencias diaria/semanal/mensual + manual (turnos más adelante).

## 1. Qué resuelve
Hoy no hay forma de registrar controles periódicos: el pre-uso del autoelevador, la revisión de
extintores o la recorrida de orden y limpieza. La Etapa 11 agrega cinco cosas:
- **plantillas de checklist** por empresa, con versiones;
- **inspecciones** hechas desde la web o desde el celular (sin señal, escaneando el QR del equipo),
  con respuestas inmutables;
- **programación** automática por el cron y **cumplimiento** (hechas vs. programadas);
- un **ítem que no cumple crea una acción CAPA** automáticamente (Etapa 10);
- **plantillas precargadas** para la metalúrgica.

## 2. Conceptos
| Concepto | Qué es |
|---|---|
| Plantilla | Estructura del checklist (secciones e ítems). Puede ser para un **tipo de equipo** (ej. Autoelevador), para un **sector** o general. |
| Versión | Foto congelada de la plantilla. Editar una plantilla ya usada crea la versión siguiente; cada inspección queda atada a la versión con la que se hizo. |
| Programa | "Qué inspección se hace, sobre qué, cada cuánto y quién". Ej: pre-uso diario de **todos** los autoelevadores, a cargo de los supervisores de su sector. |
| Inspección programada | Cada vez que toca hacerla (ej. "AE-01, 08/10"). La genera el cron. Queda pendiente, hecha u omitida; "vencida" se calcula. |
| Inspección | El checklist completado: respuestas, fotos, GPS, resultado. Evidencia inmutable. |

## 3. Tablas (base de cada empresa, migraciones tenant 0043+)

### `inspection_templates`
- Campos: `uuid`, `name`, `description`, `scope`.
- `scope` puede ser `equipo` (con `equipment_type_id`), `sector` o `general`.
- `current_version_id`, `is_active`, `updated_at` y `deleted_at` (para la sincronización).

### `inspection_template_versions`
- Campos: `template_id`, `version`, `structure` (JSON en `LONGTEXT`), `created_by`, `created_at`.
- **Nunca se modifica** una vez que una inspección la usó.
- La estructura tiene secciones e ítems. Cada ítem tiene:
  - `key`: UUID estable entre versiones, para comparar en el tiempo;
  - `text` y `help` (ayuda opcional);
  - `type`: `si_no`, `si_no_na`, `numero` (con `min`/`max`/`unidad`) o `texto`;
  - `ok_when`: qué respuesta cumple, ej. "sí" para "¿Frenos funcionan?" y "no" para "¿Pierde aceite?";
  - `critical` (sí/no) y `photo` (`nunca`, `siempre` o `si_no_cumple`).

### `inspection_programs`
- Campos: `uuid`, `template_id`, `name`.
- Frecuencia: `diaria`, `semanal` (día de la semana), `mensual` (día del mes) o `manual`.
- Objetivo: un equipo, todos los equipos de un tipo (en una planta o sector), o un sector.
- Quién la hace: un usuario, un rol o los supervisores del sector del objetivo.
- **Responsable de las acciones automáticas**: un usuario, ej. el jefe de mantenimiento. Si no hay,
  el supervisor del sector; si tampoco hay, quien inspeccionó.
- Plazo de las acciones por ítem común y por ítem crítico. Por defecto: 7 días y 1 día.
- `is_active`.

### `inspection_schedule`
- Campos: `uuid`, `program_id`, `equipment_id` o `sector_id`, `period_key`, `due_on`, `status`,
  `inspection_id`, `assigned_to`.
- `period_key` es, por ejemplo, `2026-10-08` o `2026-W41`.
- `status`: `pendiente`, `hecha` u `omitida` (omitirla pide motivo).
- Único por `(program_id, equipo|sector, period_key)`: el cron puede correr mil veces sin duplicar.

### `inspections`
- Número **INS-000001** por empresa.
- Campos:
  - origen: `uuid` (puede venir del celular), `template_version_id`, `schedule_id` (si era programada);
  - qué y dónde: `equipment_id`, `site_id`, `sector_id`, `lat`/`lng`;
  - quién y cuándo: `inspector_user_id`, `done_at_device`, `received_at`;
  - resultado y estado: `result` (`conforme` · `con_observaciones` · `no_conforme_critico`), `score`
    (% de ítems que cumplen, sin contar los N/A), `notes` y `status` (`completa` / `anulada` con motivo);
  - evidencia: `original_data` + `original_hash` (SHA-256), igual que las observaciones.

### `inspection_answers`
- Una fila por ítem: `item_key`, `item_text` (copia del texto), `value`, `ok`, `critical`, `comment`
  y `action_id`, la acción creada si el ítem no cumple.
- Son de solo inserción: las respuestas no se editan. Para corregir, se anula la inspección y se
  hace otra.

### `inspection_attachments`
- Las fotos, con `item_key`: inmutables, con SHA-256, EXIF y miniatura (`ImageProcessor`).

## 4. Resultado y acciones automáticas
Al recibir la inspección, el servidor:
1. Recalcula `ok` y el resultado; no confía en lo que diga el celular.
2. Por cada ítem que **no cumple**, crea una acción CAPA:
   - `origin_type = inspeccion`, con el texto del ítem y el comentario;
   - el equipo y el sector salen de la inspección;
   - la prioridad es **crítica** si el ítem es crítico y **media** si no;
   - la fecha límite sale del plazo del programa.
   - ✅ Una acción por ítem que no cumple (decidido).
3. Si falló un **ítem crítico**: avisa enseguida a los supervisores del sector y a SyH (evento
   `inspection.critical_fail`).
   - ✅ Decidido: **solo aviso**. El equipo no se marca como fuera de servicio. Queda como posible
     mejora futura.
4. Si era una inspección programada, la marca como hecha.

## 5. Programación y cumplimiento
- El cron genera las inspecciones programadas de hoy y de los próximos 7 días para cada programa
  activo. "Todos los autoelevadores" se expande a cada equipo activo de ese tipo; un equipo nuevo
  entra solo.
- Vencida: estado `pendiente` con `due_on` < hoy.
- Recordatorios con `NotificationMarks`:
  - `inspection.due`, el día que toca, a quien la tiene asignada;
  - `inspection.overdue`, al día siguiente, a quien la tiene asignada y a los supervisores del sector.
- **Cumplimiento** = hechas a tiempo / programadas (las omitidas con motivo se muestran aparte).
  Se ve por programa, sector, equipo e inspector, por mes.
- ✅ **Frecuencias** (decidido): diaria, semanal, mensual y "manual": el pre-uso "antes de cada uso"
  se hace cuando se escanea, sin programa. **Turnos** quedan para más adelante.

## 6. Permisos (módulo `inspecciones`, ya existe)
| Acción | Para qué |
|---|---|
| ver | Ver inspecciones (alcance todo / sectores / propias) |
| crear | Hacer inspecciones |
| editar | Plantillas y programas |
| cerrar | Anular una inspección u omitir una programada (con motivo) |
| exportar | CSV |

- ✅ **El operario hace el pre-uso** (decidido): el rol reportante recibe `ver` + `crear` con
  alcance "propios", así el autoelevadorista hace el checklist desde su celular. Se aplica con una
  migración que suma el permiso a los roles existentes, igual que en etapas anteriores.
- Supervisor: ver, crear, cerrar (sus sectores). SyH y admin: todo.

## 7. Pantallas del panel
- **`/panel/inspecciones`**: lista con filtros (plantilla, equipo, sector, resultado, inspector,
  fechas) y pestañas Hechas / Pendientes de hoy / Vencidas.
- **Hacer una inspección** (`/panel/inspecciones/nueva?plantilla=…&equipo=…`): formulario armado
  con la plantilla, botones grandes Sí / No / N/A, fotos por ítem y comentario obligatorio cuando
  un ítem no cumple.
- **Detalle**: respuestas, fotos, resultado, acciones creadas (con link), integridad (hash),
  imprimible y anular.
- **Plantillas** (`/panel/inspecciones/plantillas`):
  - constructor con secciones e ítems (agregar, ordenar, tipo, crítico, foto);
  - vista previa e historial de versiones;
  - "copiar desde una precargada".
- **Programas** (`/panel/inspecciones/programas`): alta y edición, con una vista previa de las
  próximas fechas.
- **Cumplimiento** (`/panel/inspecciones/cumplimiento`): por mes, programa, sector y equipo, con
  link a lo pendiente y lo vencido.
- **Ficha del equipo**:
  - checklists disponibles para su tipo, última inspección y resultado (con el aviso si la última
    falló en un ítem crítico);
  - el QR (`/q/{uuid}`) muestra esto mismo.

## 8. App de campo (PWA)
- Se sincronizan:
  - las **plantillas vigentes**, con su estructura;
  - **mis inspecciones programadas** pendientes de hoy y vencidas.
- **Escanear el QR de un equipo** muestra:
  - el checklist de su tipo, como "Hacer pre-uso de AE-01";
  - el resultado de la última inspección;
  - la opción "Reportar una observación" (lo que ya existe).
- El checklist se completa **sin señal**: botones grandes, fotos por ítem y GPS.
  - Se envía con `inspection.create`, idempotente por uuid y `op_id`; las fotos van por partes
    (`uploads` con `inspection_uuid` + `item_key`).
  - Las acciones automáticas las crea el servidor al recibir la inspección.
- Ubicación en la app (la barra ya tiene 5 pestañas):
  - "Reportar" pasa a ser **"Nuevo"**, con dos botones: Reportar observación y Hacer inspección
    (escanear QR o elegir de la lista);
  - las inspecciones pendientes de hoy aparecen en "Reportes", junto a "Mis acciones".

## 9. Plantillas precargadas (metalúrgica)
Se cargan con `IndustryTemplates`, son idempotentes y cada empresa las copia y ajusta:

| Plantilla | Para | Ejemplos de ítems (★ = crítico) |
|---|---|---|
| Pre-uso autoelevador | tipo Autoelevador | ★Frenos de servicio y de mano · ★Bocina y alarma de retroceso · ★Cadenas y horquillas sin fisuras · Pérdidas de aceite/combustible · Neumáticos · Luces · Cinturón de seguridad · Matafuegos a bordo · Nivel de batería/gas |
| Puente grúa | tipo Puente grúa | ★Gancho con pestillo · ★Fin de carrera de elevación · ★Botonera y parada de emergencia · Cable/cadena sin daños · Señal sonora · Carga máxima visible · Eslingas en buen estado |
| Amoladoras | tipo Amoladora | ★Guarda colocada · ★Disco adecuado y sin fisuras · Mango auxiliar · Cable y ficha sin daños · Llave de bloqueo |
| Soldadura | tipo Soldadora / Oxicorte | ★Cables y pinza sin daños · ★Conexión a tierra · ★Válvulas antirretroceso (oxicorte) · Cilindros asegurados · Ventilación/extracción · Mamparas · Extintor cerca |
| Orden y limpieza (5S) | sector | Pasillos despejados · Pisos sin aceite · Materiales estibados · Residuos clasificados · ★Salidas de emergencia libres · Señalización visible |
| Extintores | tipo Extintor | ★Presión en zona verde · ★Precinto y seguro · Carga vigente (fecha) · Acceso libre y señalizado · Manguera y boquilla · Tarjeta de control |

## 10. Entregas (por partes, cada una con tests y commit)
1. **Base**:
   - plantillas y versiones (constructor), precargadas, permisos;
   - hacer una inspección en la web con fotos;
   - resultado, acciones automáticas y aviso por falla crítica;
   - detalle, imprimible y ficha del equipo / QR.
2. **Programación y cumplimiento**:
   - programas y cron que genera las programadas, recordatorios y vencidas, omitir con motivo;
   - tablero de cumplimiento, CSV y recuadro "Inspecciones de hoy" en el inicio.
3. **App de campo**: QR → checklist offline, fotos por ítem, pendientes del día.

## 11. Tests
- Versionado: editar una plantilla usada crea otra versión y la inspección vieja sigue igual.
- El resultado se calcula en el servidor (`ok_when`, N/A, números fuera de rango).
- Una acción por ítem que no cumple, con su prioridad y plazo.
- Falla crítica: aviso inmediato a los supervisores del sector y a SyH.
- Inmutabilidad: hash y anulación con motivo.
- Generación del cron idempotente (correrlo dos veces no duplica).
- Equipos nuevos entran en el programa.
- Vencidas y recordatorios una sola vez.
- Cálculo de cumplimiento.
- Alcance por rol.
- Sync: `inspection.create` idempotente y fotos por partes con `item_key`.
- Aislamiento entre empresas.
