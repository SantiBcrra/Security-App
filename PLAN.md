# PLAN — Sistema de Seguridad e Higiene (SaaS multi-empresa)
## PHP puro + MySQL, sin framework, deploy por copia de archivos

## Visión
Plataforma SaaS multi-tenant para reportar y gestionar seguridad e higiene industrial:
observaciones de actos/condiciones inseguras, acciones correctivas, inspecciones,
incidentes, permisos de trabajo, EPP y capacitaciones. La usan muchas empresas desde
una sola instalación. Tiene una app móvil con modo offline para quienes reportan en
planta (operarios, supervisores, guardias de seguridad).

## REGLA DE ORO DE DESPLIEGUE (no negociable)
El sistema se instala igual en XAMPP que en el hosting de producción:
1. Copiar los archivos (FTP/SFTP, administrador de archivos o deploy automático).
2. Crear una base MySQL vacía desde el panel del hosting.
3. Abrir /install en el navegador y completar el instalador web.
PROHIBIDO depender de: Composer en el servidor, comandos CLI, workers permanentes,
supervisor, Node en el servidor, Redis, configuración de Apache fuera de .htaccess,
o extensiones PHP poco comunes.

## Stack
- PHP 8.1+ (verificar la versión exacta del hosting y no usar nada más nuevo)
- MySQL 5.7+/MariaDB 10.4+ vía PDO, con consultas preparadas siempre
- Apache + .htaccess (igual que XAMPP)
- Arquitectura propia, ligera, mismo estilo que MetalERP: front controller, router,
  controladores, modelos/repositorios, vistas PHP
- Frontend web sin paso de build: Bootstrap 5 + Alpine.js y/o htmx como archivos locales
  en /public/assets (no depender de CDN en producción)
- Librerías PHP puras vía Composer SOLO en local; la carpeta vendor/ se sube junto con
  el código. Permitidas: firebase/php-jwt, phpmailer/phpmailer, dompdf/dompdf,
  phpoffice/phpspreadsheet (o CSV nativo). Ninguna que requiera extensiones raras.
- Auth web: sesiones PHP + CSRF. Auth app móvil: JWT con access y refresh token
  (mismo enfoque que la capa MCP de MetalERP)
- App móvil: React Native con Expo + expo-sqlite. Se compila en la nube de Expo (EAS);
  no toca el hosting.
- Push: API HTTP de Expo Push vía cURL desde PHP. Email: PHPMailer por SMTP.
  WhatsApp: API HTTP (Meta Cloud API o webhook a GoHighLevel).

## Estructura de carpetas
/public              ← único directorio accesible por web (document root si el hosting
                       lo permite)
  index.php          ← front controller
  .htaccess          ← rewrite a index.php + pasar header Authorization
  /assets            ← css, js, imágenes estáticas
/app
  /Core              ← Router, Request, Response, DB, Auth, Tenant, Session, Csrf,
                       Validator, View, Logger, Mailer, Queue, Storage
  /Controllers       ← /Web y /Api
  /Models
  /Services
  /Views
  /Policies          ← permisos por módulo
/config
  config.php         ← valores por defecto (en git)
  config.local.php   ← credenciales del entorno (NO va a git; lo genera el instalador)
/database
  /migrations        ← archivos .sql numerados: 0001_tenants.sql, 0002_users.sql...
  /seeds             ← datos demo y plantillas por rubro
/storage             ← archivos subidos, logs, caché (bloqueado con .htaccess "Deny from all")
/install             ← instalador web (se bloquea solo al terminar)
/cron                ← tareas programadas, ejecutables por URL secreta
/vendor              ← dependencias Composer, versionadas en el repo
/tests               ← PHPUnit (corre solo en local/CI)

Si el hosting NO permite cambiar el document root a /public: un .htaccess en la raíz
redirige todo a /public y bloquea el acceso directo a /app, /config, /storage,
/database y /vendor.

