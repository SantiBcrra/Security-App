# CLAUDE.md — Security App

Sistema de Seguridad e Higiene, SaaS multi-empresa. El plan por etapas está en [PLAN.md](PLAN.md).
Este archivo manda sobre PLAN.md cuando hay diferencias (ver "Decisiones").

## Regla de oro de despliegue
Se instala igual en XAMPP que en el hosting: copiar archivos → crear base vacía en el panel →
abrir `/install` en el navegador. PROHIBIDO depender de consola en el servidor, Composer en el
servidor, workers, Redis, Node, config de Apache fuera de `.htaccess` o extensiones raras.

## Entornos
| | Producción (exclusivehosting) | Local (XAMPP Mac) |
|---|---|---|
| PHP | 8.4.25 | 8.2.4 |
| Base | MySQL 5.7.27 | MariaDB 10.4.28 |
| URL | (a definir) | http://localhost/securityapp/ |

Local: symlink `/Applications/XAMPP/htdocs/securityapp → ~/Desktop/Security App`.

## Decisiones (cambian el PLAN.md original)
- **Una base de datos por empresa, 100% independiente.** Base maestra (empresas, super-admin,
  credenciales de cada base) + una base por empresa. NO hay `tenant_id` ni filtros por empresa:
  el aislamiento lo da la conexión (`DB::master()` / `DB::tenant()`). Se esperan 2–3 empresas.
- **Sin dependencias externas por ahora**: sin Composer ni librerías. JWT propio (HS256 con
  `hash_hmac`) para la app móvil. Tests con runner propio (`tests/run.php`).
- `/install` lo atiende el front controller; el diagnóstico vive en `public/check.php` y se
  publica en `/install/check.php` (regla en `public/.htaccess`). No crear carpetas reales en
  `public/` con el mismo nombre que una ruta (Apache redirige agregando `/public/`).
- Migraciones en dos carpetas: `database/migrations/master` y `database/migrations/tenant`.
- Instalado = existe `storage/installed.lock`. Sin él, todo redirige a `/install`
  (middleware `RequireInstalled`). Con él, `/install` y `/install/check.php` dan 403.
- Super-admins de la plataforma (`platform_admins`, base maestra) son distintos de los usuarios
  de empresas (Etapa 3). Login con bloqueo: 5 fallos por email+IP o 20 por IP en 15 min.

## Empresas (Etapa 2)
- Registro en la maestra (`tenants`). Cada empresa: base propia (`securityapp_{slug}` en modo
  automático, o una base creada en el panel del hosting). La contraseña de esa base se guarda
  cifrada con `Crypto` (AES-256-GCM, clave `app.key`).
- ⚠️ **Respaldar `config/config.local.php`**: sin su `app.key` no se pueden descifrar las
  contraseñas de las bases de empresa ni validar links firmados.
- Activar empresa = `Tenant::activate($row)` → conecta `DB::tenant()` a SU base. Empresa
  suspendida → `TenantSuspended`. Las consultas de negocio usan `DB::tenant()`, nunca la maestra.
- Área de empresa en **`/panel`** (middlewares `RequireTenant` + `ReadOnlyImpersonation`).
- **URLs reservadas**: ninguna ruta puede empezar con `/app`, `/config`, `/database`, `/storage`,
  `/tests` o `/vendor` (el `.htaccess` raíz las bloquea con 403 porque son carpetas internas).
- Impersonación del super-admin ("Entrar como empresa"): solo lectura, auditada al entrar y salir.
- Auditoría: `Audit::platform()` (maestra, `platform_audit_log`) y `Audit::tenant()` (base de la
  empresa, `audit_log`). Solo inserción; guardar antes/después de lo que cambia.
- Archivos de empresa: `TenantFiles` (storage/tenants/{uuid}/…, MIME real con finfo, nombre UUID).
  Nunca se sirven directo: controlador con permisos o `SignedUrl::make()` (link temporal).

