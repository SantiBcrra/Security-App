# App nativa Android para guardias (Kotlin) · Plan

Estado: **aprobado**. Decisiones del usuario: app nativa en **Kotlin**, solo Android, para guardias, en sus
**celulares personales**; **QR y NFC**; pánico por **datos + SMS de respaldo**; todo lo que no sale por falta de señal
**se guarda y se reenvía solo** al reconectar; **sin Google Play**: la app se descarga e instala desde el sistema y
se actualiza sola desde el sistema.

## 1. Por qué y qué cambia
- **Qué se busca**: los guardias usan exclusivamente una app Android nativa.
  para tener:
  - GPS confiable durante la ronda;
  - lectura de QR y NFC;
  - alertas que no se pierden;
  - botón de pánico.
- **Qué usa del servidor**: la misma API v1 de siempre (login JWT, sync pull/push con `op_id`, subidas por partes).
  El servidor suma poco (sección 5).
- **Dónde vive el código**: en este mismo repo, en `android/`, para que la app y el contrato de la API
  (`docs/api/openapi.yaml`) avancen juntos. El deploy por FTPS excluye `android/`.

## 2. Alcance (primera versión)
- **Ingreso**: empresa + usuario (email o DNI) + contraseña, con 2FA si lo tiene. La sesión queda en el dispositivo
  (tokens cifrados con Android Keystore).
- **Rondas**:
  - rutas asignadas con el orden de los puntos;
  - iniciar, escanear y finalizar;
  - cada escaneo guarda la hora del celular, el GPS, la precisión y la distancia, como hoy;
  - escaneo por **QR** (cámara, ML Kit) y por **NFC** (si se decide);
  - todo funciona sin señal.
- **Seguimiento GPS durante la ronda**:
  - un *foreground service* con la notificación fija "Ronda en curso" arranca al iniciar y se apaga al finalizar;
  - manda una posición cada N segundos (por defecto 60) o cada X metros;
  - las posiciones se acumulan sin señal y se envían en lote.
- **Botón de pánico**:
  - mantener presionado 2 s → vibración y aviso **CRÍTICO** con la última ubicación a supervisores y SyH;
  - **por datos y por SMS** (decidido). Sin conexión, o si el servidor no confirma en 15 s, la app manda un SMS
    (`SmsManager`, sin abrir la app de mensajes) a los números de pánico de la empresa. El SMS lleva: guardia,
    empresa, hora y link a Google Maps con la última posición. Además, el pedido por datos queda en la cola y se envía
    al volver la señal.
  - Los números de pánico se configuran en el panel de la empresa y bajan a la app con la sync.
- **Novedades**: reportar una observación con fotos y riesgo inminente, con la misma validación que la web
  (`ObservationInput`).
- **Avisos**:
  - notificaciones por Firebase Cloud Messaging;
  - un canal de máxima prioridad para las alertas críticas;
  - "Recibido" para escalamientos.
- **Ajustes**:
  - estado de la sincronización y envíos pendientes;
  - permisos ("Ubicación: permitir siempre", ahorro de batería) con una guía paso a paso;
  - cerrar la sesión.
- **Queda fuera de la v1** (sigue en la web): inspecciones, permisos de trabajo, EPP e incidentes completos.

## 3. Tecnología (todo oficial de Google o estándar de la industria)
| Parte | Elección |
|---|---|
| Lenguaje y pantallas | Kotlin + Jetpack Compose (Material 3) |
| Arquitectura | MVVM: ViewModel + repositorios; inyección con Hilt |
| Base local (offline) | Room (SQLite): datos maestros, rutas, rondas, escaneos, posiciones y outbox |
| Sincronización | WorkManager: reintentos con espera creciente, también con la app cerrada |
| Red | Retrofit + OkHttp + kotlinx.serialization; refresh de JWT automático |
| QR | CameraX + ML Kit Barcode Scanning (funciona sin internet) |
| NFC | API NFC de Android (NfcAdapter, lectura del UID del tag) |
| GPS | Fused Location Provider (Google Play Services) en un foreground service tipo `location` |
| Notificaciones | Firebase Cloud Messaging (FCM) |
| Seguridad | Android Keystore / EncryptedSharedPreferences para los tokens; HTTPS obligatorio en producción |
| Actualizaciones | Propias (sin Play): descarga del APK desde el servidor, verificación SHA-256 + `PackageInstaller` |
| SMS de pánico | `SmsManager` con permiso `SEND_SMS` (se puede porque la app no pasa por Google Play) |
| Versiones | `minSdk 26` (Android 8), `targetSdk 35`; Gradle con Kotlin DSL |

