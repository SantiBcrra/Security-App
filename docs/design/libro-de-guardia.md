# Libro de guardia (fase 1)

Decisiones del usuario (21/10/2026): los guardias son de una **empresa de seguridad contratada** (su libro rubricado en papel
sigue siendo responsabilidad de ellos; este es el libro de Indumor, con búsqueda y valor de prueba, y el parte diario
imprimible con folios les sirve de respaldo). Datos de visitantes **optativos**; **ingreso libre** (se registra lo que el
guardia anota); **cierre de turno con firma** siempre y **recepción con firma** solo si llega otro guardia; los datos de
visitantes **se conservan para siempre** (borrado manual por fecha, auditado, a riesgo del usuario).

## Modelo (tenant 0073)
- `logbook_entries`: una anotación del libro de un predio (`site_id`), con número de folio por predio (`folio`,
  `Sequences::next('libro:{site}')`). `kind`: novedad | persona | vehiculo | salida | correccion | cierre | recepcion.
  Novedad: `category` (rutina, incidente, alarma, mantenimiento, llamada, entrega, otro), `text`, `important`.
  Persona: `person_name`, `person_doc`, `company`, `visiting`, `reason`, `badge`. Vehículo: `plate` (normalizada sin
  espacios ni guiones, mayúsculas), `vehicle_type`, `driver`, `company`, `load_note`. Salida: `ref_entry_id` (la entrada que
  se cierra). Corrección: `ref_entry_id` + texto. Cierre: `text` (comentario) + `data` (resumen: novedades, adentro) +
  firma. Recepción: `ref_entry_id` (el cierre) + texto + firma.
  Hora del hecho en el celular (`occurred_at`) y del servidor (`created_at`), `user_id`, `shift_slot_id`, GPS opcional.
  **Inmutable**: nunca se edita ni se borra; las columnas de caché `exited_at` / `exit_entry_id` (entradas de persona o
  vehículo) y `purged_at` no son parte de la huella.
- **Cadena de huellas** por predio: `hash = sha256(prev_hash + JSON canónico de los campos inmutables)`. `verify()` recorre
  la cadena y detecta cualquier alteración (las anotaciones purgadas solo validan el encadenamiento).
- `logbook_attachments`: fotos (patente, remito, daño) con SHA-256, como el resto de la evidencia.
- `logbook_known`: padrón para autocompletar (persona por DNI, vehículo por patente) con los últimos datos y
  `last_seen_at`; lo mantiene el servicio y baja al celular.

## Reglas
- Persona: alcanza con nombre, DNI **o** empresa. Vehículo: patente obligatoria. La salida marca la entrada (una vez).
- "Adentro ahora" = personas y vehículos con entrada y sin salida, por predio.
- Cierre de turno: resumen automático (novedades del tramo, quién sigue adentro) + comentario + firma. Si quedan personas o
  vehículos adentro → aviso `logbook.inside_at_close` (SyH + supervisores). Recepción: firma del que entra sobre el último
  cierre sin recibir del predio (opcional).
- Novedad importante → `logbook.important` (SyH + supervisores).
- Cron: personas o vehículos adentro hace más de `libro.horas_adentro` (12) → un aviso por día `logbook.inside_long`.
- Purga manual (Configuración, `libro.editar` con alcance todo): borra los datos personales (nombre, DNI, patente,
  conductor) de anotaciones anteriores a una fecha; queda `purged_at` y auditoría.

## Permisos (módulo `libro`, activable)
Admin y SyH: todo. Supervisor: ver, crear, exportar. Reportante (guardia): ver y crear. Auditor: ver y exportar.

## Pantallas
- Panel `/panel/libro`: predio + día, línea de tiempo con folios, "Adentro ahora" con "Salió", alta rápida (novedad,
  persona, vehículo) para la garita con PC, cierre y recepción con firma, corrección de una anotación.
  `/panel/libro/buscar` (patente, DNI, nombre, texto, fechas), `/panel/libro/imprimir` (parte diario con folios y verificación
  de la cadena), `/panel/libro/exportar` (CSV, auditado).
- App Android (entrega 2): Libro en el inicio con + Novedad / + Persona / + Vehículo, "Adentro ahora", autocompletado por
  DNI o patente (padrón + empleados), fotos, cierre y recepción con firma integrados con Empezar / Terminar turno.
  Sync: pull `logbook_entries` (últimas 48 h + las que siguen adentro) y `logbook_known`; push `logbook.create`,
  `logbook.exit`, `logbook.correct`, `logbook.close`, `logbook.receive`; fotos con `uploads.logbook_entry_uuid`.
