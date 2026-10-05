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
- Evidencia original (fotos, firmas, reporte inicial) nunca se edita: enmiendas como eventos.
- Textos de la interfaz en español (preparados para i18n).
- Assets locales en `public/assets` (Bootstrap 5.3.3, Alpine 3.14.1). Nada de CDN.

## Estructura
```
public/            docroot (index.php front controller, assets/, check.php)
app/Core/          App, Router, Request, Response, View, DB, Config, ErrorHandler, Logger, Session,
                   Storage, Migrator, Csrf, Flash, Validator, Uuid, EnvCheck, helpers
app/Middleware/    RequireInstalled, VerifyCsrf (globales), RequireSuperAdmin
app/Controllers/   Web/ (Install, Admin/*) y Api/
app/Models/        acceso a datos (único lugar con SQL)
app/Services/      lógica (AdminAuth)
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
