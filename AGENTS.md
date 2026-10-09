# AGENTS.md — Guía para agentes de código (Codex y otros)

Leé este archivo completo antes de tocar código. Después leé **`docs/ESTADO.md`** (checklist: qué está
hecho, qué falta y quién lo hace), **`CLAUDE.md`** (convenciones detalladas por etapa: es la fuente de
verdad técnica) y **`docs/ROADMAP.md`** (lo que falta construir, en detalle). `PLAN.md` es el plan original y está **desactualizado** en varias decisiones: si
contradice a `CLAUDE.md` o a este archivo, mandan estos.

## Qué es
Security App: SaaS multi-empresa de **Seguridad e Higiene laboral** (Argentina) para empresas
industriales (la primera es una metalúrgica, "Indumor"). Interfaz y textos en **español**
(rioplatense: "vos", "cargá", "confirmá").

Ya está hecho (Etapas 0–13 y la entrega 1 de la 14): instalador web, empresas con base propia, usuarios/roles/2FA,
datos maestros, Observaciones, notificaciones, API de sincronización offline, app de campo PWA (`/movil/`), rondas,
Acciones CAPA, Inspecciones, Incidentes, Permisos de trabajo y EPP (catálogo, matriz, entregas, constancia). También
el rediseño visual (menú lateral). El checklist completo está en `docs/ESTADO.md`.

## Reglas que no se negocian
1. **Despliegue sin consola**: se instala copiando archivos + base vacía + `/install` en el
   navegador. Prohibido: Composer en el servidor, Node en el servidor, workers/daemons, Redis,
   colas externas, config de Apache fuera de `.htaccess`, extensiones PHP raras. Las tareas
   periódicas van en el cron por URL (`/cron/run?key=…`, ver `CronRunner`).
2. **Sin librerías ni servicios externos**: nada de Composer, npm, CDN, Firebase, dompdf,
   PhpSpreadsheet, Chart.js por CDN. Si algo hace falta, se escribe en PHP puro o se copia el
   archivo JS minificado a `public/assets/vendor/` (con su licencia), como Leaflet y qrcode.
   "PDF" = página imprimible (`/imprimir`, CSS de impresión). Excel = CSV o XLSX propio
   (`Core\Spreadsheet` ya lee XLSX; escribir uno es ZIP + XML con `ZipArchive`).
3. **Una base de datos por empresa, 100 % independiente.** Base maestra (empresas, super-admins,
   settings de plataforma) + una base por empresa. **NO existe `tenant_id`** y no se filtra por
   empresa en las consultas: el aislamiento lo da la conexión. Datos de negocio → `DB::tenant()`;
   plataforma → `DB::master()`. Nunca mezclar.
4. **Compatibilidad**: PHP **8.2** como mínimo (producción usa 8.4: no usar nada de 8.3/8.4).
   SQL compatible con **MySQL 5.7.27 y MariaDB 10.4**: sin CTE (`WITH`), sin window functions,
   sin columnas `JSON` (usar `TEXT`/`LONGTEXT` y decodificar en PHP), `DATETIME` (no
   `TIMESTAMP`), `ONLY_FULL_GROUP_BY`, `utf8mb4_unicode_ci`, InnoDB.
5. **Evidencia inmutable**: reportes originales, fotos, firmas y respuestas firmadas nunca se
   editan ni se borran. Correcciones = eventos nuevos con antes/después. Nada de negocio se borra
   físicamente: se desactiva (`is_active`, `deleted_at`).
6. **No tocar la instalación real del usuario** al probar (ver "Cómo probar").

## Entornos
| | Producción (hosting compartido) | Local |
|---|---|---|
| PHP | 8.4.25 | 8.2.4 (XAMPP Mac) |
| Base | MySQL 5.7.27 | MariaDB 10.4.28 |
| URL | pendiente (subdominio aún no creado) | http://localhost/securityapp/ |

- Repo: GitHub `SantiBcrra/Security-App`, rama `main`. Deploy: workflow manual
  `.github/workflows/deploy.yml` (FTPS) — **todavía no se usó**; producción no existe aún.
- PHP local: `/Applications/XAMPP/xamppfiles/bin/php`. Apache local corre como `daemon`.
- Base maestra local: `securityapp`; empresa demo `indumor` (base `securityapp_indumor`).

