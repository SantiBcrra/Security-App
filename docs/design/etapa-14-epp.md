# Etapa 14 — Elementos de protección personal (EPP) · Diseño

Estado: **aprobado** · entrega 1 hecha. Decisiones del usuario: sin stock (solo entregas); solo personal propio; firma en pantalla o
planilla en papel escaneada; matriz por puesto + extras por empleado.

## 1. Qué resuelve
Hoy la entrega de EPP se anota en planillas de papel. Esas planillas se pierden, nadie sabe qué
le toca a cada puesto ni cuándo vence un casco o un par de botines, y ante una inspección o un
accidente cuesta demostrar que se entregó. La Etapa 14 suma el módulo **EPP**. El permiso `epp`
ya existe en `Permissions`.

El módulo tiene:
- un **catálogo de EPP** con marca, modelo, certificación y vida útil;
- una **matriz por puesto** que dice qué corresponde a cada puesto y cada cuánto se repone;
- **entregas con firma del empleado** en pantalla (web o celular, también sin señal), inmutables;
- **vencimientos y reposiciones** con avisos por cron;
- la **constancia de la Res. SRT 299/11**, imprimible por empleado;
- un **estado por empleado** ("le falta / vence / al día"), que también alimenta el
  cumplimiento de la Etapa 16.

## 2. Catálogo de EPP (`ppe_items`)
- Cada elemento tiene estos datos:
  - nombre ("Casco de seguridad", "Botín con puntera de acero");
  - categoría (ver abajo);
  - tipo o modelo, marca;
  - **certificación**: si la tiene y cuál (ej. "IRAM 3620", sello S);
  - **vida útil en días** (vacío = se repone solo por desgaste o rotura);
  - si **lleva talle** y de qué tipo (ropa, calzado, guantes);
  - activo o inactivo.
- Categorías fijas: cabeza, ojos y cara, auditiva, respiratoria, manos, pies, ropa de trabajo,
  caídas (arnés, cabo de vida), otros.
- Tablas maestras con `uuid`, `is_active`, `updated_at`, `deleted_at`, como las demás, para que
  viajen al celular.
- Precarga por rubro: `database/seeds/templates/metalurgica.php` → `epp`, idempotente por
  `preset_key`, con unos 15 elementos típicos y una matriz sugerida para los puestos de la plantilla.
- Importación desde Excel/CSV con el importador de la Etapa 4. Es opcional y puede quedar para
  después.

## 3. Matriz por puesto (`ppe_matrix`)
- Cada fila es puesto × elemento, con:
  - cantidad por entrega (ej. 2 pares de guantes);
  - vida útil propia, si difiere del catálogo (ej. guantes de soldador: 30 días);
  - **obligatorio** o **según tarea** (ej. protector facial solo al amolar: no cuenta como
    faltante, pero se puede entregar);
  - nota.
- Pantalla `/panel/epp/matriz`: grilla de puestos × EPP, con ✓ y la vida útil en cada celda. Se
  edita con un click por celda. Para armar un puesto nuevo, "copiar de otro puesto".
- **Extras por empleado** (`ppe_employee_extras`, decidido): elementos puntuales para una persona,
  por ejemplo un respirador por una tarea especial o anteojos con graduación. Llevan cantidad,
  vida útil y motivo, y se suman a lo del puesto en su estado y en su constancia.

## 4. Entregas (`ppe_deliveries` + `ppe_delivery_items`)
- **Una entrega** es un acto con firma: a un empleado, en una fecha, por un usuario y con un
  motivo. Los motivos son:
  - **inicial** (ingreso);
  - **reposición por vencimiento**;
  - **rotura o desgaste**;
  - **pérdida**;
  - **cambio de talle**;
  - **otro**.
- Lleva uno o varios **ítems**: elemento, cantidad, talle y una copia de marca, modelo y
  certificación al momento de la entrega (si después cambia el catálogo, la constancia no cambia).
- **Próxima reposición** de cada ítem = fecha de entrega + vida útil (la de la matriz o la del
  catálogo).