## Usuarios, roles y permisos (Etapa 3)
- **Usuarios y roles viven en la base de cada empresa** (como `system_users`/`roles` del ERP).
  La maestra solo tiene empresas y super-admins. Una persona en dos empresas = dos cuentas.
- Login web: `/login` (empresa + email o DNI + contraseña), `/login/{slug}`; la última empresa
  se recuerda en una cookie. 2FA TOTP opcional (`Totp`, secreto cifrado con `Crypto`).
- Alta de usuarios por invitación: link `/activar/{slug}/{token}` (token hasheado, 72 h, un uso),
  para copiar o mandar por WhatsApp (`wa.me`). Nadie más conoce la contraseña. El primer admin
  de cada empresa lo crea el super-admin desde el detalle de la empresa.
- Permisos: registro único en `app/Policies/Permissions.php` (módulos × acciones + alcance
  todo/sectores/propios). Roles base `is_system` no editables: se copian y se ajusta la copia.
- En código: `UserAuth::can('modulo', 'accion')`, `UserAuth::scope('modulo')`; por ruta:
  `new RequirePermission('modulo', 'accion')`. El menú de `/panel` se arma con `can()`.
- El usuario se recarga desde la base en cada request: desactivarlo, vencerle el acceso o
  cambiarle el rol tiene efecto en el próximo click. No se puede dejar la empresa sin admin.
- API app móvil: `POST /api/v1/auth/login|refresh|logout`, `GET /api/v1/me`. Access JWT propio
  (`Jwt`, HS256, 15 min) + refresh rotativo por dispositivo (`{uuid empresa}.{aleatorio}`, 60 días,
  se guarda su SHA-256). Reutilizar un refresh viejo revoca el dispositivo. Middleware
  `RequireApiUser`.

## Datos maestros (Etapa 4)
- Plantas (`sites`), sectores (`sectors`, jerarquía planta > nave > sector, máx. 3 niveles,
  `path` materializado `/3/7/12/`), puestos, contratistas, empleados (propios o de contratista),
  equipos y catálogos (`catalog_items`, una tabla con `catalog` = tipo_riesgo | categoria |
  severidad | causa | tipo_equipo; definidos en `app/Services/Catalogs.php`).
- Todas las tablas maestras: `uuid`, `is_active`, `updated_at`, `deleted_at` (para la sync).
  Nada se borra: se desactiva.
- CRUD genérico: cada entidad es un `App\Resources\*Resource` (campos, columnas, validación);
  lo atiende `MasterDataController` en `/panel/datos/{recurso}`. Modelos sobre `Models\Repository`.
  En formularios las referencias viajan como UUID, nunca como id interno.
- Alcance por sector: `SectorScope::sectorIds($modulo)` → null (todo) o ids permitidos
  (sectores del usuario + descendientes). Asignación en el formulario de usuario (`user_sectors`).
- Plantillas de rubro: `database/seeds/templates/{clave}.php`, idempotentes (`IndustryTemplates`).
- QR de equipos: `/q/{uuid}` (con sesión → ficha de campo `/panel/equipo/{uuid}`, Etapa 11; sin sesión → login y vuelve). Etiquetas A4 en
  `/panel/datos/equipos/etiquetas`. QR generado en el navegador con `qrcode.min.js` local.
- Importación: `Core\Spreadsheet` (CSV y XLSX propios, sin librerías; `.xls` no) +
  `Services\Import\Importer` (subir → mapeo por sinónimos → vista previa → lotes de 100 por AJAX,
  reanudable). Un importador por entidad en `app/Services/Import/`. Empleados por DNI, equipos por
  código; en una actualización no se borran datos que el archivo trae vacíos.

## Observaciones (Etapa 6)
- Tablas `observations`, `observation_events` (línea de tiempo, solo inserción),
  `observation_attachments`, `observation_people`, `sequences` (número OBS-000001 por empresa con
  `SELECT … FOR UPDATE`; `Sequences::next()` exige transacción abierta).