## Arquitectura (qué reutilizar — no reinventar)
```
public/index.php        front controller; assets en public/assets (Bootstrap 5.3.3, Alpine 3.14.1)
app/routes.php          todas las rutas (grupos + middlewares)
app/Core/               Router, Request, Response, View, DB, Config, Session, Csrf, Validator, Uuid,
                        Crypto (AES-256-GCM), SignedUrl, TenantFiles, Jwt, Totp, Spreadsheet,
                        Http, Smtp, MailMessage, Ece/Vapid (Web Push), helpers.php
app/Middleware/         RequireTenant, RequirePermission, ReadOnlyImpersonation, RequireApiUser, ...
app/Policies/Permissions.php  módulos × acciones (ver/crear/editar/cerrar/exportar) × alcance
app/Models/             ÚNICO lugar con SQL (Repository = base para tablas maestras)
app/Services/           lógica de negocio
app/Resources/          CRUD genérico de datos maestros (Resource + Registry)
app/Controllers/Web/Panel/   área de empresa (/panel)   ·  Web/Admin/ super-admin (/admin)
app/Controllers/Api/    API v1 (JWT) para la app de campo
app/Views/              PHP plano; layouts/app.php es el layout de /panel
database/migrations/{master,tenant}/   numeradas; .sql o .php
public/assets/movil/    PWA (módulos ES nativos, sin build): app.js, db.js, api.js, sync.js
tests/                  runner propio: tests/run.php + *Test.php
tools/                  scripts de consola SOLO locales (bloqueados por web, fuera del deploy)
```

Patrones existentes que los módulos nuevos deben copiar:
- **Módulo con flujo** (modelo a seguir: Observaciones): `Models\Observations` +
  `Services\ObservationService` (alcance `scope()`/`canView()`, `create()`, `transition()`),
  `ObservationWorkflow` (estados y transiciones declarativas), `ObservationEvents` (línea de
  tiempo, solo inserción), `ObservationInput` (validación única web + API), `Sequences::next()`
  (numeración por empresa tipo OBS-000001, dentro de una transacción).
- **Permisos**: los módulos `acciones`, `inspecciones`, `incidentes`, `permisos_trabajo`, `epp`,
  `capacitaciones`, `reportes` **ya existen** en `Permissions::MODULES` y en los roles base.
  Usar `UserAuth::can('modulo','accion')`, `UserAuth::scope('modulo')`,
  `new RequirePermission(...)` en rutas, y `SectorScope::sectorIds($modulo)` para el alcance.
  El menú de `/panel` se arma con `can()`.
- **Auditoría**: `Audit::tenant(...)` en cada cambio (antes/después). `Audit::platform()` en la
  maestra.
- **Archivos**: `TenantFiles` (MIME real con finfo, nombre UUID, fuera del docroot) +
  `ImageProcessor` (sha256, EXIF, miniatura). Servir solo por controlador con permiso o
  `SignedUrl::make()`.
- **Notificaciones**: `Notify\Notifier::dispatch($evento, $sujeto, $extra)`. `Notifier::subject()` normaliza el sujeto
  según el prefijo del evento (`action.`, `inspection.`, `incident.`, `permit.`; agregar el del módulo nuevo ahí).
  Eventos y textos en `Notify\Messages` (`EVENTS`, `CRITICAL`, un `for{Modulo}()`), reglas por empresa en
  `notification_rules` (seed con una migración .php), `Recipients` resuelve destinatarios. Nunca debe romper la
  operación que lo llama.
- **Tareas periódicas**: agregarlas en `Notify\CronRunner::run()` (corre por empresa, con lock);
  para "una vez por día" usar `NotificationMarks`.
- **Datos maestros nuevos** (p. ej. catálogo de EPP, cursos): un `App\Resources\*Resource` +
  registro en `Resources\Registry` → CRUD gratis en `/panel/datos/{recurso}`. Catálogos simples:
  `Services\Catalogs` / tabla `catalog_items`.
- **App de campo**: para que una entidad llegue al celular, agregarla a `Sync\Pull::ENTITIES` y
  su consulta; operaciones nuevas del celular = nuevo `type` en `Sync\Push` (idempotente por
  `op_id`) + su manejo en `public/assets/movil/sync.js`. Contrato en `docs/api/openapi.yaml`
  (actualizarlo).
- **Settings por empresa**: `Models\Settings` (tabla `settings`), pantalla `/panel/configuracion`.