## 4. Cómo se evitan los problemas de GPS (celulares personales)
Son los celulares de los guardias (decidido): no hay MDM ni kiosco, así que el peso está en la app y el servidor.
1. **Seguimiento solo durante la ronda** (foreground service con notificación): es lo que Android respeta, y en un
   celular personal es lo correcto. Fuera de la ronda la app **no** sigue al guardia.
2. **Asistente de permisos al primer ingreso, que se vuelve a revisar al iniciar cada ronda**:
   - ubicación "Permitir siempre";
   - notificaciones;
   - cámara;
   - exclusión del ahorro de batería;
   - instrucciones por marca para el "inicio automático" de Xiaomi/Redmi, Huawei, Oppo/Realme, Samsung y Motorola.
   La ronda no arranca sin los permisos imprescindibles.
3. **Consentimiento** (celular personal, Ley 25.326): una pantalla explica qué se registra (ubicación solo durante la
   ronda, escaneos, fotos) y el guardia acepta. La aceptación queda guardada con fecha y versión del texto.
4. **La prueba de la ronda es el escaneo** (QR/NFC + GPS en ese momento), no el recorrido continuo.
5. **El servidor detecta los huecos**: un guardia en ronda sin enviar posición en M minutos (10 por defecto,
   configurable) → aviso "guardia sin señal" al supervisor. Si el celular estaba sin señal, al reconectar llegan las
   posiciones guardadas y el recorrido se completa.
6. **Sin señal no se pierde nada** (decidido): escaneos, posiciones, novedades, fotos y pánico van primero a la base
   local (Room) y a la cola. WorkManager los reintenta con espera creciente y se dispara solo al volver la conexión,
   aunque la app esté cerrada. Cada envío es idempotente (`op_id` / uuid): reintentar nunca duplica.

## 5. Cambios en el servidor (PHP, sin librerías, como siempre)
- **FCM HTTP v1**:
  - envío con una cuenta de servicio de Firebase: JWT RS256 firmado con `openssl_sign` → token OAuth2 → `messages:send`;
  - la clave se guarda cifrada en `platform_settings`;
  - el canal "push" suma destinos `fcm:{token}` junto a `web:` y Expo.
- **Registro del dispositivo**: `POST /api/v1/push/fcm` con el token FCM de cada dispositivo y su versión de app.
- **Posiciones**: tabla `patrol_tracks` (ronda, hora del celular, lat, lng, precisión, batería). La app manda la
  operación `round.track` en lote por la sync (idempotente por uuid). La ruta recorrida se ve en el detalle de la
  ronda (mapa Leaflet, ya incluido).
- **Pánico**:
  - operación `guard.panic` (también `POST` directo para no esperar a la cola);
  - evento CRÍTICO `guard.panic` con ubicación y link al mapa;
  - escalamiento como el riesgo inminente (`Escalations`).
- **Guardia sin señal**: el cron revisa las rondas en curso sin posición en `rondas.minutos_sin_senal` (10) →
  `guard.silent`, una sola vez por hueco.
- **NFC**: campo `nfc_uid` en `patrol_points` (se carga acercando el tag desde la app, con permiso de editar).
  El escaneo guarda `method` = qr | nfc.
