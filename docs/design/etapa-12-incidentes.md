# Etapa 12 — Incidentes, accidentes e investigación · Diseño

Estado: **aprobado** · entregas 1 y 2 hechas. Decisiones del usuario: datos de salud solo SyH y admin; investigación obligatoria en
accidentes y enfermedades; aviso crítico en accidentes con baja e in itinere; se agregan los datos para la ART.

## 1. Qué resuelve
Hoy un accidente se puede cargar como observación, pero faltan:
- los datos del accidentado y la lesión;
- los días perdidos y el seguimiento hasta el alta;
- la investigación de causas;
- la información para la denuncia ante la ART;
- las horas trabajadas, que son la base de los índices de frecuencia y gravedad (Etapa 16).

La Etapa 12 suma un módulo de **Incidentes** (el módulo de permisos `incidentes` ya existe).

## 2. Tipos
| Tipo | Ejemplo | ¿Investigación obligatoria? |
|---|---|---|
| `accidente_con_baja` | Corte en la mano, 5 días sin trabajar | sí |
| `accidente_sin_baja` | Golpe leve, vuelve a su puesto | sí |
| `in_itinere` | Accidente yendo o volviendo del trabajo | sí |
| `enfermedad_profesional` | Hipoacusia por ruido | sí |
| `incidente` | Daño material sin lesionados (choque de autoelevador contra una estantería) | opcional |
| `casi_accidente` | Cae una pieza del puente grúa sin golpear a nadie | opcional |

## 3. Tablas (base de cada empresa, migraciones tenant 0048+)

### `incidents`
- Número **INC-000001** por empresa, con `Sequences::next('incidents')`.
- Qué pasó:
  - `type`, `occurred_at` (hora del hecho), `reported_at`;
  - planta, sector, equipo, lugar y GPS;
  - `description` y `immediate_actions` (qué se hizo en el momento);
  - `potential_severity` (qué pudo haber pasado), del catálogo de severidad.
- Quién reportó: `reported_by`.
- Seguimiento: `status` y `closed_at`.
- **Evidencia inmutable**: `original_data` + `original_hash` (SHA-256), igual que las
  observaciones. Las correcciones de clasificación quedan como eventos con antes y después.

Estados:
```
reportado → en_investigacion → investigado → cerrado
     (anulado, con motivo — ej. se cargó dos veces)       cerrado → reabrir
```

### `incident_people`
Una fila por persona involucrada.
- **Quién es**: empleado propio o de contratista (`employee_id`), o un externo con nombre y DNI
  (visitante, transportista).
- **Rol**: `lesionado`, `testigo` o `involucrado`.
- **Lesión** (solo lesionados):
  - naturaleza de la lesión, parte del cuerpo y forma del accidente (catálogos nuevos);
  - agente material (máquina, herramienta o sustancia);
  - descripción y atención recibida: primeros auxilios, prestador de la ART, guardia u hospital.
- **Baja y seguimiento**:
  - `lost_time`, `leave_start`, `discharge_date` (alta médica) y `return_date` (reingreso);
  - `follow_up_status`: en tratamiento, alta, reingresó, con secuelas o reubicado.
- **ART**: `art_case_number` (N.° de siniestro que da la ART).
- **Días perdidos**: se calculan; no se cargan a mano. Son los días corridos desde el día siguiente
  al accidente hasta el alta. Mientras sigue de baja, se cuentan hasta hoy y se muestran como
  "provisorios".

### `incident_events`, `incident_attachments`
- Línea de tiempo (solo inserción).
- Fotos y documentos inmutables, con SHA-256: fotos del lugar, certificado médico, denuncia
  presentada.
- El seguimiento de cada lesionado (cambios de estado, alta, reingreso) queda como eventos.

### `incident_investigations`
Una por incidente:
- equipo investigador (usuarios), fecha de inicio y de fin;
- **5 porqués**: lista ordenada de preguntas y respuestas, en JSON;
- **árbol de causas**: nodos con texto y tipo (hecho, causa inmediata o causa básica), cada uno con
  su padre; en JSON y editable;
- **causas raíz**: del catálogo `causa` (ya existe), puede haber varias;
- **conclusiones** y **lecciones aprendidas**.

Las **acciones derivadas** son acciones CAPA (Etapa 10) con `origin_type = incidente`, creadas desde
la investigación con `ActionService`. Cuando todas quedan verificadas, el incidente queda listo
para cerrar.

### `worked_hours`
- Horas-hombre trabajadas y dotación promedio, **por mes y por planta**.
- Se cargan en Configuración. Son la base de los índices de la Etapa 16:
  - frecuencia = accidentes con baja × 1.000.000 / horas;
  - gravedad = días perdidos × 1.000 / horas;
  - incidencia = casos × 1.000 / trabajadores.

### Catálogos nuevos (precargados en la plantilla metalúrgica)
| Catálogo | Valores precargados |
|---|---|
| `lesion` (naturaleza) | Herida cortante, contusión, fractura, quemadura, esguince, cuerpo extraño en ojo, amputación… |
| `parte_cuerpo` | Cabeza, ojos, cuello, tronco, mano, dedos, pie… |
| `forma_accidente` | Caída a nivel, caída de altura, golpe contra, atrapamiento, contacto eléctrico, proyección de partícula… |

## 4. Datos para la denuncia ante la ART
No hay integración con la ART: el sistema arma una **página imprimible con todos los datos**, en el
orden en que se piden, para presentarla o copiarla al portal de la ART.

