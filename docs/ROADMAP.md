# Roadmap — Etapas 9 a 19

Texto del plan original, **ajustado a las decisiones ya tomadas** (ver `AGENTS.md`):
- No hay librerías: donde el plan decía dompdf o PhpSpreadsheet, se usa una página imprimible o
  un CSV/XLSX propio.
- Hay una base por empresa.
- La app de campo es la PWA `/movil/`.

Las notas marcadas **"Nota"** son las aclaraciones de implementación.

**Estado (2026-10-09):** las Etapas 9 a 13 están hechas y la 14 tiene la entrega 1 hecha. Checklist al día en
`docs/ESTADO.md`. Lo que sigue: 14 (entregas 2 y 3) → 15 → 16 → 19 → 17 → 18. Las Etapas 17 a 19 dependen de
que exista producción, y el deploy está pendiente. Las secciones de etapas ya hechas quedan como referencia histórica:
lo implementado de verdad está en `CLAUDE.md` y `docs/design/`.

## ETAPA 9 — Rondas de guardias (adelantada)
- Puntos de control con QR único, planta/sector, coordenadas GPS, radio permitido y punto crítico.
- Rutas configurables y registro de rondas por guardia.
- La PWA inicia rondas, escanea QR y guarda hora, GPS, precisión, distancia y cumplimiento del radio.
- Modo offline con sincronización idempotente (`round.start`, `round.scan`, `round.finish`).
- Panel con alta de puntos, impresión de QR e historial de rondas.
- Las incidencias de una ronda quedan preparadas para vincularse a CAPA.
- Mapa: Leaflet local con OpenStreetMap; Google Maps queda fuera por la regla de no depender de servicios externos.

---

## ETAPA 10 — Acciones correctivas y preventivas (CAPA)
- Una acción puede nacer de cualquier origen (observación, inspección, incidente, auditoría,
  o manual), con columnas `origen_tipo` y `origen_id`.
- Tiene responsable, fecha límite, prioridad y evidencia de cierre obligatoria (texto y/o fotos,
  que son inmutables).
- Verificación de eficacia: un segundo paso, a cargo de otro rol u otro usuario distinto de quien
  cerró, valida el cierre o lo rechaza y lo reabre.
- Recordatorios automáticos por cron, antes y después del vencimiento (con `NotificationMarks`
  para no repetir en el día).
- Tablero de acciones vencidas por sector y por responsable.
- Módulo de permisos: `acciones`, que ya existe.
- **Nota:** hoy la observación en estado `accion_asignada` guarda solo
  `assigned_user_id` / `action_text` / `action_due_on` dentro de la propia observación.
  - La transición "asignar" pasa a crear una acción CAPA vinculada
    (`origen_tipo='observacion'`).
  - Hay que migrar las existentes con una migración `.php`.
  - Hay que decidir y documentar si cerrar la acción cierra la observación o solo la habilita.
- **Nota:** generalizar `Notifier` y `Messages` para eventos de acciones (`action.assigned`,
  `action.due_soon`, `action.overdue`, `action.closed`, `action.verified`, `action.rejected`) sin
  romper los de observaciones. Hoy el evento `observation.overdue` sale de `Digests::overdue()`.
- **Nota:** en la app de campo, el responsable debería ver "Mis acciones" y poder cerrarlas con
  foto. Hay que sumar la entidad a `Sync\Pull` y una operación `action.close` a `Sync\Push`.

## Extensión prevista — Operación integral de guardias
- Puestos de vigilancia, turnos, relevos y asignación de guardias.
- Libro de novedades por turno, con cierre y entrega firmada.
- Incidencias de vigilancia vinculables a una acción CAPA.
- Control de accesos de personas, vehículos y contratistas, si Indumor lo requiere.
- Alertas por punto crítico omitido, ronda fuera de horario o incumplimiento reiterado.

## ETAPA 11 — Inspecciones y checklists
- Constructor de plantillas por empresa, con ítems de tipo:
  - sí/no/N.A.;
  - numéricos (con rango aceptable);
  - foto obligatoria;
  - ítems críticos.
- La estructura de la plantilla se guarda en JSON en una columna `LONGTEXT`, con versiones: una
  inspección hecha queda atada a la versión que usó. Las respuestas van en tablas propias.
- Programación recurrente (diaria, semanal, mensual) generada por el cron, por planta, sector o
  equipo, con un responsable.
- Escanear el QR de un equipo abre su checklist; por ejemplo, el pre-uso del autoelevador.
  - **Nota:** el QR ya existe en `/q/{uuid}`, y la PWA tiene escáner.
  - El checklist tiene que poder completarse **offline** en `/movil/`.
- Un ítem que no cumple genera automáticamente una acción correctiva de la Etapa 10 (CAPA), con
  `origen_tipo='inspeccion'`. Un ítem crítico que no cumple puede disparar un aviso crítico.
- Cumplimiento: inspecciones hechas vs. programadas.
- Plantillas precargadas para metalúrgica: autoelevador, puente grúa, amoladoras, soldadura,
  orden y limpieza, extintores.
  - **Nota:** van en `database/seeds/templates/`, se aplican con `IndustryTemplates` y son
    idempotentes.

## ETAPA 12 — Incidentes, accidentes e investigación
- Tipos: accidentes con o sin baja, incidentes, casi-accidentes y enfermedad profesional.
- Datos del accidentado (empleado propio o de contratista), lesión, parte del cuerpo
  (catálogos), días perdidos y seguimiento: alta médica y reingreso.