- **Evidencia inmutable**: `original_data` (JSON del reporte tal como llegó) + `original_hash`
  (SHA-256) nunca se actualizan (`Observations::update()` los descarta). Las columnas de
  clasificación son el valor vigente; una corrección = evento `correction` con antes/después.
  Fotos: el archivo recibido no se modifica (sha256 + EXIF guardados); miniatura aparte.
- Flujo en `ObservationWorkflow` (abierta → en_analisis → accion_asignada → cerrada; descartada;
  reabrir). Toda transición pasa por `ObservationService::transition()` (permiso + estado bloqueado).
- Alcance: `ObservationService::scope()` / `canView()` (propios, sectores + lo propio, todo).
- Anónimo (setting `observaciones.anonimo_habilitado`): sin autor, sin auditoría que lo vincule.
- Riesgo inminente: evento `imminent_alert` + `ObservationAlerts::imminent()` (Etapa 7 envía avisos).
- Mapas: Leaflet local (`public/assets/vendor/leaflet`) + tiles de OpenStreetMap (permitidos en la CSP).
- "PDF" = página imprimible (`/imprimir`), sin librerías.
- Datos demo SOLO en local (las pruebas e2e lo sobrescriben: hacer backup de `storage/demo-links.txt`): `php tools/seed-demo.php {empresa} http://localhost/securityapp`
  (aplica migraciones pendientes, solo agrega, links de activación en `storage/demo-links.txt`).
  `tools/` está bloqueado por web y excluido del deploy.

## Notificaciones (Etapa 7)
- Disparar SIEMPRE con `Notify\Notifier::dispatch($evento, $sujeto)` (`$sujeto` = fila de observación, o de acción
  si el evento empieza con `action.`; `Notifier::subject()` la relee y normaliza responsable/creador/sector/nivel): aplica las reglas de la
  empresa (`notification_rules`), crea los avisos en la app (`notifications`) y encola email/push/
  WhatsApp (`notification_queue`, también es el log). Nunca rompe la operación que lo llama.
- Eventos en `Notify\Messages::EVENTS`; críticos (`CRITICAL`): no se pueden silenciar y se envían
  en el mismo request. Quien hace la acción no recibe su propio aviso (salvo críticos).
- Riesgo inminente → `Escalations::open()` crea la alerta (`alerts`); sin "Recibido" en N minutos
  (setting `notif.escalation_minutes`, 15) sube a nivel 1 y 2 avisando a SyH + admins.
- Sin workers: `/cron/run?key=…` (`CronRunner::key()` derivada de app.key; ver /admin/tareas)
  corre cola, escalamientos, vencidas (una vez por día, `notification_marks`) y resúmenes
  (diario 8 h, semanal lunes). Lock `GET_LOCK` por empresa. Lazy cron solo con PHP-FPM.
- Email sin librerías: `Core\Smtp` + `Core\MailMessage`. Transporte "archivo" (por defecto,
  `storage/mail/*.eml`, visible en /admin/tareas) o "smtp" (config en /admin/configuracion,
  contraseña cifrada en `platform_settings`). Push = API HTTP de Expo; WhatsApp = Meta Cloud API
  con plantilla de 2 parámetros (deshabilitado hasta configurarlo).
- En tests: `MailTransport::$fake` y `Channels::$fakeExternal` (no sale nada a internet).

## API de sincronización y app de campo (Etapas 5 y 8)
- Contrato en `docs/api/openapi.yaml`. Pull por cursor `(updated_at, id)` por entidad con ventana de
  5 s para cambios recientes (`Sync\Pull`); push de hasta 50 operaciones idempotentes por `op_id`
  (`idempotency_keys`) y por uuid del celular (`Sync\Push`); fotos por partes con SHA-256
  (`Uploads`, tabla `uploads`); rate limit por dispositivo (`RateLimiter`, tabla `rate_limits`).