Contenido:
- empleador: razón social, CUIT, domicilio, actividad, ART y N.° de contrato;
- trabajador: apellido y nombre, DNI/CUIL, fecha de nacimiento, sexo, domicilio, puesto, antigüedad;
- siniestro: fecha y hora, lugar, tipo, in itinere, forma de ocurrencia, agente material,
  naturaleza de la lesión, zona del cuerpo, descripción, testigos;
- atención recibida.

Para el aviso y su plazo:
- Un recordatorio a SyH si a las **N horas** (configurable, 48 por defecto) no se cargó el N.° de
  siniestro de la ART.
- No asumimos el plazo legal exacto: la empresa lo ajusta según lo que le indique su ART.

✅ Se agregan (decidido) los datos que hoy faltan:
- en empleados: CUIL, fecha de nacimiento, sexo y domicilio;
- en la empresa: domicilio, actividad (CIIU), nombre de la ART y N.° de contrato.

## 5. Datos de salud (Ley 25.326)
Lesión, diagnóstico, atención médica y seguimiento son **datos sensibles**.
- ✅ Decidido: se agrega una acción de permiso **`datos_salud`**, que existe solo en el módulo
  incidentes.
  - La tienen SyH y el administrador.
  - El resto ve el incidente (qué pasó, dónde, acciones) pero no los datos médicos del lesionado.
  - El acceso a esos datos queda auditado.

## 6. Flujo y avisos
1. **Reporte rápido** (web o celular, también sin señal): tipo, cuándo, dónde (QR del equipo),
   qué pasó, quién se lastimó (buscador de empleados), qué se hizo en el momento y fotos. Lo puede
   hacer cualquiera con `incidentes.crear`, incluido el reportante.
2. **Aviso inmediato** (`incident.reported`) a los supervisores del sector y a SyH.
   - ✅ Decidido: accidentes con baja e in itinere = **aviso crítico**. No se puede silenciar y
     se manda en el momento, como el riesgo inminente.
3. **SyH completa**:
   - clasificación y gravedad potencial;
   - datos del lesionado y de la ART;
   - seguimiento hasta el alta.
4. **Investigación**: 5 porqués, árbol de causas, causas raíz y acciones derivadas.
   - Recordatorio si no empezó a los N días (configurable, 3 por defecto).
5. **Cerrar** (permiso `cerrar`):
   - ✅ Decidido: exige la investigación terminada en accidentes y enfermedades profesionales.
   - En incidentes y casi-accidentes la investigación es opcional.
6. **Seguimiento de bajas**: aviso semanal a SyH con los lesionados que siguen de baja sin alta.

Eventos nuevos: `incident.reported`, `incident.investigation_overdue`, `incident.art_pending`,
`incident.open_leaves` (resumen semanal) e `incident.closed`.

## 7. Pantallas del panel
- **`/panel/incidentes`**:
  - lista con filtros: tipo, estado, sector, fechas y "con baja abierta";
  - contadores por tipo;
  - **días sin accidentes con baja** (por planta).
- **Reportar** (`/panel/incidentes/nuevo`).
- **Detalle**:
  - datos del hecho, personas (los datos de salud solo con permiso), fotos y documentos;
  - investigación, acciones derivadas y línea de tiempo;
  - botones según el estado: investigar, cerrar, anular, reabrir.
- **Investigación**:
  - editor de 5 porqués (agregar o quitar pasos);
  - editor de **árbol de causas** (agregar causas debajo de un hecho; se muestra con sangría y
    también impreso);
  - selector de causas raíz;
  - "crear acción" desde una causa: abre el alta de acción con el origen y el sector ya cargados.
- **Imprimibles**: informe del incidente con su investigación, y datos para la denuncia ante la ART.
- **Configuración → Horas trabajadas**: grilla de meses × plantas (horas y dotación).
- **Inicio**: recuadro "Días sin accidentes con baja" y "Bajas abiertas" (para SyH).

## 8. App de campo (PWA)
- "Nuevo" suma un tercer botón: **Reportar incidente / accidente**.
- Formulario corto **offline**: tipo (botones grandes), cuándo, dónde (sector o QR del equipo), qué
  pasó, quién se lastimó (buscador de empleados sincronizados), qué se hizo y fotos.
- Se envía con `incident.create` (idempotente por uuid del celular), con fotos por partes.
- En "Reportes": mis incidentes reportados con su estado.
- La investigación y los datos médicos son solo web.

## 9. Entregas (cada una con tests y commit)
1. **Registro y seguimiento**:
   - tablas, catálogos y precargados, permisos (`datos_salud`), campos nuevos de empleados y empresa;
   - reportar (web), detalle, personas y lesión, seguimiento y días perdidos, adjuntos, eventos;
   - avisos (incluido el crítico), lista con filtros y "días sin accidentes";
   - imprimible del incidente y datos para la ART.
2. **Investigación y cierre**:
   - 5 porqués, árbol de causas, causas raíz y acciones derivadas;
   - cierre con las reglas de la sección 6;
   - recordatorios (investigación, N.° de siniestro de la ART, bajas abiertas);
   - horas trabajadas y CSV.
3. **App de campo**: reporte offline con fotos, mis incidentes y avisos.

## 10. Tests
- Numeración INC.
- Evidencia inmutable: hash y correcciones como eventos.
- Días perdidos: con alta, sin alta (provisorios hasta hoy) y reingreso.
- Datos de salud ocultos sin permiso (vistas, API e imprimibles).
- Aviso crítico según el tipo.
- Cierre bloqueado sin investigación cuando corresponde.
- Acciones derivadas con su origen.
- 5 porqués y árbol de causas: validación de la estructura (padres existentes, sin ciclos).
- Recordatorios una sola vez.
- Horas trabajadas únicas por mes y planta.
- Alcance por rol.
- Sync idempotente.
- Aislamiento entre empresas.