## Reglas para Claude Code (aplican a todas las etapas)
1. Antes de codear cada etapa: proponer el diseño en plan mode y esperar aprobación.
2. Respetar SIEMPRE la regla de oro de despliegue. Si algo requiere consola en el
   servidor, buscar la alternativa web.
3. Toda tabla de negocio lleva tenant_id + índice compuesto (tenant_id, ...). Sin excepción.
4. IDs públicos = UUID generado en PHP (y en el celular para registros offline).
   La PK interna puede ser INT AUTO_INCREMENT.
5. Todo acceso a la base pasa por la capa DB/Repositorio, que aplica el filtro de
   tenant automáticamente. Prohibido SQL suelto en controladores.
6. Seguridad básica obligatoria: PDO preparado, escape en vistas (helper e()),
   CSRF en todo form, password_hash/password_verify, headers de seguridad.
7. Tests de aislamiento: todo endpoint nuevo tiene un test que prueba que la empresa A
   NO puede ver ni modificar datos de la empresa B.
8. Nunca editar evidencia original (fotos, firmas, reporte inicial). Los cambios se guardan
   como enmiendas o eventos.
9. Fechas en UTC en la base; se muestran en la zona horaria de cada empresa.
10. Textos de la interfaz en español, preparados para i18n.
11. Mantener CLAUDE.md con convenciones, decisiones y lista de verificación de deploy.

---