- La validación del alta de observaciones es una sola: `ObservationInput::validate()` (web y API).
- **La app de campo es una PWA** en `/movil/` (decisión del usuario: no Expo por ahora). Shell,
  service worker y manifest los sirve `MobileController`; JS en módulos nativos sin compilar en
  `public/assets/movil/` (`db.js` IndexedDB, `api.js` JWT, `sync.js` outbox, `app.js` Alpine).
  Al cambiar cualquier archivo de la app cambia la versión del SW y se actualiza sola.
- PWA = contexto seguro: HTTPS o `localhost`. Probar en el celular contra XAMPP por Wi-Fi requiere
  `chrome://flags` → "Insecure origins treated as secure" con `http://IP-de-la-Mac`. El navegador
  integrado de la app de escritorio NO soporta service workers (probar instalación/push en Chrome).
- Web Push propio: `Core\Ece` (aes128gcm, RFC 8291 — test con el vector oficial) y `Core\Vapid`
  (ES256). Claves VAPID en `platform_settings` (privada cifrada). El canal "push" de la Etapa 7
  envía a suscripciones web (`push_subscriptions`, destino `web:{id}`) y a tokens Expo futuros.
- Prueba de estrés: `php tools/sync-stress.php http://localhost/securityapp {empresa} {usuario} {clave} 500 10`.

## Rondas de guardias (Etapa 9 adelantada)
- Puntos de ronda en `patrol_points`, con QR público, coordenadas, radio permitido y marca de punto crítico.
- Rutas y orden de puntos en `patrol_routes` / `patrol_route_points`; ejecuciones y escaneos en `patrol_rounds` / `patrol_scans`.
- Panel `/panel/rondas` para alta de puntos, impresión de QR e historial. El QR apunta a `/ronda/punto/{uuid}` y abre la PWA.
- Cada escaneo guarda hora del dispositivo, hora de recepción, GPS, precisión, distancia calculada y si quedó dentro del radio. No se duplica un punto dentro de la misma ronda.
- La PWA guarda rondas y escaneos en IndexedDB y los envía con `round.start`, `round.scan` y `round.finish`; las operaciones son idempotentes por `op_id`.
- El mapa operativo usa Leaflet/OpenStreetMap ya incluido; no se agrega dependencia de Google Maps.
- Rutas: al celular llegan solo las asignadas (`patrol_route_assignments`; alcance "todo" ve todas), con `points` = UUIDs en
  orden; las no asignadas viajan como baja. **Cambiar asignaciones o puntos de una ruta debe tocar `patrol_routes.updated_at`.**
  Iniciar una ruta no asignada se rechaza. Al finalizar: `completa` o `incompleta` (puntos salteados).
  Detalle en `/panel/rondas/ronda/{uuid}` (recorrido, salteados, fuera de radio). Con alcance "propios" el panel muestra solo las propias.
- `round.start` es idempotente también por uuid de la ronda; el escaneo valida punto activo, uuid y hora del
  celular (máx. 30 días atrás). La PWA recupera la ronda en curso (`mine` + `en_curso`) al reabrir.

## Acciones CAPA (Etapa 10) — diseño en `docs/design/etapa-10-capa.md`
- Tablas `actions` (número ACC-000001 con `Sequences::next('actions')`, `origin_type`/`origin_id`), `action_events`
  (solo inserción) y `action_attachments` (evidencia inmutable, fotos o PDF, por `cycle`).
- Flujo en `ActionWorkflow` (abierta → en_curso → cerrada → verificada; cancelada; rechazar vuelve a en_curso y sube
  `cycle`). Todo pasa por `ActionService` (`transition`, `update`, `comment`, `addEvidence`). "Vencida" se calcula
  (`due_on` < `ActionService::today()`, fecha local de la empresa), no es un estado.
- Cerrar exige texto + ≥1 evidencia del ciclo actual. Verificar/rechazar: permiso `verificar` (solo existe en el
  módulo acciones: `Permissions::MODULE_ONLY_ACTIONS` / `actionsFor()`) y nunca quien cerró.