- **Versión mínima**: `meta.app_android_min` en el pull. Una app demasiado vieja pide actualizarse.
- **Distribución propia de la app** (sin Google Play):
  - el super-admin sube el APK firmado en `/admin/app-android`: versión, notas y versión mínima obligatoria;
  - se guarda en `storage` con su SHA-256;
  - **descarga** en `/descargas/guardias` (página con QR para escanear desde el celular del guardia). No puede ser
    `/app`, que es una ruta reservada;
  - **actualización**: `GET /api/v1/app/android` devuelve la última versión, el SHA-256 y el link. La app baja el APK,
    verifica el hash y abre el instalador de Android, que solo acepta la actualización si la firma coincide con la
    instalada.
- **Números de pánico**: setting de la empresa `guardias.panico_telefonos` (hasta 3) en Configuración, en `meta` del pull.
- **Consentimiento**: `POST /api/v1/consent` (usuario, dispositivo, versión del texto, fecha); se audita.

## 6. Entregas (cada una con tests del servidor y build de la app)
1. **Base**:
   - proyecto `android/`, login con 2FA, sesión segura y consentimiento;
   - pull de datos maestros y rutas a Room;
   - pantalla de rutas;
   - distribución propia: subida del APK en el admin, página de descarga con QR y actualización automática desde la
     app (así los guardias prueban sin cables);
   - build debug en el emulador y en un celular real.
2. **Rondas offline**: iniciar, escanear QR (ML Kit), finalizar, outbox con WorkManager y estado de la sincronización.
3. **GPS y seguridad del guardia**:
   - asistente de permisos y batería por marca;
   - foreground service con posiciones;
   - servidor: `patrol_tracks`, mapa del recorrido y `guard.silent`;
   - botón de pánico de punta a punta (datos + SMS, números de pánico en Configuración).
4. **Novedades y avisos**: observaciones con fotos (subida por partes), FCM en el servidor y en la app, canal crítico y
   "Recibido".
5. **NFC y salida**:
   - NFC (alta de tags desde la app y escaneo);
   - firma de release con keystore propio (respaldo);
   - versión 1.0 en la página de descarga;
   - manual corto para guardias.

## 7. Distribución (decidido: sin Google Play)
- **Instalación**: el guardia abre la página de descarga del sistema (o escanea su QR), baja el APK y Android le pide
  permitir "Instalar apps desconocidas" para el navegador una sola vez. La página trae la guía con capturas.
- **Actualizaciones**: automáticas desde el sistema (ver sección 5). La app avisa y no deja seguir si la versión es
  menor a la mínima obligatoria.
- **Firma**: la app se firma con una clave propia (keystore). **Hay que respaldarla, igual que `config.local.php`**: sin
  ella ningún celular acepta las actualizaciones y habría que desinstalar y reinstalar en todos.
- **Play Protect** puede mostrar el aviso "app no verificada" al instalar fuera de la tienda. Es normal: se explica
  en la guía.
- **Notificaciones**: FCM funciona sin publicar en Google Play, porque solo necesita Google Play Services en el
  celular. Los celulares **Huawei recientes no tienen Google Play Services**: ahí no llegan las notificaciones con la
  app cerrada. Con la app abierta se consultan los avisos cada minuto, y el pánico por SMS funciona igual.

## 8. Pruebas
- **Servidor**: tests PHP como siempre (posiciones, pánico, sin señal, FCM con transporte falso, NFC, idempotencia).
- **App**:
  - tests unitarios (sync, outbox, reglas de la ronda);
  - el emulador de Android Studio para pantallas;
  - **un celular Android real** para cámara, NFC, GPS con la pantalla apagada y batería.

## 9. Decisiones del usuario
1. **Celulares**: los personales de los guardias (sin MDM).
2. **Puntos de ronda**: QR y NFC (los dos).
3. **Pánico**: por datos y por SMS de respaldo.
4. **Sin señal**: todo se guarda y se reenvía solo al reconectar. Posición cada 60 s durante la ronda y "guardia sin
   señal" a los 10 min: valores por defecto, configurables por empresa.
5. **Publicación**: sin Google Play. Descarga e instalación desde el sistema, con actualización automática propia.