- Investigación con 5 porqués y árbol de causas, con acciones derivadas de la Etapa 10 (CAPA)
  (`origen_tipo='incidente'`).
- Datos preparados para la denuncia ante la ART: página imprimible con los campos del formulario.
- Numeración propia con `Sequences`, por ejemplo INC-000001. La evidencia inicial es inmutable,
  igual que en Observaciones.
- **Nota:** los días perdidos y las horas-hombre trabajadas alimentan los índices de la Etapa 16.
  Prever el dato de horas-hombre por mes y por planta, cargado a mano en la configuración.

## ETAPA 13 — Permisos de trabajo
- Tipos: trabajo en altura, en caliente (soldadura y corte), espacio confinado, LOTO y eléctrico.
- Cada permiso tiene:
  - un checklist previo obligatorio, por tipo;
  - firmas digitales (canvas → imagen PNG con hash SHA-256, inmutable);
  - una ventana de validez.
- Estados: solicitado → aprobado → en ejecución → cerrado. También puede quedar rechazado o
  vencido; el vencimiento lo marca el cron automáticamente.
- Vista "permisos activos ahora" por planta.
- **Nota:** las firmas se capturan en el navegador o en la PWA, en un `<canvas>` sin librerías, y
  se guardan con `TenantFiles`.

## ETAPA 14 — EPP
- Catálogo de EPP y matriz por puesto: qué EPP corresponde a cada puesto y cada cuánto se
  repone.
- Entrega con firma del empleado en el celular, usando el mismo componente de firma de la
  Etapa 13.
- Vencimientos y reposiciones, con avisos por cron.
- Constancia en el formato de la Res. SRT 299/11.
  - **Nota:** es una página imprimible con CSS de impresión, sin dompdf.

## ETAPA 15 — Capacitaciones
- Cursos, matriz por puesto, asistencia con firma y vencimientos (vigencia por curso).
- Alertas de capacitaciones vencidas o por vencer, por empleado.
- Constancia o planilla de asistencia imprimible.

## ETAPA 16 — Indicadores, reportes y exportaciones
- Dashboard por empresa con gráficos de Chart.js.
  - **Nota:** copiar el archivo minificado a `public/assets/vendor/`, sin CDN.
- Indicadores:
  - índices de frecuencia y de gravedad;
  - días sin accidentes;
  - observaciones por sector, tipo y turno;
  - acciones abiertas y vencidas;
  - cumplimiento de inspecciones, EPP y capacitaciones.
- Consultas agregadas con índices adecuados, sin window functions ni CTE. Si las consultas se
  vuelven pesadas, usar tablas resumen recalculadas por cron.
- Exportación:
  - a Excel, como CSV o XLSX propio escrito con `ZipArchive`, sin PhpSpreadsheet;
  - a "PDF", como página imprimible.
- Informe mensual por email, con `MailTransport` y una tarea del cron.
- Módulo de permisos: `reportes`, que ya existe.

## ETAPA 17 — Capa comercial SaaS
- Planes con límites (usuarios, plantas, almacenamiento y módulos habilitados), guardados en la
  base maestra y validados en un middleware.
- Alta self-service con período de prueba.
  - **Nota:** con una base por empresa, en el hosting compartido normalmente no se pueden crear
    bases por PHP. Hay que proponer un flujo: alta en espera de provisión → el super-admin crea la
    base en el panel del hosting → la conecta desde `/admin`. También se puede usar el modo
    automático si el usuario MySQL tiene permiso de `CREATE DATABASE`, como ya soporta
    `TenantProvisioner`.
- Facturación con Mercado Pago y/o Stripe, vía API HTTP (`Core\Http`) con webhooks a un endpoint
  PHP (firma verificada e idempotente).
- Marca blanca opcional: logo y colores por empresa.

## ETAPA 18 — Integraciones
- API pública con tokens por empresa (hasheados, con permisos acotados).
- Webhooks salientes por la cola de la Etapa 7 (`notification_queue`, o una cola análoga), con
  firma HMAC y reintentos.
- Conector MetalERP / Indumor, opcional y nunca una dependencia: sincroniza empleados y sectores
  usando el importador existente o la API.
- Conector MCP para consultar el sistema desde Claude.
  - **Nota:** tiene que ser un endpoint HTTP en PHP, sin un proceso Node en el servidor.

## ETAPA 19 — Hardening y operación
- Revisión de seguridad (OWASP):
  - inyección, XSS y CSRF;
  - **IDOR entre empresas**, que aquí significa usar siempre la conexión correcta y no aceptar
    ids internos;
  - subida de archivos: validar el MIME real, renombrar y nunca ejecutar.
- Backups:
  - exportación SQL programada por cron vía PHP, de la maestra y de cada empresa, más una copia
    de `/storage`;
  - una prueba de restauración documentada;
  - exportación completa de los datos de una empresa.
- Página de estado y log de errores visible para el super-admin; alertas de errores por email.
- Cumplimiento de la Ley 25.326 de datos personales: términos, consentimiento, retención y
  borrado. El borrado tiene que ser compatible con la evidencia inmutable, por ejemplo
  anonimizando en vez de borrar.
- Pruebas de carga sobre la sincronización y la subida de archivos en el hosting real
  (`tools/sync-stress.php`).
