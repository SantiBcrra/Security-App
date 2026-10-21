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
- **Módulos activables por empresa** (`Services\Modules`, setting `modulos.desactivados`, /panel/configuracion#modulos): rondas,
  permisos de trabajo, EPP, observaciones, inspecciones, acciones, incidentes. Desactivado = `UserAuth::can()` false, `scope()`
  null y fuera de `UserAuth::permissions()` (así desaparece del menú, del inicio, de sus páginas — `RequirePermission` muestra
  "Módulo desactivado" — y de la app, que arma su menú con `/me.permisos`). No se borra nada. Menú lateral: Operación →
  Prevención → Administración.
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
- Mapas: Leaflet local (`public/assets/vendor/leaflet`) + `public/assets/js/maps.js` (`SAMaps.base(map, 'satellite'|'map')`: capas
  "Satélite" = Esri World Imagery + calles y lugares, y "Mapa" = OpenStreetMap, con selector; recuerda la elegida en el navegador).
  CSP: `*.tile.openstreetmap.org` y `server.arcgisonline.com`. Rondas/pánico arrancan en satélite; observaciones en mapa.
  ⚠️ Las imágenes de Esri sin clave sirven para desarrollo/uso bajo; para producción comercial sacar una clave gratuita de
  ArcGIS Location Platform (o usar otro proveedor) y agregarla a las URL de `maps.js`.
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
- La app de guardias será nativa para Android. El backend conserva las APIs v1 de autenticación,
  sincronización y operaciones offline para esa aplicación; el cliente web móvil fue retirado.
- La app Android usa HTTPS en producción y puede probarse contra XAMPP por Wi-Fi con la configuración
  de red correspondiente del dispositivo.
- Web Push propio: `Core\Ece` (aes128gcm, RFC 8291 — test con el vector oficial) y `Core\Vapid`
  (ES256). Claves VAPID en `platform_settings` (privada cifrada). El canal "push" de la Etapa 7
  envía a suscripciones web (`push_subscriptions`, destino `web:{id}`) y a tokens Expo futuros.
- Prueba de estrés: `php tools/sync-stress.php http://localhost/securityapp {empresa} {usuario} {clave} 500 10`.

### App Android — navegación y marca (versión 1.2)
- La app usa una navegación inferior compacta y persistente, con Inicio, Actividad, Avisos y Más. Se monta en el contenedor común de todas las vistas autenticadas para que no desaparezca al entrar a un módulo, con área de navegación del sistema reservada: la barra queda arriba de los tres botones nativos y el espacio inferior se pinta negro, como en las apps Android estándar, sin superposición. El encabezado superior también es común (logo, usuario, avisos, sincronización y ajustes); las pantallas raíz no dibujan una flecha de retorno propia. El botón central amarillo abre directamente la cámara para leer un QR y entra a Rondas; si no hay ronda activa, se aplica el inicio automático por primer escaneo ya documentado.
- El logo compartido por la marca se guarda en `android/app/src/main/res/drawable/security_logo.png` y se usa en el ícono adaptable, el ingreso y el encabezado. Se conserva la paleta `Brand`/`Accent` existente; no se agregan librerías ni servicios.
- Los avisos de pánico que incluyen una URL de Google Maps muestran además el botón `Abrir ubicación`, que lanza la aplicación de mapas o el navegador mediante `ACTION_VIEW`; no hace falta copiar coordenadas manualmente.

## Rondas de guardias (Etapa 9 adelantada)
- Puntos de ronda en `patrol_points`, con QR público, coordenadas, radio permitido y marca de punto crítico.
- Rutas y orden de puntos en `patrol_routes` / `patrol_route_points`; ejecuciones y escaneos en `patrol_rounds` / `patrol_scans`.
- Panel de rondas con barra fija (`app/Views/panel/rounds/_nav.php`, en TODAS sus pantallas; parámetro `tab`): pestañas Hoy
  `/panel/rondas` (en curso, programadas y alertas del día, últimas rondas, celulares) · Rondas realizadas `/panel/rondas/historial`
  (filtros fecha/guardia/ruta o "libres"/estado, `Patrols::searchRounds()`, tabla compartida `_rounds_table.php` con fila clickeable
  al detalle) · Programación · Rutas `/panel/rondas/rutas` · Puntos `/panel/rondas/puntos` (mapa + QR + eliminar) · "Reportes ▾"
  (Métricas, Marcas fuera de lugar) y a la derecha "+ Nuevo ▾" (punto, ruta, programación según permiso). Pánicos sin atender
  como banner arriba. No agregar botones sueltos de navegación por pantalla: van en la barra. El QR apunta a `/ronda/punto/{uuid}` y lo procesa la app Android.
  Doble clic en el mapa (permiso `rondas.crear`) → "Crear punto acá" → `/panel/rondas/puntos/nuevo?lat=&lng=` precargado. "Eliminar"
  (`rondas.editar`, `Patrols::deletePoint`): baja lógica (`deleted_at`, baja por la sync), lo saca de las rutas (y toca su
  `updated_at`); las marcas viejas quedan.
- Rutas: `/panel/rondas/rutas/{uuid}` (detalle: mapa satelital con el recorrido planificado = puntos numerados + línea punteada
  con flechas `SAMaps.route()`, guardias, programación, últimas rondas) y `/rutas/nueva` | `/rutas/{uuid}/editar` (orden de los
  puntos arrastrando o ↑↓, agregar tocando el punto en el mapa, recorrido en vivo; `Patrols::updateRoute` reemplaza puntos y
  asignaciones y toca `updated_at`). Modo de orden `patrol_routes.order_mode` (tenant 0071): libre (por defecto) | secuencial |
  azar (la app arma el orden de cada ronda y lo manda en `round.start.point_order`; el servidor valida que sea una permutación).
  `patrol_rounds.point_order` guarda el orden de la ronda; `patrol_scans.out_of_order` = no era el primero pendiente según la hora
  del celular (solo se registra; decisión del usuario: sin aviso inmediato). Se ve en el detalle de la ronda (planificado
  punteado + recorrido real), en métricas y en el resumen diario. La app avisa al guardia "fuera de orden: tocaba X".
- Cada escaneo guarda hora del dispositivo, hora de recepción, GPS, precisión, distancia calculada y si quedó dentro del radio. No se duplica un punto dentro de la misma ronda.
- La app Android guarda rondas y escaneos sin conexión y los envía con `round.start`, `round.scan` y `round.finish`; las operaciones son idempotentes por `op_id`.
- El panel web conserva Leaflet/OpenStreetMap. La app Android usa Google Maps SDK para Android: `RouteMapCard` dibuja los puntos sincronizados y la línea de la ruta, con vista de calles o satélite. La clave se lee de `android/secrets.properties` (ignorado por Git) mediante `MAPS_API_KEY`; no se incluye ninguna clave en el APK versionado. La restricción de Google para debug usa el paquete `ar.com.securityapp.campo.debug` y su SHA-1; release usa `ar.com.securityapp.campo` y la SHA-1 de la firma release.
- Rutas: al celular llegan solo las asignadas (`patrol_route_assignments`; alcance "todo" ve todas), con `points` = UUIDs en
  orden; las no asignadas viajan como baja. **Cambiar asignaciones o puntos de una ruta debe tocar `patrol_routes.updated_at`.**
  Iniciar una ruta no asignada se rechaza. Al finalizar: `completa` o `incompleta` (puntos salteados).
  Detalle en `/panel/rondas/ronda/{uuid}` (recorrido, salteados, fuera de radio). Con alcance "propios" el panel muestra solo las propias.
- Supervisores y administradores (`rondas.editar`; la app solo les muestra "Ubicar puntos" a ellos) pueden corregir la ubicación de referencia desde Android con `patrol.point_locate` (GPS actual, cola offline e idempotencia por `op_id`); el cambio queda auditado. Se rechaza un GPS simulado y uno con precisión peor que `Patrols::LOCATE_MAX_ACCURACY_M` (30 m), en la app y en el servidor. El reporte filtra por días locales de la empresa (convertidos a UTC). El reporte `/panel/rondas/marcas-fuera` filtra marcas `lejos`/`simulada` por guardia, punto y fecha.
- `round.start` es idempotente también por uuid de la ronda; el escaneo valida punto activo, uuid y hora del
  celular (máx. 30 días atrás). La app Android recupera la ronda en curso (`mine` + `en_curso`) al reabrir y ofrece "Tomar GPS" para ubicar un punto parado en el lugar.
- Automatizaciones (Fase 1): `/panel/configuracion/notificaciones` permite ajustar por empresa activación, tolerancias de progreso/inicio/atraso, batería baja, sincronización demorada y guía Android. Los valores viajan en `sync/pull.meta.automatizaciones_rondas`; los destinatarios y canales se mantienen en `notification_rules`. La tarjeta tiene su propio formulario (`section=rondas` en `saveSettings`): cada formulario de esa pantalla guarda solo sus campos. Detalle en `docs/design/automatizaciones-rondas.md`.
- Rondas programadas en Android: el inicio muestra la próxima ronda con cuenta regresiva y acceso "Preparar". Las alarmas locales avisan cinco minutos antes, a la hora exacta y al vencer la tolerancia si no empezó; se reprograman después de cada sincronización y funcionan con la app cerrada.
- Inicio automático: si no hay ronda activa, el primer QR/NFC inicia una ruta única asignada; si el punto pertenece a varias, Android pide elegir; si no pertenece a ninguna, inicia una ronda libre. `round.scan_auto` guarda inicio y primer escaneo en una sola operación idempotente.
- Automatizaciones fases 2 y 4 (`RoundAutomation::run()` en el cron + `tracking/RoundWatch.kt` en la app): primero avisa la
  app al guardia (local, sin señal también; revisa cada minuto desde `RoundTrackingService`) y si sigue igual después de
  OTRO plazo igual avisa el servidor a SyH + supervisores. Sin progreso: N min sin marcar (app) / 2×N (servidor
  `guard.no_progress`, solo si el celular manda posiciones: si no, ya avisa `guard.silent`; una vez por hueco). Atrasada:
  ruta con `expected_minutes` E → E + T (app) / E + 2×T (servidor `guard.round_late`, una vez). Abandonada: en curso hace
  más de 12 h → el sistema la cierra (completa/incompleta) con su última actividad. Todo queda en `patrol_round_alerts`
  (tenant 0067) y se ve en el detalle de la ronda junto con la duración real vs. esperada.
- Fase 5, estado del celular: la app manda `device.status` en cada sincronización (`tracking/DeviceState.kt`; `Outbox.replace`
  deja solo el último y no cuenta como "pendiente") → `user_devices.status` / `status_at` (tenant 0068, `DeviceStatus`). Al
  guardia le avisa `RoundWatch`: batería ≤ `rondas.bateria_baja_porcentaje` sin cargar, GPS apagado o sin permiso, GPS sin
  posiciones nuevas, registros sin enviar hace ≥ `rondas.sin_sync_minutos`. A SyH + supervisores (`guard.device`, una vez por
  ronda y problema): batería ≤ la mitad del umbral sin cargar o GPS apagado hace `DeviceStatus::GPS_OFF_MINUTES` (5).
  Panel: tarjeta "Celulares de los guardias" en /panel/rondas (últimas 24 h, con problemas) y estado del celular en el detalle
  de una ronda en curso. "Sin contacto" del panel no avisa aparte: ya lo cubre `guard.silent`.
- Fase 3, programación (`PatrolPlanner`, tenant 0069): `patrol_schedules` por ruta (horarios fijos `times` JSON o intervalo
  `window_start/end` + `every_minutes`, cruza la medianoche y cuenta el día en que empieza; `days` 1..7, vigencia, `except_dates`,
  `user_ids` opcional ⊆ asignados de la ruta, `grace_minutes` (vacío = `rondas.inicio_gracia_minutos`), `max_delay_minutes`).
  El cron (`PatrolPlanner::run()` en `CronRunner`) genera `patrol_slots` de ayer a pasado mañana en hora de la empresa (idempotente
  por `schedule_id + due_at`; no genera horarios ya vencidos al crear/editar), avisa `guard.round_not_started` a la hora + 2×gracia y
  marca `no_realizada` + `guard.round_missed` al pasar la demora máxima. `round.start` (con `slot_uuid` opcional) llama a
  `attach()`: realizada, `on_time` si empezó hasta la hora + gracia. Editar o pausar anula las futuras pendientes (lo pasado queda).
  Panel `/panel/rondas/programacion` (día con estado y % a tiempo, programaciones, alta/edición/pausa con `rondas.editar`).
  App: pull `patrol_slots` (solo pendientes a su cargo, últimos 2 días en adelante), "Próximas rondas programadas" con Iniciar
  (habilitado de hora − gracia a la demora máxima), recordatorios con alarmas del sistema (`tracking/SlotAlarms`, a la hora y al
  terminar la gracia, se reprograman en cada sincronización y al prender el celular) y guía al próximo punto (distancia, rumbo,
  "Cómo llegar" con `geo:`); recordatorios y guía se apagan con `rondas.guia_android`.
- Fase 6, métricas (`RoundMetrics`): `/panel/rondas/metricas` (período en días locales, por defecto 7 días; filtros ruta y guardia;
  alcance "propios" = solo las suyas): tarjetas (rondas, % programadas a tiempo, % marcas en el lugar y NFC, atrasadas /
  abandonadas / pánicos / sin actividad), por guardia (incluye asignados a rutas activas sin rondas en el período), por punto
  (salteado, fuera de lugar, dudosas; los que más fallan primero) e historial de alertas con resolución (alertas de ronda,
  pánicos con quién y en cuánto los atendió, marcas fuera de lugar, programadas no realizadas). CSV `/metricas/exportar?tipo=
  guardias|puntos|alertas` (permiso `rondas.exportar`, ";" + BOM, auditado). Resumen diario `guard.daily_summary` (tenant 0070,
  SyH + supervisores, app + email) del día anterior, después de las 8 h, una vez por día y solo si hubo actividad.

## Turnos de guardias — diseño en `docs/design/turnos-guardias.md`
- Decisiones del usuario: inicio **manual**, turnos **fijos y rotativos**, la salida del predio la **justifica el guardia**,
  ausencias **aprobadas por el supervisor**, un guardia en **varios predios**, reporte de **horas reales**.
- **Privacidad**: ubicación SOLO durante el turno (de "Empezar" a "Terminar" o cierre automático), con notificación fija
  "En turno"; solo se guardan posiciones **fuera del predio**. Posiciones (turnos y rondas) se borran a los
  `turnos.dias_posiciones` (90) días (`ShiftService::purge` en el cron). Consentimiento `Consent::VERSION = 2026-10-turnos`.
- Tablas (tenant 0072): `guard_shifts` (programación: `pattern` weekly | rotation con `rotation_on/off` + `anchor_date`,
  `start_time/end_time`, cruza la medianoche, `user_ids`), `guard_shift_slots` (un turno por guardia: programado | en_curso |
  cumplido | ausente | justificado | anulado; inicio/fin reales, `start_inside`, `out_seconds`, `end_mode` manual|auto,
  `unscheduled`, `replaces_slot_id`, `absence_id`), `guard_absences` (solicitada | aprobada | rechazada | cancelada, reemplazo),
  `guard_closures` (días sin servicio por predio o todos), `guard_shift_exits` (motivo, avisó antes, vuelta, distancia máx.),
  `guard_shift_positions`. `patrol_slots.status = justificada` (+ `note`) cuando todos los guardias a cargo están ausentes.
- Servicios: `ShiftPlanner` (validar/guardar, `occurrences`, `worksOn`, `generate` de ayer a +6 días, idempotente, sin días sin
  servicio; ausencia aprobada → nace `justificado`), `ShiftAbsences` (pedir, cargar aprobada, aprobar/rechazar con reemplazo,
  cancelar, `apply` idempotente, `allAbsentAt`, días sin servicio), `ShiftService` (start/end/exit/exitReason/exitReturn/track,
  `where` = radio del predio + `turnos.tolerancia_m` sin acusar a un GPS impreciso, `run` en el cron), `ShiftReport` (horas).
- Avisos (`guard.shift_*`, `guard.absence_*`, migración 0072): sin empezar a hora + 2×tolerancia (`turnos.gracia_minutos` 15),
  ausente al pasar el fin, cierre automático a fin + `turnos.cierre_auto_minutos` (120) con la hora programada, empezó fuera del
  predio o con GPS falso, salida con motivo (informativo), salida sin motivo a `turnos.minutos_sin_motivo` (10) (alarma, una vez),
  sin contacto `turnos.minutos_sin_contacto` (30) o GPS apagado 5 min, puesto sin cubrir (una vez por ausencia sin reemplazo),
  pedido y respuesta de ausencia (al guardia: destinatario `reporter`).
- Sync: push `shift.start` (uuid del turno o uno nuevo no programado con `site_uuid`; desde 60 min antes; uno a la vez),
  `shift.end`, `shift.exit` (con o sin `reason`; `announced` = "Salgo por una emergencia"), `shift.exit_reason`, `shift.return`,
  `shift.track` (posiciones fuera del predio), `absence.request`; pull `guard_shift_slots` (propios programado/en curso/justificado)
  y `guard_absences` (propias) + `meta.turnos` (tolerancias y motivos). `GET /api/v1/shifts/mine` (historial y horas),
  `POST /api/v1/absences/{uuid}/cancel`. El celular toma el área del predio de la entidad `sites` (puede cambiar).
- Panel: pestaña **Turnos** en Rondas (`panel/shifts/_subnav`): Hoy `/panel/rondas/turnos`, Programación (+ "Predios: Ubicar en
  el mapa" `/turnos/predio/{uuid}`), Ausencias y días sin servicio, Horas trabajadas (Reportes; CSV por guardia o turno);
  detalle `/panel/rondas/turno/{uuid}` (línea de tiempo + mapa con el predio y las salidas). Ajustes: Configuración →
  notificaciones → "Turnos de guardias" (`section=turnos`).

## Libro de guardia — diseño en `docs/design/libro-de-guardia.md`
- Decisiones del usuario: guardias de **empresa contratada** (su libro rubricado en papel es responsabilidad de ellos; este es
  el libro de Indumor), datos de visitantes **optativos**, **ingreso libre**, **cierre de turno con firma siempre** y
  **recepción con firma** solo si llega otro guardia, datos de visitantes **para siempre** (purga manual por fecha, a su riesgo).
- Módulo `libro` (activable; permisos en roles base, migración tenant 0073): admin y SyH todo; supervisor ver/crear/exportar;
  guardia (reportante) ver/crear con alcance todo (ve el libro del predio); auditor ver/exportar.
- `logbook_entries` por predio con **folio** (`Sequences::next('libro:{site}')`) y **cadena de huellas** (`hash` = sha256 de
  `prev_hash` + JSON canónico de `Logbook::HASHED`; `Logbook::verify()` detecta alteraciones). **Inmutable**: nunca UPDATE de
  campos de la huella (ni en tests: rompe la cadena); salida, corrección y recepción son anotaciones nuevas con
  `ref_entry_id`. Caché fuera de la huella: `exited_at`/`exit_entry_id`, `received_entry_id`, `purged_at`. Tipos: novedad
  (categoría, importante), persona (nombre, DNI **o** empresa), vehiculo (patente normalizada obligatoria), salida,
  correccion, cierre (resumen + firma), recepcion (firma). `logbook_known` = padrón para autocompletar por DNI/patente.
  Fotos `logbook_attachments` (web y `uploads.logbook_entry_uuid` desde la app).
- Servicio `Logbook`: create/exit/correct/close/receive (idempotentes por uuid), inside, day, search, summary, pendingClose,
  lookup, verify, purge (borra datos personales anteriores a una fecha; `libro.editar` con alcance todo), run (cron).
- Avisos: `logbook.important`, `logbook.inside_at_close` (cierre con gente o vehículos adentro), `logbook.inside_long`
  (adentro hace más de `libro.horas_adentro` 12 h, uno por predio y día).
- Panel `/panel/libro` (predio + día, alta rápida novedad/persona/vehículo con foto, "Adentro ahora" con "Salió", cierre y
  recepción con firma, corregir, sello "Libro íntegro"), `/panel/libro/buscar` (+ purga con confirmación "BORRAR"),
  `/panel/libro/imprimir` (parte diario con folios, firmas y huella), `/panel/libro/exportar` (CSV auditado).
- Sync (para la app, entrega 2): pull `logbook_entries` (últimas 48 h + lo que sigue adentro) y `logbook_known` (solo a quien
  anota); push `logbook.create|exit|correct|close|receive`.

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

## Permisos de trabajo (Etapa 13) — diseño en `docs/design/etapa-13-permisos-trabajo.md`
- `work_permits` (PT-000001; `types` JSON combinables: altura, caliente, espacio_confinado, loto, electrico;
  `original_data`/hash congelan lo solicitado), `work_permit_workers` (ejecutores/vigías, empleados o externos),
  `work_permit_checklists` (un checklist por tipo, evaluado con `InspectionStructure::evaluate`),
  `work_permit_signatures` (inmutables) y `work_permit_events`.
- Checklists previos = plantillas de inspección con `scope = permiso` + `permit_type` (`InspectionTemplates::forPermitType`);
  precargadas en el rubro (sin foto obligatoria: un crítico sin cumplir directamente impide autorizar). No aparecen en
  Inspecciones (`InspectionTemplates::all()` las excluye salvo `includePermits`), no se programan ni viajan como checklist.
- Flujo (`WorkPermitService`): solicitar (firma) → autorizar (acción `aprobar`, solo en este módulo; nunca el
  solicitante; bloqueado con críticos sin cumplir; firma) o rechazar → iniciar (firma de CADA ejecutor/vigía, desde
  30 min antes del inicio) → cerrar (firma + cómo quedó el área) → recibir (firma del autorizante). Cancelar antes de
  empezar. Máximo `permisos.max_horas` (12); el cron (`WorkPermitService::expire()`) vence los activos y avisa 30 min antes.
- Firmas: `Signatures::store($dataUrl, $carpeta)` valida PNG real con trazos y guarda archivo + SHA-256; componente
  `public/assets/js/signature-pad.js` (`<div data-signature><canvas><input type=hidden>…`), reutilizable (EPP, capacitaciones).
- `/panel/permisos` = activos ahora por planta (se refresca cada minuto) + para autorizar + recientes; imprimible con QR
  a `/panel/permisos/{uuid}/verificar` (cualquier usuario de la empresa: VIGENTE / NO VIGENTE). Destinatario de avisos
  `approvers` = permiso `permisos_trabajo.aprobar`.
- Controles (`WorkPermitControls`, entrega 2): mediciones de gases en espacio confinado (`work_permit_measurements`,
  límites en settings `permisos.gas_*` editables en /panel/configuracion; O₂ y LIE obligatorios). Fuera de rango con el
  trabajo en ejecución → se suspende solo (evento "Sistema") + `permit.gas_alarm` (CRÍTICO). Para iniciar/reanudar un
  confinado hace falta una medición en rango de los últimos 60 min (al reanudar, posterior a `suspended_at`).
  LOTO (`work_permit_isolations`): iniciar exige ≥1 bloqueo con energía cero verificada; cerrar exige todos retirados.
  Caliente: `fire_watch_minutes` al solicitar (setting `permisos.vigia_minutos`, 30); al cerrar se fija
  `fire_watch_until` y la recepción del área espera a que termine. Suspender/reanudar: el equipo (`canWork`) o quien
  tiene `aprobar` (`permit.suspended`). Extensión: UNA vez, la firma el autorizante (no el solicitante), antes de
  vencer, hasta `valid_until + max_horas` (`extended_until/by/at`, firma rol `extension`). Conflictos =
  `WorkPermits::conflicts()` (otro permiso vigente o pedido en el mismo equipo/sector y franja): aviso en el detalle y al solicitar.
- `/panel/permisos/historial` (filtros estado/tipo/planta/sector/fechas/texto/míos) y `/panel/permisos/exportar`
  (CSV, permiso exportar). "Mis permisos de trabajo" en el inicio (activos propios + para autorizar).
- App de campo (entrega 3, `WorkPermitApi`): pull `work_permits` (los que `canView`: vigentes, para autorizar y terminados
  hace ≤ 3 días; con ejecutores, últimas mediciones, bloqueos y `can` {approve, start, close, work, stop}) + `meta.gases`.
  Push `permit.start` (firmas de los ejecutores como data URL), `permit.measure` (uuid + hora del celular; la suspensión
  automática cuenta desde `measured_at`), `permit.isolate` / `permit.release` (uuid del bloqueo), `permit.suspend` /
  `resume` / `close`: idempotentes (si ya está hecho → ok `duplicate`), responden el permiso actualizado. Autorizar /
  rechazar solo con conexión (`POST /api/v1/permits/{uuid}/approve|reject`); QR → `GET /permits/{uuid}/verify` (cualquier
  usuario). App Android: permisos de trabajo en Reportes, detalle por UUID (firmas, evaluación
  de gases local con alerta "salir del espacio", `pending` hasta que el servidor confirma; si rechaza, se vuelve a pedir el
  permiso con `GET /permits/{uuid}`). El escáner reconoce el QR del permiso. Extender y recibir el área: solo web.

## EPP (Etapa 14) — diseño en `docs/design/etapa-14-epp.md`
- Decisiones del usuario: **sin stock** (solo entregas), **solo personal propio** (`employees.contractor_id IS NULL`;
  los de contratistas no aparecen), firma en pantalla **o planilla en papel escaneada** (foto/PDF + motivo), matriz por
  puesto **+ extras por empleado**.
- `ppe_items` (catálogo: categoría, marca, modelo, certificación, `life_days`, `size_type` ropa|calzado|guantes,
  `preset_key`), `ppe_matrix` (puesto × elemento: cantidad, vida útil propia, obligatorio o "según tarea"),
  `ppe_employee_extras` (pisa al puesto para ese elemento), talles en `employees.size_clothing|size_shoes|size_gloves`
  (se actualizan con cada entrega).
- Entregas `ppe_deliveries` (EPP-000001 con `Sequences::next('ppe_deliveries')`, motivo, firma `signature_mode`
  pantalla|papel con path + SHA-256, `original_data`/hash inmutables; se **anulan** con motivo (permiso `epp.cerrar`),
  nunca se editan) + `ppe_delivery_items` (copia de nombre/marca/modelo/certificación al entregar y `next_due_on` =
  fecha local + vida útil efectiva). Idempotente por `uuid` (app de campo). Hasta 60 días atrás.
- Estado (`PpeService::status` / `summaries`): por elemento obligatorio vencido | nunca | por_vencer (`epp.aviso_dias`,
  15) | al_dia; "según tarea" = opcional (no falta). Última entrega no anulada con `Ppe::lastDeliveries()` (MAX, sin window).
- Catálogo y matriz: `PpeService::canManage()` = `epp.editar` con alcance "todo" (el supervisor no). Alcance de
  empleados por sector del empleado. Precarga del rubro en `templates/{rubro}.php` → `epp` (`items` + `matrix` con
  "?clave" = según tarea, "clave:2" = cantidad), `IndustryTemplates::applyPpe()`.
- Pantallas `/panel/epp` (empleados y estado), `/panel/epp/empleado/{uuid}` (ficha, talles, extras, entregas, anular),
  `/entregar`, `/constancia` (Res. SRT 299/11, imprimible con firma por fila), `/panel/epp/catalogo`, `/panel/epp/matriz`.
- Entrega 2: `/panel/epp` incluye tablero por sector; `/panel/epp/entrega-lote` lista pendientes para firmar uno a uno;
  `/panel/epp/exportar` descarga CSV y `/panel/epp/constancias-sector?sector=UUID` imprime constancias. `PpeReminders`
  integra `ppe.due_soon`, `ppe.overdue` y `ppe.missing` al cron; el aviso se ajusta en `epp.aviso_dias`.
- Soporte Android: `Sync\\Push` acepta `ppe.delivery` con `employee_uuid`, `items` y firma PNG; reutiliza `PpeService::deliver`,
  valida permiso y alcance, conserva la entrega inmutable y responde de forma idempotente por `uuid` y `op_id`. `Sync\\Pull`
  expone `ppe_items`, `ppe_matrix` y `ppe_deliveries` solo a usuarios con `epp.crear` dentro de su alcance.

## App Android (todos los empleados) — plan en `docs/design/app-android-guardias.md`
- Código en `android/` (Kotlin + Jetpack Compose, AGP 9.4, Gradle 9.6, minSdk 26, compileSdk 37, targetSdk 35). Paquete
  `ar.com.securityapp.campo` (debug `.debug`, servidor `http://10.0.2.2/securityapp`, editable en el ingreso solo en debug).
  Compilar: `cd android && JAVA_HOME="/Applications/Android Studio.app/Contents/jbr/Contents/Home" ./gradlew assembleDebug`.
- App: `ApiClient` (OkHttp, `{ok,data,error}`, renueva el JWT con un mutex), `TokenStore` (Keystore AES-GCM), `LocalDb`
  (SQLite: `records` entidad+uuid+JSON del pull y `outbox`), `SyncRepository` (pull por cursor), `UpdateManager`.
- Distribución sin Google Play: tabla maestra `app_releases` (migración master 0007), `/admin/app-android` (subir APK,
  versión mínima obligatoria), `/descargas/android` (QR + guía; **requiere sesión**: usuario de una empresa o super-admin,
  decisión del usuario 19/10/2026 — lo baja un admin y lo pasa por WhatsApp), `GET /api/v1/app/android` (sin sesión: solo
  versión y huella; `url` = `GET /api/v1/app/android/apk`, que exige el token de la app; desde la 1.2.2 `UpdateManager` lo manda). La app baja el
  APK, verifica SHA-256 y abre el instalador. **La clave de firma de release no va al repo: respaldarla.**
  Subida del APK **por partes** (hostings con `post_max_size` chico o internet lento: si el POST supera el límite PHP vacía el
  formulario y falla el CSRF): el navegador calcula el SHA-256, `POST /admin/app-android/partes` (inicia o retoma, id =
  hash de huella + tamaño) y manda partes de 1 MB a `/partes/{id}?offset=` con reintentos; al completar publica con
  `upload_id` (`AppDistribution::chunkFile` verifica tamaño y huella). Alternativa manual: dejar el `.apk` por FTP en
  `storage/apps/android/subir/` y elegirlo en el formulario (`inbox`).
- Rondas (entrega 2): `RoundsRepository` guarda ronda/escaneos en la base local y encola `round.start` (con
  `started_at_device`), `round.scan` y `round.finish` (con `finished_at_device`) en `Outbox` (orden de creación, op_id).
  `SyncWorker` (WorkManager, requiere red, reintento exponencial + periódico 15 min) manda la cola y baja cambios: lo hecho
  sin señal sale solo al reconectar. Rechazos del servidor quedan en Ajustes (reintentar con otro op_id / descartar).
  QR con CameraX + ML Kit (sin internet), ubicación puntual con Fused Location. En debug: "Simular escaneo" (emulador).
  Servidor: un escaneo que llega después del fin vale si `scanned_at_device` ≤ `finished_at` (recalcula completa/incompleta);
  `distance_m`/`accuracy_m` DECIMAL(11,2) (migración 0062: un GPS a miles de km no rompe el escaneo).
- Seguridad del guardia (entrega 3, `GuardSafety`): `RoundTrackingService` (servicio en primer plano tipo location, notificación
  fija "Ronda en curso", SOLO durante la ronda; arranca al iniciar o al reabrir con ronda en curso, se apaga al finalizar)
  guarda posiciones en `tracks` (SQLite) → `round.track` en lotes de 100 → `patrol_tracks` (+ `patrol_rounds.last_track_at`),
  recorrido en el mapa del detalle de ronda. Pánico (`PanicManager`): mantener 2 s → se encola `guard.panic` → `POST /api/v1/panic`
  (≤15 s) → si falla, SMS (`SmsManager`, permiso SEND_SMS) a `guardias.panico_telefonos` y la cola lo manda al volver la señal
  (idempotente por uuid, `sms_sent`). `panic_alerts`: aviso CRÍTICO `guard.panic` (SyH, supervisores, admins) y re-aviso a
  managers cada `notif.escalation_minutes` hasta "Atendido" (`/panel/rondas/panico/{uuid}`). Cron `GuardSafety::run()`: re-aviso
  + `guard.silent` (ronda en curso sin posición en `rondas.minutos_sin_senal`, una vez por hueco). Ajustes en Configuración →
  Guardias (teléfonos, `rondas.track_segundos`, minutos sin señal); bajan en `meta.guardias` del pull. "Antes de empezar":
  ubicación (obligatoria), notificaciones, sin ahorro de batería, SMS + consejos por marca. Solo se avisa a usuarios activados.
- Observaciones y avisos (entrega 4): `ObservationsRepository` guarda la observación en la base local (`local: true`) y encola
  `observation.create` (misma validación que la web); las fotos (`Uploads`, tabla `uploads` SQLite: achicadas a 1600 px JPEG,
  EXIF rotado, SHA-256) se suben por partes de 512 KB DESPUÉS de que la observación llegó (`onServer`). Pantallas en
  `ui/observations/` (lista, formulario con categoría/severidad, sector, equipo por QR `/q/{uuid}` o lista, GPS, fotos de
  cámara o galería, inminente, anónimo si la empresa lo permite; detalle con `GET /observations/{uuid}`). Avisos
  (`AvisosScreen`, campanita con contador): "Recibido" (`/alerts/{uuid}/ack`) y "Atendido" de pánicos con comentario
  (`/panics/{uuid}/ack`; el listado informa si ya lo atendieron).
- Notificaciones con la app cerrada = **Firebase Cloud Messaging** (`Notify\Fcm`, API HTTP v1 con JWT RS256 propio, token
  OAuth cacheado cifrado). El super-admin pega en /admin/configuracion → "Notificaciones de la app Android (Firebase)" el
  `google-services.json` (público) y la cuenta de servicio (cifrada). El APK NO lleva google-services.json: la app pide
  `GET /push/fcm-config?package=` e inicializa `FirebaseApp` a mano (`PushRegistrar`), manda el token a `POST /push/fcm-token`
  (`user_devices.push_token = fcm:{token}`; si FCM dice que no existe más, se borra). Se mandan mensajes "data" (crítico =
  prioridad HIGH) y la app arma la notificación: canal "alertas" (alarma, vibración) o "avisos" (`push/Notifs`), al tocarla
  abre Avisos. Sin Firebase configurado, `NotificationsRepository.notifyNew()` muestra los avisos nuevos al sincronizar.
- Acciones e inspecciones (entrega 5): `ActionsRepository` (tomar = `action.start`; cerrar = texto + fotos `target action` +
  `action.close`; cambio local optimista hasta el próximo pull, "pendiente" se deduce de la cola). `SyncRepository.syncAll`:
  push (reteniendo los `action.close` con fotos pendientes) → fotos → push otra vez → pull; una foto sube solo si su alta
  (`*.create`) ya no está en la cola. `InspectionsRepository`: checklists del pull (`inspection_templates`), programadas
  (`inspection_schedule`), `evaluate()` = misma regla que el servidor para avisar antes de enviar (el resultado oficial lo
  calcula el servidor), `inspection.create` con `photo_counts` y fotos por ítem (`uploads.item_key`, LocalDb v4). Las
  inspecciones hechas en el celular se guardan en la entidad local `my_inspections` (el pull no las baja) y se actualizan
  con la respuesta del push (número, resultado, acciones creadas). Pantallas en `ui/actions/` y `ui/inspections/` (nueva por
  QR del equipo o lista; equipo por tipo, sector o general). Acciones aparece también para quien tiene acciones a cargo.
- Incidentes (entrega 6): `IncidentsRepository` + `ui/incidents/` (tipo con explicación, cuándo = ahora o fecha/hora
  elegida, sector / equipo por QR o lista / lugar + GPS, personas: empleado sincronizado o externo con rol lesionado /
  involucrado / testigo, lesión que se ve y declaración opcionales, qué pasó, qué se hizo, gravedad potencial, fotos).
  Validación local = `IncidentService::validate` (lesionado obligatorio en accidentes; sector salvo in itinere).
  `incident.create` idempotente por uuid, fotos con `target incident`; el registro local (`local: true`) lo pisa el pull.
  Detalle con `GET /incidents/{uuid}` (nunca datos de salud). Investigación y seguimiento: solo web.
- NFC y versión 1.0 (entrega 7): la etiqueta NFC de un punto = la misma URL que su QR (NDEF URI); `nfc/NfcTags` + `NfcListener`
  (reader mode solo en la pantalla de la ronda) y `NfcWriterScreen` (permiso `rondas.editar`, opción de bloquear la etiqueta).
  `round.scan` lleva `method` qr | nfc → `patrol_scans.method` (tenant 0065), visible en el detalle de la ronda.
  Cada escaneo suena y vibra según el resultado (`ScanFeedback`: registrado, ruta completa, ya marcado, error / etiqueta vacía).
  Al marcar el último punto de una ruta, la ronda se finaliza sola tras 10 s (`AutoFinishDialog`: "Finalizar ahora" / "Seguir en la
  ronda"); las rondas libres y las que tienen puntos salteados se finalizan a mano.
- Ubicación de cada marca (`Patrols::locationStatus`, columnas `patrol_scans.location_status` + `is_mock`, tenant 0066): ok (≤ radio)
  | impreciso (fuera, pero distancia − precisión ≤ radio: no se acusa por un GPS malo) | lejos | sin_gps | simulada (la app
  manda `mock: 1` si Android marca la ubicación como simulada; nunca vale). La marca NUNCA se rechaza y el guardia no se entera:
  lejos/simulada → aviso `guard.off_site` a SyH + supervisores, una vez por ronda y tipo (`NotificationMarks`). Lista de rondas:
  "N fuera" (lejos + simulada) y "N dudosas" (impreciso + sin GPS). La app solo usa la última ubicación conocida si tiene < 2 min.
  Ícono propio (adaptable, escudo amarillo sobre pizarra + monocromo) e `ic_notification` para los avisos.
  Versión final: `versionCode 10` / `1.0.0` (turnos: 11 / 1.1.0); firma y `serverUrl` desde `android/keystore.properties` (gitignored, ver
  `.example`); sin eso `assembleRelease` se corta. **Sin R8** (con la optimización de AGP 9 se cerraba al abrir). Pasos para
  crear la clave y publicar: `docs/design/app-android-guardias.md` → "Firma de la versión final".
- Turnos (versión 1.1.0, `versionCode 11`): tarjeta "Mi turno" en el inicio (quien tiene `rondas.crear`): Empezar turno (pide
  permiso de ubicación; si el GPS dice que está fuera del predio o es falso pide confirmación y se registra igual), "Salgo por una
  emergencia" (motivo antes de salir) y Terminar turno; "Mis turnos y ausencias" (`ui/shifts/ShiftsUi.kt`: próximos, historial
  con horas de `GET /shifts/mine`, pedir/cancelar ausencia). `ShiftsRepository` (local + cola `shift.*` / `absence.request`;
  salidas en la entidad local `shift_exits_local`; área del predio de `sites`). `tracking/ShiftTrackingService` (primer plano
  "En turno", SOLO durante el turno): ubicación cada 60 s con precisión equilibrada, una lectura afuera se confirma con GPS
  preciso; fuera > `minutos_fuera` → `shift.exit` + notificación "Saliste del predio: ¿por qué?" (se repite cada 3 min, abre la
  app con `abrir=turno`) y posiciones `shift.track` mientras está afuera; al volver `shift.return`; recordatorio al terminar el
  horario. `tracking/ShiftAlarms` (alarmas del sistema a la hora de entrada y al vencer la tolerancia; se reprograman junto con
  las de rondas en `SlotAlarms.schedule`). Salir de "Ver como" o cerrar sesión detiene el servicio.
- **Permisos de trabajo y EPP desactivados en la app** (decisión del usuario, 09/10/2026): comentados en `MODULES` de
  `HomeScreen.kt`; el servidor los sigue soportando. No activarlos hasta que el usuario lo pida.
- **Roles en la app**: el menú, los botones y lo que baja la sync dependen del rol (`/me.permisos` + alcance en `Sync\Pull`);
  el servidor valida todo igual (p. ej. `patrol.point_locate` exige `rondas.editar`). Los datos técnicos (conteos de lo
  guardado) solo se ven en Ajustes y solo para administradores (`usuarios.editar`).
- **"Ver como"** (`Services\AppPreview`, Ajustes → "Ver la app como otro usuario", permiso `usuarios.editar`):
  `GET /api/v1/preview/users`, `POST /preview/start` → JWT `typ = preview` (`sub` = usuario visto, `pv` = quien mira, 30 min,
  ligado al dispositivo de quien mira); `RequireApiUser` rechaza todo lo que no sea GET (`preview_read_only`). Entrar y
  salir queda auditado (`app.preview_start|stop`); `/me.vista_previa`. En la app (`ApiClient.previewToken`): se exige no tener
  nada pendiente ni ronda en curso, se borra lo bajado y se baja como el otro usuario; se puede simular todo (nada sale:
  `syncAll` solo baja, el pánico no envía ni SMS); al salir (o si la app se cerró en el medio: `Prefs.previewActive`) se
  borra y se vuelve a bajar lo propio. Banner "VISTA PREVIA" con "Salir" en el inicio; dentro de cada vista tambien aparece el aviso de solo lectura y las acciones de turnos y rondas muestran una advertencia inmediata antes de bloquearse.
- **`targetSdk` 35 a propósito**: apuntando a Android 17 (37) el sistema bloquea las conexiones a la red local y la app no
  llega al XAMPP de la Mac (10.0.2.2 o la IP por Wi-Fi). Sin Google Play no hay exigencia de subirlo.
- Consentimiento (celular personal, Ley 25.326): `Consent::VERSION` + texto en el servidor, `user_consents` (tenant 0061),
  `GET/POST /api/v1/consent`; `/me` informa `consentimiento`.

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

## Migraciones pendientes y tablas ausentes
- El tablero de plataforma `/admin` muestra las migraciones pendientes y enlaza a `/admin/migraciones`; este aviso no se muestra en `/panel`.
- `ErrorHandler` reconoce el error MySQL/MariaDB 1146 (`42S02`) y muestra una pantalla 503 clara. En `/admin` incluye el enlace a migraciones; en `/panel` solo indica que el módulo no está habilitado y pide contactar al administrador.

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
   `tests/`, `tools/`, `android/`, `docs/`, `storage/`, `config/config.local.php`, `.github/`, `PLAN.md`, `CLAUDE.md`, `AGENTS.md`).
   Alternativa manual: zip del proyecto con las mismas exclusiones y subirlo por el panel.
4. Primera vez: si el hosting no deja apuntar el docroot a `/public`, el `.htaccess` de la raíz
   redirige a `public/` y bloquea las carpetas internas.
5. Abrir `/install/check.php` en producción → todo en verde (incluye rewrite, Authorization y
   que `/storage` y `/config` no sean accesibles).
6. Desde la Etapa 1: panel super-admin → "Actualizar base de datos" para aplicar migraciones.