- El responsable siempre ve, toma y cierra la suya aunque su rol no tenga el módulo (`ActionService::scope()` sin
  permiso = solo las suyas; el menú "Acciones" aparece si tiene alguna abierta).
- Otros módulos crean acciones con `ActionService::validate()` + `create($data, $origen, $id)` (o `insert()` dentro de
  su transacción + `afterCreate()` después del commit). `syncOrigin()` avisa al origen.
- Observaciones: "Asignar acción" crea una acción (puede haber varias). `assigned_user_id`/`action_text`/`action_due_on`
  de la observación son ahora un **resumen** de la acción abierta que vence primero (`ObservationService::syncActions()`);
  no escribirlos a mano. Cuando todas quedan verificadas/canceladas, la observación se cierra sola (evento "Sistema").
- Avisos de acciones (`Messages::EVENTS` `action.*`; destinatario nuevo `verifiers` = permiso acciones.verificar):
  los dispara `ActionService` (asignar/reasignar, cerrar, verificar, rechazar, cancelar) y el cron con
  `Notify\ActionReminders::run()` (por vencer N días antes, vencida 1 vez por día, escalamiento a supervisores del
  sector + SyH al cumplir M días, verificación atrasada). Settings `acciones.aviso_dias`, `acciones.escalar_dias`,
  `acciones.dias_verificacion` en /panel/configuracion/notificaciones. Los eventos viejos `observation.assigned/overdue`
  ya no existen (migración 0041).
- Tablero `/panel/acciones/tablero` (`Actions::board()`, con alcance), CSV `/panel/acciones/exportar` (";" + BOM,
  permiso exportar), imprimible `/panel/acciones/{uuid}/imprimir`, "Mis acciones pendientes" en el inicio.
- App de campo: entidad `actions` en `Sync\Pull` (alcance en PHP con `ActionService::canView`; si deja de verla, baja),
  operaciones `action.start` / `action.close` en `Sync\Push`, evidencia por partes con `POST /uploads` + `action_uuid`.
  En `sync.js` el cierre espera a que suban sus fotos (dos pasadas de `pushOps`); "Mis acciones" en la pestaña Reportes y
  detalle `#/accion/{uuid}`. Reintentar un envío rechazado genera otro op_id (el servidor recuerda la respuesta de cada uno).

## Inspecciones y checklists (Etapa 11) — diseño en `docs/design/etapa-11-inspecciones.md`
- Plantillas `inspection_templates` (alcance equipo [por tipo] / sector / general) con versiones
  `inspection_template_versions.structure` (JSON: secciones → ítems con `key` UUID estable, type si_no | si_no_na |
  numero | texto, `ok_when`, min/max, critical, photo). `InspectionTemplateService::save()`: si la versión vigente ya
  se usó crea otra, si no la corrige. Precargadas por rubro en `database/seeds/templates/{rubro}.php` → `inspections`
  ("*" = crítico), idempotentes por `preset_key` (`IndustryTemplates::applyInspections()`).
- `InspectionStructure::evaluate()` calcula SIEMPRE en el servidor ok/resultado/score; comentario obligatorio si no
  cumple; foto según `photo`. `InspectionService::create()`: número INS-000001, respuestas + `original_data`/hash
  inmutables (solo se anula, con motivo), una acción CAPA por ítem que no cumple (`origin_type = inspeccion`; crítico
  → prioridad crítica, plazo `inspecciones.plazo_critico_dias` 1; si no `inspecciones.plazo_dias` 7; responsable:
  supervisor del sector, si no quien inspeccionó) y evento `inspection.critical_fail` (supervisores + SyH).
  Idempotente por `uuid` (para la app de campo).
- Decisiones del usuario: falla crítica = solo aviso (el equipo NO queda fuera de servicio), el operario (reportante)
  hace el pre-uso (`inspecciones` ver + crear propios), sin turnos por ahora.
- El QR `/q/{uuid}` lleva a la ficha de campo `/panel/equipo/{uuid}` (cualquier usuario): checklists de su tipo,
  últimas inspecciones, reportar observación.
