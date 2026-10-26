# Estado del proyecto — checklist

Actualizado: 2026-10-09. **Mantener este archivo al día**: marcar `[x]` al terminar cada entrega, en el mismo commit.
Detalle técnico de cada etapa hecha: `CLAUDE.md`. Diseños: `docs/design/`. Lo que falta en detalle: `docs/ROADMAP.md`.

## Quién hace qué
- **Codex**: etapas web pendientes (14 entregas 2–3, 15, 16, 17, 18, 19) y la mejora de "migraciones pendientes".
- **Claude**: app Android nativa de guardias (`android/` + los cambios de servidor que pide, ver
  `docs/design/app-android-guardias.md`).
- Reglas para trabajar en paralelo: `AGENTS.md` → "Trabajo en paralelo".

## Hecho
- [x] Etapa 0–1 — Instalador web, base maestra, migraciones desde el panel, super-admin
- [x] Etapa 2 — Empresas con base propia, impersonación de solo lectura, auditoría, archivos
- [x] Etapa 3 — Usuarios, roles y permisos por módulo/alcance, 2FA, API JWT para el celular
- [x] Etapa 4 — Datos maestros (plantas, sectores, puestos, empleados, contratistas, equipos con QR), importación CSV/XLSX
- [x] Etapa 5 — API de sincronización offline (pull por cursor, push idempotente, fotos por partes)
- [x] Etapa 6 — Observaciones (evidencia inmutable, flujo, mapa, imprimible)
- [x] Etapa 7 — Notificaciones (reglas, cola, email, Web Push, escalamiento, cron por URL)
- [x] Etapa 8 — App de campo web retirada; la operación de guardias continúa en la app Android nativa
- [x] Etapa 9 — Rondas de guardias (puntos QR, rutas asignadas, historial)
- [x] Etapa 10 — Acciones CAPA (3 entregas) — `docs/design/etapa-10-capa.md`
- [x] Etapa 11 — Inspecciones y checklists (3 entregas) — `docs/design/etapa-11-inspecciones.md`
- [x] Etapa 12 — Incidentes y accidentes (3 entregas) — `docs/design/etapa-12-incidentes.md`
- [x] Etapa 13 — Permisos de trabajo (3 entregas) — `docs/design/etapa-13-permisos-trabajo.md`
- [x] Etapa 14 entrega 1 — EPP: catálogo, matriz por puesto, entregas firmadas, constancia SRT 299/11
- [x] Automatizaciones de rondas — Fase 1: configuración por empresa y parámetros para Android
- [x] Rediseño visual: menú lateral, paleta #353c4f / #f2c014, inicio como tablero, logo configurable
- [x] App Android 1.2.1: navegación inferior con botón QR central, acceso directo a la cámara y nuevo logo de Security App
- [x] App Android: navegación inferior respeta el área de botones nativos del sistema y deja el espacio inferior negro
- [x] App Android: advertencia visible y bloqueo preventivo de acciones de turno en modo «Ver como» (solo lectura)
- [x] App Android: mapa de rutas con Google Maps (puntos, recorrido y vista satelital opcional; clave local fuera del repositorio)
- [x] App Android: recordatorio de rondas programadas 5 minutos antes, aviso a la hora, atraso y próxima ronda visible en el inicio
- [x] App Android 1.5.5: lista offline de autorizados en el Libro y selección precargada para ingresos de personas/vehículos

### Hecho — últimas entregas (Claude, hasta el 26/10/2026; detalle técnico en `CLAUDE.md`)
- [x] Mapas siempre en satélite (web y app); ronda en vivo en la app y en el panel; edición de puntos sin cambiar el QR
- [x] CRUD completo: eliminar (baja lógica) datos maestros, usuarios, plantillas/programas, catálogo EPP, reglas, rutas, puntos y
      programaciones; editar ausencias; editar/eliminar turnos; anular rondas con motivo. La evidencia nunca se edita ni se borra
- [x] Turnos con **horario por día** (franjas de hasta 72 h, ej. Sáb 14:00 → Lun 08:00) — tenant 0076
- [x] App 1.4.x: transiciones, atrás predictivo, vibración, deslizar para sincronizar, **modo oscuro**, botón central "Escanear"
- [x] **Turnos por puesto** (guardias tercerizados que se organizan solos): franjas a cubrir, relevo, "Dejar el puesto sin persona"
      con motivo, avisos de cobertura, reporte de cobertura — tenant 0077–0079
- [x] **Celular compartido**: una cuenta del puesto, cada guardia se identifica con PIN; operaciones con `as_user` (`ActingGuard`) — app 1.5.0
- [x] **Guardias = empleados** (sin usuario): PIN en Turnos → Guardias y PIN; perfil interno `users.no_login` — tenant 0083
- [x] **Reporte de fin de turno** por franja (email a RRHH con casillas fijas en las reglas), al terminar el turno sin depender del
      cron; "Ver reporte" / "Enviar reporte ahora"; cron de respaldo también desde la app (LiteSpeed o PHP-FPM) — tenant 0080
- [x] Libro: **condición insegura** (aviso crítico inmediato + observación), **lista de autorizados** (personas y vehículos con
      vigencia/días/horario, importación Excel, control sin señal en la app) — tenant 0081
- [x] Libro en la **notebook del puesto**: anota a nombre del guardia de turno del celular, sesión recordada 90 días
      (`PostTerminal`) — tenant 0082; aviso **"sin turno iniciado"** (se anota igual, marcado, avisa al supervisor) también para
      guardias con cuenta propia — app 1.5.4 (`versionCode 24`, última)