## ETAPA 0 — Fundaciones y compatibilidad con el hosting
- Crear la estructura de carpetas anterior, el .gitignore (excluye config.local.php y
  /storage/*) y el CLAUDE.md.
- /install/check.php: diagnóstico del entorno. Verifica la versión de PHP, las extensiones
  (pdo_mysql, mbstring, openssl, curl, gd, fileinfo, zip, json), que /storage sea
  escribible, el mod_rewrite, que el header Authorization llegue a PHP y los límites
  upload_max_filesize, post_max_size, max_execution_time y memory_limit.
  Correrlo primero en el hosting real ANTES de seguir.
- Core mínimo: front controller, router con grupos y middlewares, Request/Response,
  conexión PDO singleton, vistas con layout, manejo global de errores
  (log en /storage/logs, pantalla genérica en producción), helpers.
- Configuración: config.php + config.local.php; el modo debug se activa solo en local.
- Deploy: GitHub Actions con FTP/SFTP deploy (o SSH si el hosting lo tiene), subiendo
  vendor/ y excluyendo config.local.php y /storage. Alternativa manual documentada:
  subir un zip.
**Listo cuando:** la misma copia del código muestra "OK" en XAMPP y en el hosting,
y check.php da todo en verde en ambos.

## ETAPA 1 — Instalador web y migraciones sin consola
- Instalador /install (al estilo WordPress): pide los datos de MySQL, prueba la conexión,
  genera config.local.php, ejecuta las migraciones, crea el super-admin y se bloquea
  creando /storage/installed.lock.
- Migrador web: tabla schema_migrations; los .sql de /database/migrations se aplican en
  orden. En el panel super-admin hay un botón "Actualizar base de datos" que aplica las
  pendientes, para usar después de cada deploy.
- Migraciones idempotentes y transaccionales cuando se pueda; registrar errores.
**Listo cuando:** se instala desde cero en el hosting sin tocar phpMyAdmin ni consola,
y una migración nueva se aplica con un botón.

## ETAPA 2 — Núcleo multi-tenant
- Tabla tenants: uuid, nombre, slug, razón social, CUIT, logo, zona horaria,
  estado (activo/suspendido/trial), plan, configuración JSON.
- Tenant::resolve(): en web, desde la sesión del usuario logueado; en la API, desde el JWT.
  No depender de subdominios comodín (muchos hostings no los permiten); dejarlos como
  opción futura.
- Capa DB con filtro automático: los repositorios agregan "WHERE tenant_id = ?" y asignan
  tenant_id al insertar. Las consultas sin tenant solo se permiten en un repositorio
  "Platform" explícito para el super-admin.
- Archivos: /storage/tenants/{tenant_uuid}/... Nunca se sirven directo; se entregan por
  un controlador PHP que valida sesión, tenant y permiso, o por un link firmado temporal.
- Log de auditoría (tabla audit_log): usuario, tenant, acción, entidad, antes/después
  en JSON, IP, dispositivo y fecha.
- Panel super-admin: alta, edición y suspensión de empresas, e impersonación auditada.
- Tests de aislamiento entre tenants.
**Listo cuando:** se pueden crear 2 empresas demo y los tests prueban que no se cruzan datos.

## ETAPA 3 — Usuarios, roles y permisos
- Un usuario puede pertenecer a varias empresas (tabla tenant_user con el rol por empresa).
- Tabla roles con permissions en JSON (mismo modelo que MetalERP) + helper Auth::can().
- Roles base (cada empresa puede clonarlos y ajustarlos):
  - super_admin: solo la plataforma, fuera de los tenants
  - admin_empresa: configuración total de su empresa
  - responsable_hys: gestiona todos los módulos de seguridad e higiene
  - supervisor: ve y gestiona solo SUS sectores asignados
  - reportante: operario o guardia; crea reportes y ve los propios
  - auditor: solo lectura (ART, consultor externo), con fecha de vencimiento de acceso
- Permisos por módulo y acción (ver, crear, editar, cerrar, exportar) + alcance por sector.
- Login web: sesión segura (regenerar el ID, cookies httponly/secure/samesite), bloqueo
  por intentos fallidos guardado en la base, 2FA TOTP opcional (implementación PHP pura).
- Login de la app: email o DNI + contraseña; access JWT corto + refresh token guardado
  hasheado en la base por dispositivo, revocable desde el panel.
- Invitación de usuarios por email/WhatsApp con link de activación.
**Listo cuando:** cada rol ve exactamente lo que le corresponde, con tests de permisos.

## ETAPA 4 — Datos maestros y configuración por empresa
- Plantas/sitios, con dirección y geocerca opcional.
- Sectores/áreas jerárquicos (planta > nave > sector).
- Puestos de trabajo.
- Empleados: un empleado no es necesariamente un usuario; tiene legajo, DNI, puesto y sector.
- Equipos: autoelevadores, puentes grúa, andamios, prensas, etc., con QR imprimible
  (generado con una librería PHP pura, o en el navegador con JS).
- Contratistas y su personal.
- Catálogos configurables: tipos de riesgo, categorías (acto/condición insegura),
  severidad, causas.
- Importación por CSV/Excel con vista previa y validación, procesada por lotes vía AJAX
  para no superar max_execution_time.
- Plantilla inicial por rubro (ej: "Metalúrgica") que precarga catálogos y checklists.
**Listo cuando:** una empresa nueva queda operativa importando un Excel en minutos.

## ETAPA 5 — API v1 y contrato de sincronización offline
- REST /api/v1 con JSON consistente ({ ok, data, error }) y documentación en un archivo
  OpenAPI (YAML) dentro del repo.
- .htaccess: regla para que HTTP_AUTHORIZATION llegue a PHP (algunos Apache lo eliminan).
- Pull: GET /api/v1/sync/pull?since=<cursor> devuelve catálogos y datos maestros
  modificados, incluidos los borrados (columna deleted_at), paginado.
- Push: POST /api/v1/sync/push recibe un lote chico de operaciones (ej: 50) con UUIDs del
  cliente. Tabla idempotency_keys: reintentar nunca duplica.
- Resolución de conflictos:
  - Datos maestros: gana el servidor.
  - Reportes: append-only; no hay conflictos, solo eventos nuevos.
  - Transiciones de estado: validadas en el servidor, con un error explicativo si no aplica.
- Subida de archivos separada y en partes (chunks de ~1 MB) para no chocar con
  upload_max_filesize/post_max_size; se reensamblan en el servidor y se verifica el hash.
- Cada registro guarda created_at_device (hora del evento) y received_at (hora del servidor).
- Rate limiting simple por token, guardado en una tabla MySQL.
**Listo cuando:** un script de prueba sincroniza 500 operaciones con fotos, simula cortes
y reintentos, y no duplica nada.

## ETAPA 6 — Módulo Observaciones (MVP estrella)
- Reporte de acto o condición insegura: sector, GPS, tipo de riesgo, severidad,
  descripción, fotos/video, personas involucradas (opcional) y equipo (opcional, por QR).
- Flag "RIESGO INMINENTE": dispara alerta inmediata (etapa 7) y la app indica:
  "Avisá en persona/radio al supervisor y frená la tarea".
- Reporte anónimo opcional, configurable por empresa.
- Workflow: abierto → en análisis → con acción asignada → cerrado (o descartado, con motivo).
- Evidencia inmutable: hash SHA-256 de cada archivo y metadata original preservada;
  las correcciones se guardan como eventos.
- Panel web: listado con filtros (sector, severidad, estado, fecha), mapa (Leaflet
  + OpenStreetMap, sin API key) y detalle con línea de tiempo.
- PDF del reporte con dompdf.
**Listo cuando:** se reporta desde web, queda trazable y la línea de tiempo muestra cada evento.

## ETAPA 7 — Motor de notificaciones (sin workers)
- Tabla notification_queue: evento, destinatario, canal, payload, estado, intentos,
  próximo intento, error.
- Canales: in-app (tabla + campanita con polling AJAX), push (Expo vía cURL),
  email (PHPMailer SMTP) y WhatsApp (HTTP).
- Procesamiento sin workers permanentes:
  1. ALERTAS CRÍTICAS (riesgo inminente): se envían en el mismo request que crea el
     reporte, con timeouts cortos de cURL. Si fallan, quedan en la cola para reintento.
  2. RESTO: /cron/run.php?key=SECRETO procesa la cola en lotes chicos con tiempo
     máximo acotado. Se dispara por (a) el cron del panel del hosting si existe,
     (b) un servicio externo gratuito tipo cron-job.org cada 1–5 min, o (c) fallback
     "lazy": un request web cada X minutos procesa un lote corto.
  3. Lock con tabla/fila en MySQL para que dos ejecuciones no se pisen.
- Reglas configurables por empresa: qué evento, con qué severidad y en qué sector,
  notifica a qué rol o usuario y por qué canal.
- Escalamiento: si una alerta crítica no se confirma en X minutos, se notifica al
  siguiente nivel (lo evalúa el cron).
- Botón "Recibido" en alertas críticas; resúmenes diario y semanal por email.
- Preferencias por usuario (silenciar canales no críticos) y log de cada envío.
**Listo cuando:** un riesgo inminente llega por push + WhatsApp en segundos, y si nadie
confirma, escala usando solo el cron por URL.

## ETAPA 8 — App móvil (Expo), offline-first
Puede arrancar en paralelo a partir de la Etapa 5.
- Login, selección de empresa (si el usuario pertenece a varias), registro del token push
  enviado al backend y refresh token automático.
- SQLite local: catálogos, sectores, equipos y reportes propios.
- Cola de salida (outbox): toda acción se guarda local primero y se sincroniza cuando hay
  red, con reintento exponencial y lotes chicos.
- Fotos comprimidas en el celular (máx ~1600 px) antes de subir; subida por chunks.
- Indicador claro de "pendiente de enviar" en cada registro.
- Cámara, GPS (con precisión registrada) y escáner QR de equipos y puntos.
- Formularios dinámicos según la configuración del tenant.
- Sincronización en segundo plano al recuperar conexión; bandeja de notificaciones y tareas.
- Builds con EAS en la nube de Expo (Android primero; iOS después).
**Listo cuando:** en modo avión se cargan 10 reportes con fotos y al recuperar la señal
se suben todos sin duplicados.

=== FIN DEL MVP (Etapas 0–8): usable en una planta real ===

## ETAPA 9 — Acciones correctivas y preventivas (CAPA)
- Una acción puede nacer de cualquier origen (observación, inspección, incidente,
  auditoría), con columnas origen_tipo y origen_id.
- Responsable, fecha límite, prioridad y evidencia de cierre obligatoria.
- Verificación de eficacia: un segundo paso, a cargo de otro rol, valida el cierre.
- Recordatorios automáticos por cron (antes y después del vencimiento).
- Tablero de acciones vencidas por sector y responsable.

## ETAPA 10 — Inspecciones y checklists
- Constructor de plantillas por empresa: ítems sí/no/N.A., numéricos, foto obligatoria,
  ítems críticos (estructura en JSON + tablas de respuestas).
- Programación recurrente generada por el cron (diaria, semanal, mensual).
- Escanear el QR de un equipo abre su checklist (ej: pre-uso del autoelevador).
- Un ítem que no cumple genera una acción correctiva automáticamente.
- Cumplimiento: inspecciones hechas vs. programadas.
- Plantillas precargadas para metalúrgica: autoelevador, puente grúa, amoladoras,
  soldadura, orden y limpieza, extintores.

## ETAPA 11 — Incidentes, accidentes e investigación
- Accidentes con o sin baja, incidentes y casi-accidentes.
- Datos del accidentado, lesión, parte del cuerpo, días perdidos y seguimiento.
- Investigación: 5 porqués y árbol de causas, con acciones derivadas.
- Datos preparados para la denuncia ante la ART.

## ETAPA 12 — Permisos de trabajo
- Tipos: trabajo en altura, en caliente (soldadura y corte), espacio confinado, LOTO y eléctrico.
- Checklist previo obligatorio, firmas digitales (canvas → imagen PNG con hash),
  ventana de validez.
- Estados: solicitado → aprobado → en ejecución → cerrado; vencimiento automático por cron.
- Vista "permisos activos ahora" por planta.

## ETAPA 13 — EPP
- Catálogo de EPP y matriz por puesto.
- Entrega con firma del empleado en el celular.
- Vencimientos y reposiciones.
- Constancia PDF (dompdf) en formato de la Res. SRT 299/11.

## ETAPA 14 — Capacitaciones
- Cursos, matriz por puesto, asistencia con firma y vencimientos.
- Alertas de capacitaciones vencidas por empleado.

## ETAPA 15 — Indicadores, reportes y exportaciones
- Dashboard por empresa con Chart.js (archivo local): índices de frecuencia y gravedad,
  días sin accidentes, observaciones por sector/tipo/turno, acciones abiertas o vencidas,
  cumplimiento de inspecciones.
- Consultas agregadas con índices adecuados; tablas resumen recalculadas por cron si
  las consultas se vuelven pesadas.
- Exportación a Excel (PhpSpreadsheet o CSV) y PDF; informe mensual por email.

## ETAPA 16 — Capa comercial SaaS
- Planes con límites (usuarios, plantas, almacenamiento, módulos habilitados),
  validados en el middleware.
- Alta self-service con trial.
- Facturación: Mercado Pago y/o Stripe, vía API HTTP con webhooks a un endpoint PHP.
- Marca blanca opcional: logo y colores por empresa.

## ETAPA 17 — Integraciones
- API pública con tokens por empresa y webhooks salientes (por la cola de la etapa 7).
- Conector MetalERP/indumor (opcional, nunca una dependencia): sincroniza empleados y sectores.
- Conector MCP para consultar el sistema desde Claude (mismo enfoque que el de MetalERP).

## ETAPA 18 — Hardening y operación
- Revisión de seguridad (OWASP): inyección, XSS, CSRF, IDOR entre tenants, subida de
  archivos (validar MIME real, renombrar, nunca ejecutar).
- Backups: exportación SQL programada por cron vía PHP + copia de /storage; prueba de
  restauración documentada. Exportación completa de los datos de una empresa.
- Página de estado y log de errores visible para el super-admin; alertas de errores por email.
- Cumplimiento de la Ley 25.326 (datos personales): términos, consentimiento, retención y borrado.
- Pruebas de carga sobre sync y subida de archivos en el hosting real.