- Programas (`inspection_programs`, servicio `InspectionPlanner`): checklist + objetivo (un equipo / todos los de un
  tipo, opcionalmente de un sector / un sector) + frecuencia (diaria, semanal con día, mensual con día; manual = sin
  programar) + a cargo (usuario / rol / supervisores del sector) + responsable de las acciones. El cron
  (`Notify\InspectionReminders::run()` en `CronRunner`) genera `inspection_schedule` de hace 2 días a +6 (único por
  programa + `target_key` + `period_key`: idempotente; equipos nuevos entran solos) y avisa `inspection.due` el día
  que vence y `inspection.overdue` una vez al vencer (destinatario `assignee` = `assignee_ids` de la programada).
- Al guardar una inspección se marca la programada indicada (`schedule` uuid) o la que corresponda
  (`InspectionSchedules::matchFor`: misma plantilla y objetivo, ventana habilitada, la más vieja); `on_time` = hecha
  hasta `due_on`. Omitir con motivo (permiso cerrar). Cumplimiento = a tiempo / (vencidas − omitidas), por mes y por
  programa / sector / equipo / inspector (`/panel/inspecciones/cumplimiento`, CSV). "Inspecciones de hoy" en el inicio.
- App de campo: pull de `inspection_templates` (estructura de la versión vigente; solo a quien puede crear) e
  `inspection_schedule` (solo pendientes a su cargo; hecha/omitida/ajena = baja). Push `inspection.create` (uuid del
  celular, `version`, `schedule`, `answers`, `photo_counts` = fotos que va a subir: valida las obligatorias). Fotos por
  partes con `inspection_uuid` + `item_key` (solo el inspector). Pestaña "Nuevo" → Reportar observación | Hacer
  inspección (QR del equipo, lista de equipos, recorridas por sector, "para hoy"); detalle `#/inspeccion/{uuid}`
  (`GET /api/v1/inspections/{uuid}`); el aviso "para hoy" abre `#/programada/{uuid}`.

## Incidentes y accidentes (Etapa 12) — diseño en `docs/design/etapa-12-incidentes.md`
- `incidents` (INC-000001, tipos en `IncidentService::TYPES`: accidente con/sin baja, in itinere, enfermedad profesional,
  incidente, casi-accidente; `original_data`/hash inmutables, correcciones = evento `correction`), `incident_people`
  (empleado o externo; rol lesionado/testigo/involucrado; lesión con catálogos `lesion`, `parte_cuerpo`,
  `forma_accidente`; baja/alta/reingreso; N° de siniestro ART), `incident_events`, `incident_attachments`.
- **Datos de salud** (lesión, atención, baja, alta, documentos médicos, eventos de seguimiento con `health = 1`): solo con
  `incidentes.datos_salud` (acción que existe solo en ese módulo; SyH y admin). Ver el detalle o la denuncia con esos
  datos queda auditado (`incident.health_view`, `incident.art_print`). Los avisos nunca llevan datos de salud.
- Días perdidos: se calculan (`IncidentService::lostDays`): desde `leave_start` hasta el alta sin contarla; sin alta,
  hasta hoy inclusive (provisorios). Accidente con baja: la baja arranca por defecto al día siguiente del hecho.
- Avisos: `incident.serious` (CRÍTICO: con baja e in itinere) / `incident.reported` a supervisores del sector + SyH;
  `incident.closed` a quien reportó. Cerrar exige investigación terminada (estado `investigado`) en accidentes y
  enfermedades (`TYPES[...]['investigation']`); la investigación llega en la entrega 2.
- Denuncia ART: imprimible `/panel/incidentes/{uuid}/art/{persona}` con empleador (razón social y CUIT del alta de la
  empresa + `empresa.*` en /panel/configuracion) y trabajador (empleados: `cuil`, `birth_date`, `gender`, `address`).
