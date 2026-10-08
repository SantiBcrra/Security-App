# Etapa 13 — Permisos de trabajo · Diseño

Estado: **aprobado** · entrega 1 hecha. Decisiones del usuario: autoriza quien tiene `aprobar` y nunca el solicitante; 12 h + una
extensión; gases fuera de rango = suspensión automática + aviso crítico; firma cada ejecutor.

## 1. Qué resuelve
Los trabajos de alto riesgo se hacen hoy con permisos en papel, que se pierden, se firman sin
controlar y no avisan cuándo vencen. La Etapa 13 suma un módulo de **Permisos de trabajo** (el módulo
de permisos `permisos_trabajo` ya existe) con:
- checklist previo obligatorio por tipo;
- firmas digitales inmutables;
- ventana de validez con vencimiento automático;
- un tablero de **permisos activos ahora**;
- uso desde el celular.

## 2. Tipos y controles propios
| Tipo | Controles específicos (además del checklist previo) |
|---|---|
| `altura` | Arnés y línea de vida, anclajes, andamio inspeccionado, área de exclusión debajo. |
| `caliente` (soldadura, corte, amolado) | Materiales combustibles retirados, extintor, **vigía de fuego** (persona y minutos de guardia después de terminar, 30 por defecto). |
| `espacio_confinado` | **Mediciones de gases** antes de entrar y periódicas (O₂ 19,5–23,5 %, explosividad < 10 % LIE, CO y H₂S con límites configurables), vigía en la entrada, plan de rescate. |
| `loto` (bloqueo y etiquetado) | **Registro de puntos de bloqueo**: energía (eléctrica, neumática, hidráulica, mecánica…), dispositivo y N.° de candado, quién lo colocó y quién lo retira; verificación de energía cero. |
| `electrico` | Tensión de trabajo, verificación de ausencia de tensión, EPP dieléctrico, distancias. |

- Cada tipo tiene un **checklist previo** que reutiliza el motor de checklists de la Etapa 11:
  - plantillas con el nuevo alcance `permiso`, versiones e ítems críticos;
  - las 5 plantillas vienen **precargadas** y cada empresa las ajusta;
  - un ítem crítico que no cumple **impide aprobar el permiso**.
- Un permiso puede combinar tipos, por ejemplo soldar en altura dentro de un tanque. En ese caso
  se completan los checklists de todos sus tipos.

## 3. Estados
```
borrador → solicitado → aprobado → en_ejecucion → cerrado
              ↘ rechazado            ↘ suspendido ⇄ en_ejecucion
   (aprobado / en_ejecucion / suspendido) → vencido   (cron, al pasar el fin de la validez)
   (borrador / solicitado / aprobado) → cancelado
```

- **Solicitar**:
  - el solicitante (supervisor o responsable del contratista) carga tipo(s), lugar, equipo,
    tarea, fecha y hora de inicio y fin, y ejecutores (empleados propios o de contratistas);
  - completa el checklist y **firma**.
- **Aprobar**: el autorizante revisa, puede pedir cambios (rechazar con motivo) o **aprueba
  firmando**.
- **Iniciar**:
  - en el lugar, los ejecutores **firman** que fueron informados de los riesgos y las medidas;
  - en espacio confinado hace falta además una medición de gases en rango;
  - recién ahí pasa a `en_ejecucion`.
- **Suspender**: cualquiera del equipo, el vigía o SyH puede frenar el trabajo (alarma, cambio
  de condiciones, lluvia). Se reanuda con una nueva verificación.
- **Cerrar**:
  - el responsable confirma que el área quedó segura: herramientas retiradas, candados LOTO
    retirados, fin de la guardia de fuego;
  - firma, y el autorizante firma la recepción.
- **Vencer**:
  - el cron marca `vencido` cuando pasa el fin de la validez y avisa;
  - un permiso vencido no se reanuda: se pide otro, o se **extiende antes de que venza**
    (ver la sección 4).

## 4. Validez
- ✅ Decidido:
  - duración máxima **12 horas** (configurable por tipo), dentro de la misma jornada;
  - **una extensión** de hasta otras 12 horas, con la firma del autorizante, antes de que venza.
- Aviso 30 minutos antes del vencimiento al responsable y al autorizante.
- **Conflictos**:
  - se avisa (sin bloquear) si hay otro permiso activo en el mismo equipo o sector;
  - ejemplo: un trabajo en caliente cerca de un espacio confinado con medición de gases.

## 5. Firmas digitales
- Se firman en pantalla, con dedo o mouse, en un **canvas** que guarda la firma como imagen PNG.
- Se guardan con `TenantFiles`, SHA-256, hora, usuario o persona, IP, dispositivo y GPS.
- Son **inmutables**: una firma no se edita. Si se rechaza o cambia algo, se firma de nuevo.
- Tabla `work_permit_signatures`:
  - `role` (`solicitante`, `autorizante`, `ejecutor`, `vigia`, `cierre`, `recepcion`, `extension`);
  - quién firmó (usuario, o empleado o externo con nombre y DNI);
  - imagen, hash y datos del momento.
- ✅ Decidido: **cada ejecutor firma**, también los de contratistas que no tienen usuario. Firman
  en persona, en el celular o tablet del supervisor, con su nombre y DNI.

