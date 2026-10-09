# App nativa Android para guardias (Kotlin) · Plan

Estado: **borrador**. Decisión del usuario: app nativa en **Kotlin**, solo Android, para guardias.
Faltan las decisiones de la sección 9.

## 1. Por qué y qué cambia
- **Qué se busca**: la PWA (`/movil/`) sigue para el resto del trabajo de campo. Los guardias pasan a una app nativa
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
  - funciona por SMS de respaldo si no hay datos (si se decide).
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
- **Queda fuera de la v1** (sigue en la PWA o la web): inspecciones, permisos de trabajo, EPP e incidentes completos.

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
| Versiones | `minSdk 26` (Android 8), `targetSdk 35`; Gradle con Kotlin DSL |

## 4. Cómo se evitan los problemas de GPS
1. **Seguimiento solo durante la ronda** (foreground service con notificación): es lo que Android respeta.
2. **El guardia excluye la app del ahorro de batería** (pantalla guiada al primer ingreso, con el atajo del sistema).
3. **Celulares de la empresa con Android Enterprise / MDM** (recomendado): app fija (kiosco), sin
   optimización de batería, que no se puede desinstalar. Mejor Samsung, Motorola o Pixel que Xiaomi o Huawei.
4. **La prueba de la ronda es el escaneo** (QR/NFC + GPS en ese momento), no el recorrido continuo.
5. **El servidor detecta los huecos**: un guardia en ronda sin enviar posición en M minutos → aviso
   "guardia sin señal" al supervisor.

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

## 6. Entregas (cada una con tests del servidor y build de la app)
1. **Base**:
   - proyecto `android/`, login con 2FA, sesión segura;
   - pull de datos maestros y rutas a Room;
   - pantalla de rutas;
   - build debug en el emulador y en un celular real.
2. **Rondas offline**: iniciar, escanear QR (ML Kit), finalizar, outbox con WorkManager y estado de la sincronización.
3. **GPS y seguridad del guardia**:
   - foreground service con posiciones;
   - servidor: `patrol_tracks`, mapa del recorrido y `guard.silent`;
   - botón de pánico de punta a punta.
4. **Novedades y avisos**: observaciones con fotos (subida por partes), FCM en el servidor y en la app, canal crítico y
   "Recibido".
5. **NFC y salida**:
   - NFC (alta de tags y escaneo);
   - guía de permisos y batería;
   - firma de release y publicación (Play Console interna o MDM);
   - manual corto para guardias.

## 7. Distribución
- **Firma**: la app se firma con una clave propia (keystore). **Hay que respaldarla**: sin ella no se pueden publicar
  actualizaciones.
- **Opción A**: Google Play Console (USD 25, un solo pago), pista de prueba interna o app privada para la empresa
  (Managed Google Play). Las actualizaciones son automáticas.
- **Opción B**: APK distribuido por el MDM de la empresa (sin tienda).

## 8. Pruebas
- **Servidor**: tests PHP como siempre (posiciones, pánico, sin señal, FCM con transporte falso, NFC, idempotencia).
- **App**:
  - tests unitarios (sync, outbox, reglas de la ronda);
  - el emulador de Android Studio para pantallas;
  - **un celular Android real** para cámara, NFC, GPS con la pantalla apagada y batería.

## 9. Decisiones a tomar
1. **Celulares**: ¿de la empresa (con MDM / kiosco) o propios de los guardias?
2. **NFC** en los puntos de ronda, además de QR.
3. **Pánico**: solo por datos, o también SMS de respaldo a un número fijo.
4. **Frecuencia del GPS** durante la ronda (60 s por defecto) y minutos para "guardia sin señal" (10).
5. **Publicación**: Google Play (privada / prueba interna) o APK por MDM.