- "Días sin accidentes con baja" (`Incidents::daysWithoutLostTime`) y "bajas abiertas" en el inicio.
- Investigación (`incident_investigations`, servicio `IncidentInvestigation`): reportado → (empezar) en_investigacion →
  (terminar: porqués o árbol + ≥1 causa raíz + conclusiones) investigado; reabrir. 5 porqués (JSON), árbol de causas
  (JSON de nodos id/parent/text/type hecho|causa_inmediata|causa_basica; `normalize()` descarta vacíos, huérfanos → raíz,
  rechaza ciclos; `flatten()` para mostrar), causas raíz del catálogo `causa`, acciones derivadas con
  `createAction()` (`origin_type = incidente`). Reabrir un incidente cerrado con investigación vuelve a en_investigacion.
- Recordatorios (`Notify\IncidentReminders` en el cron): investigación sin empezar a `incidentes.dias_investigacion` (3),
  lesionado sin N° de siniestro a `incidentes.horas_art` (48, ajustable a la ART) y los lunes resumen de bajas
  abiertas (`incident.open_leaves`, sin nombres). Plazos en /panel/configuracion/notificaciones.
- Horas trabajadas `worked_hours` (planta × mes; vacío = se borra) en `/panel/incidentes/horas`, con índices del año
  (`IncidentIndicators`: IF, IG, II; in itinere aparte; días perdidos al mes del accidente). CSV de incidentes
  (días perdidos solo con datos de salud).
- App de campo: "Nuevo" → Observación | Inspección | Incidente / accidente (formulario offline: tipo, cuándo, sector o QR,
  personas buscando en `employees` sincronizados o externos, qué pasó, fotos). Push `incident.create` (idempotente por
  uuid), fotos por partes con `uploads.incident_uuid` (solo quien lo reportó). Pull `incidents`: solo los que reportó
  el usuario, sin datos de salud; detalle `GET /api/v1/incidents/{uuid}` (nunca datos de salud). "Mis incidentes" en
  Reportes, detalle `#/incidente/{uuid}`.

## Migraciones
- Archivo nuevo = siguiente número: `database/migrations/{master|tenant}/0004_descripcion.sql`.
  Nunca editar una migración ya aplicada: se crea otra.
- `.sql` con sentencias separadas por `;` (sin `DELIMITER`: nada de triggers ni procedures).
  `.php` que devuelve `function (PDO $db): void` para migraciones de datos.
- Siempre idempotentes (`CREATE TABLE IF NOT EXISTS`, etc.): MySQL no tiene DDL transaccional;
  si una falla se corta ahí y al corregirla se reintenta desde ese archivo.
- Tablas: `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`, fechas `DATETIME`
  en UTC (`UTC_TIMESTAMP()`), índices con nombre `idx_tabla_campos` / `uq_tabla_campos`.
- Se aplican desde el panel: `/admin/migraciones` → "Actualizar base de datos"
  (`Migrator::runAll()`, con lock `GET_LOCK` por base).

## Convenciones de código
- PHP **8.2** como mínimo común (nada de 8.3/8.4). `declare(strict_types=1)`, namespace `App\`
  → `/app` (autoload propio en `app/bootstrap.php`).
- SQL compatible con **MySQL 5.7 y MariaDB 10.4**: sin CTE (`WITH`), sin window functions, sin
  `UUID_TO_BIN`; JSON en columnas `TEXT`/`LONGTEXT` (se lee en PHP); `DATETIME` (no `TIMESTAMP`);
  `utf8mb4` + `utf8mb4_unicode_ci`; InnoDB; consultas compatibles con `ONLY_FULL_GROUP_BY`.
- Siempre PDO con consultas preparadas. Nada de SQL en controladores: va en Models/Services.
- Fechas en **UTC** en la base y en PHP; se muestran en la zona de la empresa con `fecha()`.
- IDs públicos = UUID generados en PHP (o en el celular). PK interna INT AUTO_INCREMENT.
- Vistas: escapar SIEMPRE con `e()`. URLs con `url()`, assets con `asset()`.
- API: respuestas `{ ok, data, error }` con `Response::json()` / `Response::jsonError()`.
- Errores de validación con mensaje para el usuario: `throw new Core\UserError('…')` (la sync los
  devuelve tal cual; cualquier otra excepción se loguea y se responde con un error genérico).
- Evidencia original (fotos, firmas, reporte inicial) nunca se edita: enmiendas como eventos.
- Textos de la interfaz en español (preparados para i18n).
- Assets locales en `public/assets` (Bootstrap 5.3.3, Alpine 3.14.1). Nada de CDN.

## Estructura
```
public/            docroot (index.php front controller, assets/, check.php)
app/Core/          App, Router, Request, Response, View, DB, Config, ErrorHandler, Logger, Session,
                   Storage, Migrator, Csrf, Flash, Validator, Uuid, EnvCheck, Tenant, Crypto,
                   SignedUrl, TenantFiles, Cuit, Jwt, Totp, helpers