- [x] **CRUD de roles**: crear (en blanco o partiendo de otro), editar y eliminar pasando sus usuarios a otro rol; sin "Copiar"
- [x] **Super-admins** (crear, editar, cambiar contraseña, desactivar, eliminar) y **eliminar / restaurar empresas** — master 0008
- Para retomar: migraciones tenant al día hasta la **0083** y master hasta la **0008**; tests `php tests/run.php` = 238 OK. Zip de actualización del servidor:
  `(git diff --name-only d9ea828 HEAD -- app public database; echo .htaccess) | sort -u | xargs git archive --format=zip -o ~/Desktop/deploy/actualizacion-servidor.zip HEAD`.
  Ojo: el libro es una cadena de huellas (nunca UPDATE de campos de `Logbook::HASHED`); no crear datos de prueba en la
  instalación real de Indumor; Permisos de trabajo y EPP siguen ocultos en la app hasta que el usuario lo pida.

### Decisión sobre el cliente móvil

La PWA `/movil/` fue eliminada del código y del roadmap el 09/10/2026. Se descartó porque los guardias usarán
exclusivamente la app Android nativa, que cubre mejor GPS, QR, NFC, funcionamiento offline, pánico y notificaciones.
Se conservaron las APIs v1 y la sincronización del servidor para que Android siga usando el backend.

## Pendiente — web (Codex)
- [x] **Mejora rápida: migraciones pendientes**. El super-admin ve el aviso y el enlace a `/admin/migraciones`; una tabla
      ausente muestra una pantalla clara (sin exponer migraciones a usuarios de `/panel`).
- [x] **Etapa 14 entrega 2 — EPP seguimiento** (diseño ya aprobado: `docs/design/etapa-14-epp.md` secciones 5 y 11):
  - [x] tablero de cumplimiento (% al día, vencidos/por vencer por sector, nunca entregados)
  - [x] entrega por lote (por sector o puesto, firma uno por uno)
  - [x] avisos por cron: `ppe.due_soon` (lunes, resumen), `ppe.overdue` (una vez), `ppe.missing` (ingreso sin EPP)
  - [x] CSV de estado
  - [x] imprimir constancias de un sector (una por hoja)
  - [x] "pendientes de EPP" en el inicio para supervisores
  - [x] setting `epp.aviso_dias` editable en Configuración
- [ ] **Etapa 14 entrega 3 — EPP en la app Android nativa**: entregar con firma sin señal (push `ppe.delivery` idempotente
      por uuid), consulta del EPP de un empleado y sincronización de catálogo, matriz y últimas entregas.
- [ ] **Etapa 15 — Capacitaciones** (diseño + decisiones del usuario primero)
- [ ] **Etapa 16 — Indicadores, reportes y exportaciones** (diseño primero)
- [ ] **Etapa 17 — Capa comercial SaaS** (diseño primero; depende de que exista producción)
- [ ] **Etapa 18 — Integraciones** (diseño primero)
- [ ] **Etapa 19 — Hardening y operación** (antes de salir a producción con clientes)

## App Android (Claude) — para todos los empleados (la PWA se eliminó)
- [x] Entrega 1 — Base: proyecto `android/`, login con 2FA, consentimiento, sincronización, módulos según permisos, distribución propia del APK
- [x] Entrega 2 — Rondas offline con QR (cola de envío + WorkManager, CameraX + ML Kit)
- [x] Entrega 3 — GPS durante la ronda, pánico (datos + SMS), "guardia sin señal"
- [x] Entrega 4 — Observaciones con fotos, notificaciones FCM, canal crítico, avisos con "Recibido" / "Atendido"
- [x] Entrega 5 — Acciones (tomar, cerrar con fotos sin señal) e inspecciones (checklists por QR o lista, fotos por ítem)
- [x] Entrega 6 — Incidentes y accidentes sin señal (permisos de trabajo desactivados en la app por ahora)
- [x] Entrega 7 — NFC en rondas, ícono, firma de la versión final, versión 1.0.0 lista para publicar (EPP desactivado en la app por ahora)
- [x] Entrega 8 — Ubicación de puntos desde el celular y reporte web de marcas fuera de lugar por guardia/punto

- [x] Automatizaciones de rondas: fases 1–6 terminadas; además, el primer QR/NFC puede iniciar automáticamente una ronda (ruta única, selector si hay varias, libre si no hay ninguna) (`docs/design/automatizaciones-rondas.md`)

## Pendiente — operación (usuario)
- [x] Aplicar migraciones en Indumor (al día hasta la 0069)
- [ ] Subir el último zip y aplicar en /admin/migraciones hasta la 0083; publicar el APK 1.5.5 en /admin/app-android
- [ ] Configurar SMTP en /admin/configuracion y agregar la casilla de RRHH a las reglas urgentes (condición insegura, reporte de fin de turno)
- [ ] Ingresar una vez en la notebook del puesto con la cuenta del puesto; cargar los guardias como empleados con PIN
- [ ] Pausar o eliminar programaciones de prueba (generan avisos de "no vino"); marcar "Atendido" los pánicos de prueba
- [x] `gh auth login` y `git push`
- [ ] Primer deploy al hosting: URL de producción, docroot a `public/`, `/install/check.php` en verde, cron por URL
- [ ] Respaldar `config/config.local.php` (y más adelante el keystore de la app Android)
- [x] Crear el proyecto de Firebase y pegar `google-services.json` + cuenta de servicio en /admin/configuracion (notificaciones con la app cerrada)
- [ ] App Android 1.0: crear la clave de firma, `android/keystore.properties` con la URL de producción, compilar y publicar en /admin/app-android (pasos en `docs/design/app-android-guardias.md`)