## Convenciones
- `declare(strict_types=1);` en todo archivo PHP; namespace `App\` → `app/`.
- PDO con consultas preparadas siempre. **Nada de SQL en controladores ni vistas.**
- Fechas en **UTC** en base y PHP (`UTC_TIMESTAMP()`, `gmdate`); se muestran con `fecha()` en la
  zona de la empresa.
- PK interna `INT AUTO_INCREMENT` + `uuid CHAR(36)` público generado en PHP (`Uuid::v4()`) o en el
  celular. En formularios, URLs y API viajan UUIDs, nunca ids internos.
- Vistas: escapar SIEMPRE con `e()`; `url()`, `asset()`, `csrf_field()` en todo form POST.
- API: `Response::json($data)` / `Response::jsonError($msg, $status)` → `{ ok, data, error }`.
- **Rutas reservadas**: ninguna URL puede empezar con `/app`, `/config`, `/database`, `/storage`,
  `/tests`, `/tools` o `/vendor` (el `.htaccess` raíz las bloquea). Área de empresa = `/panel/...`.
  No crear carpetas en `public/` con el mismo nombre que una ruta.
- **Migraciones**: archivo nuevo con el siguiente número. Próximos: **tenant `0060_…`**,
  **master `0007_…`** (ver "Trabajo en paralelo" antes de crear una). Nunca editar una ya aplicada. Idempotentes (`CREATE TABLE IF NOT EXISTS`,
  verificar columnas antes de `ALTER`). Sin `DELIMITER`, triggers ni procedures. Tablas con
  `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`; índices `idx_tabla_campos` /
  `uq_tabla_campos`. Se aplican desde `/admin/migraciones`.
- Comentarios y mensajes en español, concisos, como el código existente.

## Cómo probar
```bash
/Applications/XAMPP/xamppfiles/bin/php tests/run.php
```
- Hoy pasan **180 tests**: deben seguir en verde. Cada etapa agrega su `tests/{Modulo}Test.php`
  copiando el estilo de `tests/ObservationTest.php` / `tests/NotificationTest.php` (crean bases
  descartables `securityapp_test*` con root sin contraseña en 127.0.0.1 y storage temporal).
- En tests nada sale a internet: `MailTransport::$fake = true`, `Channels::$fakeExternal = true`.
- Pruebas de punta a punta en el navegador: usar una **maestra descartable**
  (p. ej. `securityapp_e2e`), haciendo antes backup de `config/config.local.php`,
  `storage/installed.lock` y `storage/demo-links.txt`, y restaurarlos al terminar. Al limpiar,
  borrar SOLO la carpeta `storage/tenants/{uuid}` de la empresa de prueba — **nunca** todo
  `storage/tenants/*` ni `storage/sessions`. No modificar las bases `securityapp` ni
  `securityapp_indumor` salvo aplicar migraciones.
- Datos demo locales: `php tools/seed-demo.php indumor http://localhost/securityapp`.
- No leer, escribir ni ejecutar nada en `/Users/usuario/Desktop/erp` (ERP de referencia de
  otro sistema; solo lectura si hiciera falta mirar cómo resolvió algo).
- No inventar ni imprimir contraseñas reales; las cuentas se crean por link de invitación.

## Trabajo en paralelo (Codex + Claude)
Codex hace las etapas web; Claude hace la app Android de guardias (`android/` y los cambios de servidor que esa app
necesita: API de dispositivos/FCM, posiciones de ronda, pánico, NFC en puntos de ronda, distribución del APK).
- **Ramas**: trabajar en una rama propia (`codex/<tema>`, p. ej. `codex/etapa-14-epp`) y mergear a `main` al cerrar
  cada entrega con los tests en verde. Hacer `git pull` de `main` antes de empezar y antes de mergear. Si Codex corre
  en la misma Mac, usar un worktree aparte (`git worktree add ../security-app-codex codex/<tema>`) para no pisar los
  archivos que Claude está editando.
- **No tocar**: `android/`, `docs/design/app-android-guardias.md`, ni las tablas/rutas de rondas para la app
  (`patrol_*`, `/api/v1/push/fcm`, `/api/v1/app/android`, `/descargas/guardias`) sin coordinar.
- **Migraciones**: el migrador aplica por nombre de archivo (no por "mayor que la última"), así que dos migraciones
  nuevas no se rompen entre sí, pero los números deben ser únicos. Antes de crear una, `git pull` y tomar el
  siguiente número libre; si al mergear aparece el mismo número en las dos ramas, renombrar la propia (todavía no
  aplicada en ningún lado) al siguiente libre.
- **Archivos compartidos** (`app/routes.php`, `CLAUDE.md`, `Sync\Pull`, `Sync\Push`, `Notify\Messages`, `CronRunner`,
  `layouts/app.php`): cambios chicos y agregados, sin reordenar ni reformatear lo existente, para que el merge sea limpio.
- Al terminar cada entrega, marcarla en `docs/ESTADO.md`.

## Forma de trabajo
1. **Una etapa por vez.** Antes de codificar, presentá un diseño corto (tablas, estados,
   pantallas, rutas, permisos, eventos de notificación, qué va a la app de campo) y esperá el OK.
   Si algo del roadmap no encaja con las reglas de arriba, proponé la adaptación en vez de
   romper una regla.
2. Implementá con tests. `tests/run.php` en verde antes de cada commit.
3. Actualizá **`CLAUDE.md`** con una sección corta de la etapa (decisiones, clases clave,
   rutas), como las secciones existentes, y `docs/api/openapi.yaml` si tocaste la API.
4. Un commit por entrega, mensaje en español: `Etapa N (entrega M): resumen`. Marcar la entrega en
   `docs/ESTADO.md`. Mergear a `main` (ver "Trabajo en paralelo").
5. Al final de cada etapa, explicá al usuario: qué hizo, cómo probarlo en
   http://localhost/securityapp/ (menú y rol), y si hay que aplicar migraciones
   (`/admin/migraciones` → "Actualizar base de datos").