## 6. Permisos (módulo `permisos_trabajo`)
| Acción | Para qué |
|---|---|
| ver | Ver permisos (alcance todo / sectores / propios) |
| crear | Solicitar |
| editar | Modificar mientras está en borrador o rechazado; registrar mediciones |
| cerrar | Cerrar, cancelar, suspender o reanudar |
| exportar | CSV |
| **aprobar** (nueva) | Autorizar permisos y extensiones |

- ✅ Decidido:
  - `aprobar` es una acción que existe solo en este módulo; la tienen SyH y el administrador, y la
    empresa puede dársela a jefes de planta;
  - **quien solicita no puede aprobar su propio permiso**.

## 7. Tablas (base de cada empresa, migraciones tenant 0054+)
- **`work_permits`**:
  - número PT-000001, tipos (JSON, por ser una combinación), estado;
  - planta, sector, equipo, lugar y GPS;
  - tarea, inicio y fin de la validez, extensión;
  - solicitante, autorizante, contratista;
  - vigía de fuego (persona y minutos) y motivos (rechazo, suspensión, cierre);
  - `original_data` + hash: lo que se aprobó queda congelado.
- **`work_permit_workers`**: ejecutores (empleado o externo) y vigías.
- **`work_permit_checklists`**:
  - un checklist por tipo, con la versión de la plantilla y las respuestas;
  - se guardan como las inspecciones (`InspectionStructure::evaluate()`).
- **`work_permit_measurements`**: mediciones de gases (hora, O₂, LIE, CO, H₂S, otros, quién midió,
  instrumento, resultado).
- **`work_permit_isolations`** (LOTO): punto, energía, dispositivo y candado, colocado por y a qué
  hora, retirado por y a qué hora.
- **`work_permit_signatures`** y **`work_permit_events`**: firmas y línea de tiempo, de solo
  inserción.

## 8. Avisos (motor de la Etapa 7)
- `permit.requested`: a los autorizantes.
- `permit.approved` / `permit.rejected`: al solicitante.
- `permit.expiring`: 30 minutos antes, al responsable y al autorizante.
- `permit.expired`.
- `permit.suspended`: a SyH, al autorizante y al supervisor del sector.
- ✅ Decidido: **medición de gases fuera de rango** = **suspensión automática + aviso CRÍTICO**
  (no se puede silenciar), igual que el riesgo inminente.

## 9. Pantallas del panel
- **Permisos activos ahora** (`/panel/permisos`): tarjetas por planta con tipo, lugar, ejecutores,
  hora de vencimiento (en rojo si falta poco) y estado. Se actualiza solo cada minuto, para
  dejarlo en una pantalla de la sala de control.
- **Lista** con filtros (tipo, estado, sector, contratista y fechas) y **CSV**.
- **Solicitar**: asistente en pasos (tipo y lugar → tarea y validez → ejecutores → checklists →
  firma).
- **Detalle**:
  - datos, checklists, ejecutores con sus firmas, mediciones y puntos de bloqueo;
  - línea de tiempo y botones según el estado;
  - aviso de conflictos.
- **Imprimible para colgar en el lugar del trabajo**:
  - con un **QR** que, al escanearlo, muestra si el permiso **sigue vigente**;
  - el QR lleva a una página que pide login, nunca pública.

## 10. App de campo (PWA)
- **Mis permisos**: los que solicité, debo autorizar o en los que trabajo.
- **Firmar en el celular**, también ejecutores de contratistas sin usuario, en persona.
- **Iniciar, suspender y cerrar.**
- **Registrar mediciones de gases** y puntos de bloqueo **sin señal**. Si la medición está fuera de
  rango, la app avisa en el momento ("salir del espacio") aunque no haya señal.
- **Escanear el QR** del permiso colgado: muestra si está vigente y quién trabaja.
- La aprobación desde el celular requiere conexión, para asegurar que el autorizante ve lo último.

## 11. Entregas (cada una con tests y commit)
1. **Base**:
   - tablas, tipos, checklists precargados (alcance `permiso`) y permiso `aprobar`;
   - solicitar (web), checklists, firmas en canvas, aprobar o rechazar, iniciar, cerrar, cancelar;
   - validez y vencimiento por cron, aviso de vencimiento;
   - "Permisos activos ahora", detalle e imprimible con QR, avisos.
2. **Controles específicos**:
   - mediciones de gases con suspensión automática, registro LOTO y vigía de fuego;
   - suspender y reanudar, extensión, conflictos;
   - lista, CSV y "Mis permisos" en el inicio.
3. **App de campo**: mis permisos, firmas en el celular, iniciar, suspender y cerrar, mediciones y
   LOTO offline, QR de verificación.

## 12. Tests
- Transiciones válidas e inválidas.
- Solicitante ≠ autorizante.
- No se aprueba con un ítem crítico del checklist sin cumplir.
- Firmas: hash, inmutables, faltantes que impiden iniciar.
- Validez: máximo, extensión única y vencimiento por cron una sola vez.
- Mediciones fuera de rango → suspensión y aviso crítico.
- LOTO: no se cierra con candados sin retirar.
- Conflictos.
- Alcance por rol.
- Imprimible y QR.
- Sync idempotente.
- Aislamiento entre empresas.