- Número **EPP-000001** con `Sequences`.
- **Firma del empleado**:
  - con `Signatures::store()` y `signature-pad.js` (los mismos de la Etapa 13), más el nombre y
    el DNI del empleado;
  - la entrega queda **inmutable**: `original_data` + hash, como observaciones e incidentes;
  - un error se corrige **anulando la entrega** (con motivo, queda en la historia) y cargándola
    de nuevo.
  - **sin pantalla a mano** (decidido): se puede subir la **planilla en papel firmada** (foto o PDF,
    `TenantFiles`, con hash) indicando el motivo. Queda marcada "firma en papel" y la constancia
    muestra "ver planilla adjunta" en lugar de la imagen de la firma.
- **Pantalla de entrega**: se elige el empleado. La pantalla muestra lo que le corresponde según
  la matriz, marcado:
  - vencido o nunca entregado → tildado por defecto;
  - por vencer → sugerido;
  - al día → sin tildar.
  Los talles se precargan con los del empleado. Firma → guardar.
- **Entrega por lote**: para el ingreso de un grupo o la reposición anual de ropa, se elige
  sector o puesto, lo que le falta a cada uno, y se firma uno por uno.
- **Talles del empleado** (ropa, calzado, guantes) en su ficha. Se actualizan solos con la última
  entrega.
- **Devolución** (opcional en la entrega): "devolvió el anterior" (sí/no). Se usa para reposición
  por rotura o pérdida.

## 5. Estado por empleado, vencimientos y reposiciones
- Por cada empleado activo, el estado de cada EPP **obligatorio** de su puesto, más sus extras:
  - **al día**: última entrega con próxima reposición > hoy + N días;
  - **por vence**: dentro de N días (setting `epp.aviso_dias`, 15);
  - **vencido**: próxima reposición < hoy;
  - **nunca entregado**: ingresó o cambió de puesto y no se le dio;
  - los elementos sin vida útil solo pueden estar "al día" o "nunca entregado".
- Se calcula en el momento: matriz + última entrega por empleado × elemento, en consultas sin
  window functions (subconsulta con `MAX(delivered_at)` agrupada). Si la base crece, se cachea
  en una tabla resumen por cron.
- **Tablero `/panel/epp`**:
  - % de empleados al día;
  - vencidos y por vencer por sector;
  - "nunca entregados" (ingresos recientes);
  - lista filtrable con botón "Entregar".
- **Ficha EPP del empleado** (`/panel/epp/empleado/{uuid}`):
  - lo que le corresponde con su estado;
  - talles;
  - historial de entregas con firmas;
  - botón "Constancia SRT 299/11".
- Avisos por cron (`Notify\PpeReminders` en `CronRunner`, una sola vez cada uno con
  `NotificationMarks`):
  - **`ppe.due_soon`**: lunes, resumen por sector de lo que vence en los próximos N días, a los
    supervisores del sector y a SyH;
  - **`ppe.overdue`**: una vez cuando se vence, agrupado por sector y día, a los mismos;
  - **`ppe.missing`**: empleado nuevo o cambio de puesto sin el EPP obligatorio, a los 3 días
    (`epp.dias_ingreso`), a SyH.
  - Los avisos se agrupan para no mandar uno por empleado y por elemento.
- Desde una observación de "no usa EPP" o de "EPP en mal estado" se puede ir a la ficha EPP del
  empleado y entregar. Es un enlace, sin automatismos.

## 6. Constancia Res. SRT 299/11
Una página imprimible (CSS de impresión, sin librerías), **una por empleado**, con el formato del
anexo de la resolución:
- **Encabezado**:
  - razón social, CUIT, dirección, localidad, CP y provincia. Vienen del alta de la empresa y de
    `empresa.*` en Configuración (Etapa 12);
  - nombre y apellido y DNI del trabajador;
  - descripción breve del puesto;
  - "elementos de protección personal necesarios para el trabajador, según el puesto": la matriz.
