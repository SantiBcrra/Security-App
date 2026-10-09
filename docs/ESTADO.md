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
- [x] Etapa 8 — App de campo PWA en `/movil/`
- [x] Etapa 9 — Rondas de guardias (puntos QR, rutas asignadas, historial)
- [x] Etapa 10 — Acciones CAPA (3 entregas) — `docs/design/etapa-10-capa.md`
- [x] Etapa 11 — Inspecciones y checklists (3 entregas) — `docs/design/etapa-11-inspecciones.md`
- [x] Etapa 12 — Incidentes y accidentes (3 entregas) — `docs/design/etapa-12-incidentes.md`
- [x] Etapa 13 — Permisos de trabajo (3 entregas) — `docs/design/etapa-13-permisos-trabajo.md`
- [x] Etapa 14 entrega 1 — EPP: catálogo, matriz por puesto, entregas firmadas, constancia SRT 299/11
- [x] Rediseño visual: menú lateral, paleta #353c4f / #f2c014, inicio como tablero, logo configurable

## Pendiente — web (Codex)
- [x] **Mejora rápida: migraciones pendientes**. El super-admin ve el aviso y el enlace a `/admin/migraciones`; una tabla
      ausente muestra una pantalla clara (sin exponer migraciones a usuarios de `/panel`).
- [x] **Etapa 14 entrega 2 — EPP seguimiento** (diseño ya aprobado: `docs/design/etapa-14-epp.md` secciones 5 y 11):
  - [x] tablero de cumplimiento (% al día, vencidos/por vencer por sector, nunca entregados)
  - [x] entrega por lote (por sector o puesto, firma uno por uno)
  - [x] avisos por cron: `ppe.due_soon` (lunes, resumen), `ppe.overdue` (una vez), `ppe.missing` (ingreso sin EPP)
  - [x] CSV de estado
  - [x] imprimir constancias de un sector (una por hoja)
  - [ ] "pendientes de EPP" en el inicio para supervisores
  - [x] setting `epp.aviso_dias` editable en Configuración
- [ ] **Etapa 14 entrega 3 — EPP en la PWA** (`/movil/`): entregar con firma sin señal (push `ppe.delivery` idempotente
      por uuid), consulta del EPP de un empleado, pull de `ppe_items`, `ppe_matrix` y últimas entregas.
- [ ] **Etapa 15 — Capacitaciones** (diseño + decisiones del usuario primero)
- [ ] **Etapa 16 — Indicadores, reportes y exportaciones** (diseño primero)
- [ ] **Etapa 17 — Capa comercial SaaS** (diseño primero; depende de que exista producción)
- [ ] **Etapa 18 — Integraciones** (diseño primero)
- [ ] **Etapa 19 — Hardening y operación** (antes de salir a producción con clientes)

## Pendiente — app Android de guardias (Claude)
- [ ] Entrega 1 — Base: proyecto `android/`, login con 2FA, consentimiento, pull de rutas, distribución propia del APK
- [ ] Entrega 2 — Rondas offline con QR
- [ ] Entrega 3 — GPS durante la ronda, pánico (datos + SMS), "guardia sin señal"
- [ ] Entrega 4 — Novedades con fotos, notificaciones FCM, canal crítico
- [ ] Entrega 5 — NFC, firma de release, versión 1.0

## Pendiente — operación (usuario)
- [ ] Aplicar migraciones en Indumor (`/admin/migraciones` → "Actualizar base de datos")
- [ ] `gh auth login` y `git push` (hay commits locales sin subir)
- [ ] Primer deploy al hosting: URL de producción, docroot a `public/`, `/install/check.php` en verde, cron por URL
- [ ] Respaldar `config/config.local.php` (y más adelante el keystore de la app Android)