app/Middleware/    RequireInstalled, VerifyCsrf (globales), RequireSuperAdmin, RequireTenant,
                   ReadOnlyImpersonation, RequirePermission, RequireApiUser
app/Policies/      Permissions (módulos, acciones, alcances y roles base)
app/Controllers/   Web/ (Install, Admin/*) y Api/
app/Models/        acceso a datos (único lugar con SQL)
app/Services/      lógica (AdminAuth, UserAuth, ApiAuth, UserInvitation, Audit,
                   TenantProvisioner, Impersonation)
app/Views/         layouts/, errors/
app/routes.php     declaración de rutas
config/            config.php (git) + config.local.php (NO git, lo genera el instalador)
database/          migrations/{master,tenant}, seeds
storage/           logs, sessions, cache, tenants (no se sube por deploy; se crea solo)
tests/             run.php + *Test.php
```

## Comandos locales
```bash
/Applications/XAMPP/xamppfiles/bin/php tests/run.php
```
- Los tests de migraciones crean y borran la base `securityapp_test` (root sin contraseña en
  127.0.0.1; se saltean si no hay MySQL). Los tests usan un storage temporal, no el real.
- En XAMPP Apache corre como `daemon`: `storage/` y `config/` necesitan permiso de escritura
  para todos (`chmod -R a+rwX storage && chmod a+rwx config`). En el hosting no hace falta.
  Si una carpeta de `storage/` la crea un script de consola, Apache no puede escribir en ella
  (pasó con `storage/mail`): darle `chmod a+rwx`.
- Archivos/carpetas que crea Apache en `storage/` quedan a nombre de `daemon`: para borrarlos en
  local hace falta un script PHP servido por Apache (o `sudo`). Al limpiar pruebas, borrar SOLO
  la carpeta del uuid de prueba, nunca todo `storage/tenants/*` (ahí están las empresas reales).
- Base maestra local: `securityapp`. Reinstalar desde cero: borrar `storage/installed.lock` y
  `config/config.local.php`, vaciar la base y abrir `/install`.
- Inicio: http://localhost/securityapp/
- Diagnóstico: http://localhost/securityapp/install/check.php
- Logs: `storage/logs/app-YYYY-MM-DD.log`

## Checklist de deploy
1. `php tests/run.php` en verde.
2. Commit + push a `main`.
3. Deploy: GitHub → Actions → **Deploy** → Run workflow (FTPS, sube solo cambios; excluye
   `tests/`, `storage/`, `config/config.local.php`, `.github/`, `PLAN.md`, `CLAUDE.md`).
   Alternativa manual: zip del proyecto con las mismas exclusiones y subirlo por el panel.
4. Primera vez: si el hosting no deja apuntar el docroot a `/public`, el `.htaccess` de la raíz
   redirige a `public/` y bloquea las carpetas internas.
5. Abrir `/install/check.php` en producción → todo en verde (incluye rewrite, Authorization y
   que `/storage` y `/config` no sean accesibles).
6. Desde la Etapa 1: panel super-admin → "Actualizar base de datos" para aplicar migraciones.