- **Tabla**, una fila por ítem entregado: producto, tipo/modelo, marca, posee certificación
  (sí/no), cantidad, fecha de entrega y **firma del trabajador** (la imagen de la firma de esa
  entrega).
- **Información adicional** y pie.
- Filtro por período (por defecto, todo el historial del empleado) y con anuladas excluidas.
- Imprimir varias de un sector a la vez es posible, una por hoja (`page-break`).

## 7. Stock (decidido: sin stock)
- En esta etapa solo se registran las entregas, como la planilla de hoy.
- El stock (ingresos por compra, descuento por entrega, mínimo con aviso) queda para más adelante.
  Se puede sumar sin cambiar las entregas.

## 8. Contratistas (decidido: solo personal propio)
- El contratista entrega el EPP a su gente; el módulo es **solo para el personal propio**
  (`employees.contractor_id IS NULL`).
- Los empleados de contratistas no aparecen en el tablero, en la matriz ni en las entregas.

## 9. Permisos y roles
Módulo `epp`, con las acciones de siempre:
- `ver`: tablero, fichas y constancias;
- `crear`: entregar;
- `editar`: catálogo, matriz y talles;
- `anular`: anular una entrega (cerrar);
- `exportar`: CSV de entregas y del estado.

Alcance por sector, con `SectorScope`, usando el sector del empleado. Roles base:
- **SyH y admin**: todo;
- **supervisor**: ver y entregar en sus sectores;
- **reportante**: nada. Más adelante podría ver su propio EPP si el empleado tiene usuario.

## 10. App de campo (PWA)
- **"Entregar EPP"** en la pestaña Nuevo:
  - se busca el empleado (ya sincronizado) o se escanea su credencial (si hay QR de empleado);
  - aparece lo que le corresponde según la matriz, con su estado;
  - se tildan los elementos y los talles;
  - **firma en la pantalla**;
  - se guarda **sin señal**, como el resto: push `ppe.delivery` idempotente por uuid del celular.
- Sincronización:
  - pull de `ppe_items` y `ppe_matrix` (solo a quien puede entregar);
  - estado del EPP de los empleados de su alcance: últimas entregas por empleado × elemento,
    livianas, para calcular "vencido" offline.
- "Consultar EPP de un empleado" (lectura) desde la misma búsqueda.

## 11. Entregas (cada una con tests y commit)
1. **Base**:
   - tablas, catálogo y matriz con precarga del rubro;
   - talles del empleado;
   - entrega web con firma, anulación y número;
   - ficha EPP del empleado con estado y constancia SRT 299/11;
   - permisos y roles.
2. **Seguimiento**:
   - tablero de cumplimiento;
   - entrega por lote;
   - avisos por cron (por vencer, vencidos, nunca entregados);
   - CSV;
   - impresión de constancias por sector;
   - "Mis pendientes de EPP" en el inicio (supervisor).
3. **App de campo**: entregar con firma sin señal, consulta por empleado y sync de catálogo,
   matriz y estado.

## 12. Tests
- Matriz: obligatorio y según tarea, vida útil de la matriz por encima de la del catálogo.
- Estado por empleado: al día, por vencer, vencido, nunca entregado y sin vida útil. Cambio de
  puesto.
- Entrega:
  - firma obligatoria;
  - copia de marca, modelo y certificación al momento de la entrega;
  - inmutable, con hash;
  - anular con motivo;
  - número correlativo.
- Constancia: datos del empleador y del trabajador, filas, firma por fila y anuladas excluidas.
- Avisos: una sola vez, agrupados y a los destinatarios correctos.
- Alcance por sector.
- Extras por empleado y firma en papel (archivo obligatorio, con motivo y hash).
- Sync: idempotencia por uuid y cálculo offline coherente con el servidor.
- Aislamiento entre empresas.

## 13. Decisiones del usuario
1. **Stock**: sin stock en esta etapa (solo entregas).
2. **Contratistas**: solo personal propio.
3. **Firma**: en pantalla, o planilla en papel firmada y escaneada (foto o PDF) con motivo.
4. **Matriz**: por puesto, más extras por empleado.
