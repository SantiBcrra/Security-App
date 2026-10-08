<?php $base = App\Core\App::baseUrl(); ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#212529">
    <meta name="app-base" content="<?= e($base) ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Seguridad">
    <title>Seguridad · Campo</title>
    <link rel="manifest" href="<?= e($base) ?>/movil/manifest.webmanifest">
    <link rel="icon" href="<?= e($base) ?>/assets/movil/icon-192.png">
    <link rel="apple-touch-icon" href="<?= e($base) ?>/assets/movil/apple-touch-icon.png">
    <link rel="stylesheet" href="<?= e($base) ?>/assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= e($base) ?>/assets/movil/movil.css?v=<?= e($version) ?>">
    <script type="module" src="<?= e($base) ?>/assets/movil/app.js?v=<?= e($version) ?>"></script>
    <script defer src="<?= e($base) ?>/assets/js/alpine.min.js"></script>
</head>
<body x-data="movil" x-cloak>

<!-- Cargando -->
<div class="d-flex vh-100 align-items-center justify-content-center" x-show="view === 'loading'">
    <div class="spinner-border text-warning"></div>
</div>

<!-- Ingreso -->
<main class="login-screen" x-show="view === 'login'">
    <div class="text-center mb-4">
        <img src="<?= e($base) ?>/assets/movil/icon-192.png" alt="" width="72" height="72" class="rounded-4 mb-2">
        <h1 class="h4 text-white mb-0">Seguridad · Campo</h1>
        <div class="text-white-50 small">Reportes en planta, con o sin señal</div>
    </div>
    <form class="card shadow" @submit.prevent="doLogin()">
        <div class="card-body p-4">
            <div class="alert alert-danger py-2 small" x-show="login.error" x-text="login.error"></div>
            <label class="form-label">Empresa</label>
            <input class="form-control form-control-lg mb-3" x-model="login.empresa" autocapitalize="none" autocomplete="organization" required placeholder="identificador">
            <label class="form-label">Email o DNI</label>
            <input class="form-control form-control-lg mb-3" x-model="login.usuario" autocapitalize="none" autocomplete="username" required>
            <label class="form-label">Contraseña</label>
            <input class="form-control form-control-lg mb-3" type="password" x-model="login.password" autocomplete="current-password" required>
            <template x-if="login.needTotp">
                <div><label class="form-label">Código de verificación (6 números)</label>
                    <input class="form-control form-control-lg mb-3 text-center" x-model="login.totp" inputmode="numeric" maxlength="7" autocomplete="one-time-code"></div>
            </template>
            <button class="btn btn-warning btn-lg w-100 fw-semibold" :disabled="login.busy">
                <span x-show="login.busy" class="spinner-border spinner-border-sm me-1"></span>Ingresar
            </button>
        </div>
    </form>
</main>

<!-- App -->
<div x-show="view === 'app'" class="app-shell">
    <header class="app-header">
        <div class="d-flex align-items-center justify-content-between">
            <div class="fw-semibold text-truncate" x-text="meta?.empresa?.nombre || 'Seguridad'"></div>
            <button class="sync-pill" @click="syncManual()" :class="'sync-' + sync.state">
                <template x-if="sync.state === 'syncing'"><span class="spinner-border spinner-border-sm"></span></template>
                <span x-text="sync.state === 'offline' ? 'Sin señal' : (sync.state === 'syncing' ? 'Enviando' : (sync.state === 'error' ? 'Reintentando' : 'Al día'))"></span>
                <span class="badge text-bg-warning" x-show="sync.ops + sync.photos > 0" x-text="sync.ops + sync.photos"></span>
            </button>
        </div>
        <div class="small text-white-50 mt-1" x-show="sync.ops + sync.photos > 0">
            <span x-text="sync.ops + ' reporte(s)/acción(es) y ' + sync.photos + ' foto(s) pendientes de enviar'"></span>
        </div>
    </header>

    <!-- Detalle de una acción (CAPA) -->
    <main class="app-main" x-show="actionUuid">
        <button class="btn btn-link px-0 mb-2" @click="closeAction()">← Volver</button>
        <div class="alert alert-secondary small" x-show="action && !action.local && !action.loading">Esta acción todavía no está en el celular. Conectate y sincronizá.</div>
        <template x-if="action && action.local">
            <div>
                <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
                    <h2 class="h5 m-0" x-text="action.local.code"></h2>
                    <span class="badge" :class="action.local.pending ? 'text-bg-warning' : 'text-bg-secondary'" x-text="action.local.pending ? action.local.status_label + ' · sin enviar' : action.local.status_label"></span>
                    <span class="badge text-bg-danger" x-show="action.local.priority === 'critica'">Crítica</span>
                    <span class="badge text-bg-warning" x-show="action.local.priority === 'alta'">Alta</span>
                </div>
                <div class="fw-semibold mb-2" x-text="action.local.title"></div>
                <div class="alert alert-danger small py-2" x-show="action.local.sync_error" x-text="'El servidor lo rechazó: ' + action.local.sync_error"></div>
                <div class="card mb-3"><div class="card-body small">
                    <div :class="actionLate(action.local) ? 'text-danger fw-semibold' : ''" x-text="dueText(action.local)"></div>
                    <div class="text-body-secondary" x-show="action.local.sector_uuid" x-text="sectorLabel(action.local.sector_uuid)"></div>
                    <div class="text-body-secondary">Responsable: <span x-text="action.local.responsible"></span></div>
                    <div class="text-body-secondary" x-show="!action.local.observation_uuid" x-text="'Origen: ' + action.local.origin"></div>
                    <a x-show="action.local.observation_uuid" :href="'#/observacion/' + action.local.observation_uuid" x-text="'Origen: observación ' + action.local.observation_code"></a>
                    <p class="mt-2 mb-0" style="white-space: pre-wrap" x-show="action.local.description" x-text="action.local.description"></p>
                </div></div>

                <div class="card mb-3 border-warning" x-show="action.local.closure_text"><div class="card-body small">
                    <div class="fw-semibold">Cierre</div>
                    <div style="white-space: pre-wrap" x-text="action.local.closure_text"></div>
                    <div class="text-body-secondary mt-1" x-show="action.local.status === 'cerrada'">Falta que Seguridad e Higiene verifique si fue eficaz.</div>
                </div></div>

                <div class="photo-grid mb-3" x-show="actionPhotos.length">
                    <template x-for="p in actionPhotos"><div class="position-relative"><img :src="p.url" alt="Evidencia" :style="p.old ? 'opacity:.5' : ''">
                        <span class="badge text-bg-warning photo-pending" x-show="p.pending">pendiente</span>
                        <span class="badge text-bg-secondary photo-pending" x-show="p.old">intento anterior</span></div></template>
                </div>

                <button class="btn btn-outline-primary w-100 mb-2" x-show="action.local.can_start && action.local.status === 'abierta' && !action.local.pending" @click="startAction()">Tomar: empiezo a trabajar</button>

                <template x-if="action.local.can_close && ['abierta', 'en_curso'].includes(action.local.status)">
                    <div class="card mb-3"><div class="card-body">
                        <div class="fw-semibold mb-2">Cerrar con evidencia</div>
                        <label class="form-label small mb-1">¿Qué se hizo?</label>
                        <textarea class="form-control mb-2" rows="3" x-model="actionForm.text" placeholder="Ej: se colocó la guarda y se probó la máquina"></textarea>
                        <label class="btn btn-outline-secondary w-100 mb-2">📷 Sacar o elegir fotos
                            <input type="file" accept="image/*" capture="environment" multiple hidden @change="addActionPhotos($event)"></label>
                        <div class="photo-grid mb-2" x-show="actionForm.photos.length">
                            <template x-for="(p, i) in actionForm.photos"><div class="position-relative"><img :src="p.url" alt="Foto">
                                <button type="button" class="btn btn-sm btn-danger photo-remove" @click="removeActionPhoto(i)">✕</button></div></template>
                        </div>
                        <div class="small text-body-secondary mb-2" x-show="action.local.evidence > 0 && !actionForm.photos.length" x-text="'Ya hay ' + action.local.evidence + ' archivo(s) de evidencia.'"></div>
                        <button class="btn btn-success w-100" :disabled="actionBusy" @click="submitClose()">Cerrar acción</button>
                        <div class="small text-body-secondary mt-2">Funciona sin señal: se envía cuando vuelva la conexión.</div>
                    </div></div>
                </template>

                <template x-if="action.remote">
                    <div class="card mb-3">
                        <div class="card-header small fw-semibold">Línea de tiempo</div>
                        <ul class="list-group list-group-flush small">
                            <template x-for="ev in [...action.remote.events].reverse()">
                                <li class="list-group-item">
                                    <div class="d-flex justify-content-between"><strong x-text="ev.label"></strong><span class="text-body-secondary" x-text="fmtDate(ev.at)"></span></div>
                                    <div class="text-body-secondary" x-text="ev.actor"></div>
                                    <div x-show="ev.comment" style="white-space: pre-wrap" x-text="ev.comment"></div>
                                </li>
                            </template>
                        </ul>
                    </div>
                </template>
                <div class="text-center small text-body-secondary" x-show="!action.remote && !action.loading">Sin conexión: se muestra lo guardado en el celular.</div>
            </div>
        </template>
    </main>

    <!-- Detalle de una observación -->
    <main class="app-main" x-show="detailUuid && !actionUuid">
        <button class="btn btn-link px-0 mb-2" @click="closeDetail()">← Volver</button>
        <template x-if="detail && detail.local">
            <div>
                <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <h2 class="h5 m-0" x-text="code(detail.local)"></h2>
                    <span class="badge" :class="detail.local.local ? 'text-bg-warning' : 'text-bg-secondary'" x-text="detail.remote?.status_label || detail.local.status_label || 'Pendiente de enviar'"></span>
                    <span class="badge text-bg-danger" x-show="detail.local.imminent">⚠ RIESGO INMINENTE</span>
                </div>
                <div class="alert alert-warning small py-2" x-show="detail.local.local">Guardado en el celular: se envía cuando haya señal.</div>
                <div class="alert alert-danger small py-2" x-show="detail.local.sync_error" x-text="'El servidor lo rechazó: ' + detail.local.sync_error"></div>
                <div class="card mb-3"><div class="card-body">
                    <div class="small text-body-secondary mb-1">
                        <span x-text="catalogName(detail.local.category_uuid)"></span> ·
                        <span class="badge" :style="'background:' + severityColor(detail.local.severity_uuid)" x-text="catalogName(detail.local.severity_uuid)"></span>
                    </div>
                    <p class="mb-2" style="white-space: pre-wrap" x-text="detail.local.description"></p>
                    <div class="small text-body-secondary" x-text="sectorLabel(detail.local.sector_uuid)"></div>
                    <div class="small text-body-secondary" x-text="fmtDate(detail.local.created_at_device)"></div>
                    <template x-if="detail.remote?.assigned">
                        <div class="small mt-2"><strong>Responsable:</strong> <span x-text="detail.remote.assigned"></span>
                            <div x-text="detail.remote.action"></div></div>
                    </template>
                </div></div>
                <div class="photo-grid mb-3" x-show="detailPhotos.length">
                    <template x-for="p in detailPhotos"><div class="position-relative"><img :src="p.url" alt="Foto"><span class="badge text-bg-warning photo-pending" x-show="p.pending">pendiente</span></div></template>
                </div>
                <template x-if="detail.remote && detail.remote.actions.length">
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <template x-for="a in detail.remote.actions.filter(a => a !== 'asignar')">
                            <button class="btn btn-outline-primary" @click="transition(a)" x-text="{analizar:'Tomar en análisis', cerrar:'Cerrar', descartar:'Descartar', reabrir:'Reabrir'}[a]"></button>
                        </template>
                    </div>
                </template>
                <template x-if="detail.remote">
                    <div class="card mb-3">
                        <div class="card-header small fw-semibold">Línea de tiempo</div>
                        <ul class="list-group list-group-flush small">
                            <template x-for="ev in [...detail.remote.events].reverse()">
                                <li class="list-group-item">
                                    <div class="d-flex justify-content-between"><strong x-text="(['status', 'assignment'].includes(ev.type) && ev.to_label) || ({created:'Reporte creado', comment:'Comentario', correction:'Corrección', attachment:'Fotos', imminent_alert:'Alerta de riesgo inminente', escalation:'Alerta escalada', alert_ack:'Alerta confirmada', assignment:'Acción asignada'}[ev.type] || ev.type)"></strong>
                                        <span class="text-body-secondary" x-text="fmtDate(ev.at)"></span></div>
                                    <div class="text-body-secondary" x-text="ev.actor"></div>
                                    <div x-show="ev.comment" x-text="ev.comment"></div>
                                </li>
                            </template>
                        </ul>
                        <form class="card-body d-flex gap-2" @submit.prevent="addComment()">
                            <input class="form-control" x-model="detailComment" placeholder="Agregar un comentario…">
                            <button class="btn btn-outline-primary">Enviar</button>
                        </form>
                    </div>
                </template>
                <div class="text-center small text-body-secondary" x-show="!detail.remote && !detail.local.local && !detail.loading">Sin conexión: se muestra lo guardado en el celular.</div>
            </div>
        </template>
    </main>

    <!-- Rondas -->
    <main class="app-main" x-show="!detailUuid && !actionUuid && tab === 'rondas'">
        <!-- Punto escaneado -->
        <template x-if="roundPointUuid">
            <div>
                <button class="btn btn-link px-0 mb-2" @click="go('rondas')">← Rondas</button>
                <div class="card mb-3"><div class="card-body">
                    <h2 class="h5 mb-1" x-text="roundPoint?.name || 'Punto de ronda'"></h2>
                    <div class="text-body-secondary" x-text="roundPoint?.code"></div>
                    <span class="badge text-bg-danger mt-2" x-show="roundPoint?.critical">Punto crítico</span>
                    <div class="small mt-2" x-show="roundPoint" x-text="'Radio permitido: ' + roundPoint?.radius_m + ' m'"></div>
                </div></div>
                <div class="alert alert-success small" x-show="roundPointDone">✓ Ya registraste este punto en la ronda.</div>
                <div class="alert alert-warning small" x-show="activeRoute && !roundPointInRoute">Este punto no es parte de la ruta <strong x-text="activeRoute?.name"></strong>.</div>
                <div class="alert alert-info small" x-show="!activeRound && canPatrol">No tenés una ronda en curso: al registrar el punto se inicia una ronda libre.</div>
                <button class="btn btn-primary btn-lg w-100 mb-2" @click="scanRoundPoint()" :disabled="!roundPoint || !canPatrol || roundPointDone">📍 Registrar este punto</button>
            </div>
        </template>

        <template x-if="!roundPointUuid">
            <div>
                <!-- Ronda en curso -->
                <template x-if="activeRound">
                    <div class="card mb-3 border-primary"><div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <div class="small text-body-secondary">Ronda en curso</div>
                                <h2 class="h5 m-0" x-text="activeRoute ? activeRoute.name : 'Ronda libre'"></h2>
                            </div>
                            <span class="badge text-bg-primary fs-6" x-show="activeRoute" x-text="roundDoneCount + '/' + roundProgress.length"></span>
                            <span class="badge text-bg-primary fs-6" x-show="!activeRoute" x-text="roundScans.length + ' punto(s)'"></span>
                        </div>
                        <div class="progress mb-3" style="height:8px" x-show="activeRoute">
                            <div class="progress-bar" :style="'width:' + (roundProgress.length ? Math.round(roundDoneCount * 100 / roundProgress.length) : 0) + '%'"></div>
                        </div>
                        <ol class="list-unstyled mb-3" x-show="activeRoute">
                            <template x-for="p in roundProgress" :key="p.uuid">
                                <li class="d-flex align-items-center gap-2 py-1" :class="{ 'fw-bold': p.next, 'text-body-secondary': p.done }">
                                    <span style="width:1.6em" x-text="p.done ? '✓' : (p.next ? '➜' : p.n + '.')"></span>
                                    <a class="text-reset text-decoration-none flex-grow-1" :href="'#/ronda/punto/' + p.uuid" x-text="p.code + ' · ' + p.name"></a>
                                    <span class="badge text-bg-danger" x-show="p.critical && !p.done">crítico</span>
                                </li>
                            </template>
                        </ol>
                        <button class="btn btn-primary btn-lg w-100 mb-2" @click="openScanner()">📷 Escanear QR del punto</button>
                        <button class="btn btn-outline-secondary w-100" @click="finishRound()">Finalizar ronda</button>
                    </div></div>
                </template>

                <!-- Sin ronda: mis rutas -->
                <template x-if="!activeRound">
                    <div>
                        <div class="alert alert-secondary small" x-show="!canPatrol">Tu rol no puede hacer rondas.</div>
                        <h2 class="h6 text-body-secondary">Mis rutas</h2>
                        <div class="small text-body-secondary mb-3" x-show="!patrolRoutes.length">No tenés rutas asignadas.</div>
                        <template x-for="r in patrolRoutes" :key="r.uuid">
                            <div class="obs-card">
                                <div class="d-flex justify-content-between align-items-center gap-2">
                                    <div>
                                        <strong x-text="r.name"></strong>
                                        <div class="small text-body-secondary" x-text="(r.points?.length || 0) + ' puntos' + (r.expected_minutes ? ' · ~' + r.expected_minutes + ' min' : '')"></div>
                                    </div>
                                    <button class="btn btn-primary btn-sm" x-show="canPatrol" @click="startRound(r.uuid)">Iniciar</button>
                                </div>
                            </div>
                        </template>
                        <button class="btn btn-outline-primary w-100 my-3" x-show="canPatrol" @click="startRound()">Iniciar ronda libre</button>
                    </div>
                </template>

                <details class="mt-2">
                    <summary class="small text-body-secondary">Todos los puntos (<span x-text="patrolPoints.length"></span>)</summary>
                    <div class="mt-2">
                        <template x-for="p in patrolPoints" :key="p.uuid">
                            <a class="obs-card" :href="'#/ronda/punto/' + p.uuid"><strong x-text="p.code + ' · ' + p.name"></strong><div class="small text-body-secondary" x-text="'Radio ' + p.radius_m + ' m' + (p.critical ? ' · crítico' : '')"></div></a>
                        </template>
                    </div>
                </details>
            </div>
        </template>
    </main>

    <!-- Reportar -->
    <main class="app-main" x-show="!detailUuid && !actionUuid && tab === 'reportar'">
        <template x-if="!canCreate && meta"><div class="alert alert-secondary">Tu rol no puede cargar reportes.</div></template>
        <template x-if="!meta"><div class="alert alert-info small">Bajando los datos de la empresa… (la primera vez necesita conexión)</div></template>
        <form @submit.prevent="save()" x-show="canCreate">
            <button type="button" class="btn w-100 mb-3 py-3 fw-bold imminent-btn" :class="form.imminent ? 'btn-danger' : 'btn-outline-danger'" @click="toggleImminent()">
                <span x-text="form.imminent ? '⚠ RIESGO INMINENTE (activado)' : '⚠ ¿Riesgo inminente? Tocá acá'"></span>
            </button>
            <div class="alert alert-danger fw-semibold small" x-show="form.imminent">Avisá en persona o por radio al supervisor y frená la tarea.</div>

            <label class="form-label fw-semibold">¿Qué es?</label>
            <div class="choice-grid mb-3">
                <template x-for="c in catalogs.categories">
                    <button type="button" class="btn" :class="form.category === c.uuid ? 'btn-primary' : 'btn-outline-primary'" @click="form.category = c.uuid" x-text="c.name"></button>
                </template>
            </div>

            <label class="form-label fw-semibold">¿Qué viste?</label>
            <textarea class="form-control mb-3" rows="4" x-model="form.description" placeholder="Ej: operario amolando sin protección facial"></textarea>

            <label class="form-label fw-semibold">Severidad</label>
            <div class="choice-grid mb-3">
                <template x-for="s in catalogs.severities">
                    <button type="button" class="btn sev-btn" :class="form.severity === s.uuid ? 'active' : ''" :style="'--sev:' + (s.color || '#6c757d')" @click="form.severity = s.uuid" x-text="s.name"></button>
                </template>
            </div>

            <label class="form-label fw-semibold">Sector</label>
            <input class="form-control mb-1" x-model="form.sectorQuery" @input="form.sector = ''" placeholder="Buscar sector…">
            <div class="list-group mb-3 sector-list" x-show="!form.sector">
                <template x-for="s in filteredSectors"><button type="button" class="list-group-item list-group-item-action small" @click="pickSector(s)" x-text="s.label"></button></template>
            </div>

            <label class="form-label">Equipo <span class="text-body-secondary">(opcional)</span></label>
            <div class="d-flex gap-2 mb-3">
                <select class="form-select" x-model="form.equipment"><option value="">Ninguno</option>
                    <template x-for="eq in equipment"><option :value="eq.uuid" x-text="eq.code + ' · ' + eq.name"></option></template>
                </select>
                <button type="button" class="btn btn-outline-secondary text-nowrap" @click="openScanner()">QR</button>
            </div>

            <label class="form-label">Fotos</label>
            <div class="photo-grid mb-2" x-show="form.photos.length">
                <template x-for="(p, i) in form.photos"><div class="position-relative"><img :src="p.url" alt=""><button type="button" class="btn btn-sm btn-dark photo-remove" @click="removePhoto(i)">✕</button></div></template>
            </div>
            <label class="btn btn-outline-secondary w-100 mb-3" x-show="form.photos.length < 6">
                📷 Sacar / agregar foto <input type="file" accept="image/*" capture="environment" multiple hidden @change="addPhotos($event)">
            </label>

            <label class="form-label">Tipo de riesgo <span class="text-body-secondary">(opcional)</span></label>
            <select class="form-select mb-3" x-model="form.risk_type"><option value="">—</option>
                <template x-for="r in catalogs.risks"><option :value="r.uuid" x-text="r.name"></option></template>
            </select>

            <div class="row g-2 mb-3">
                <div class="col-7"><label class="form-label small">Fecha y hora</label><input class="form-control" type="datetime-local" x-model="form.occurred_at"></div>
                <div class="col-5"><label class="form-label small">Ubicación</label>
                    <div class="small pt-2" :class="form.gps.status === 'ok' ? 'text-success' : 'text-body-secondary'"
                         x-text="form.gps.status === 'ok' ? '✓ GPS ±' + form.gps.acc + ' m' : (form.gps.status || '—')"></div></div>
            </div>
            <input class="form-control mb-3" x-model="form.location_text" placeholder="Lugar exacto (opcional)">

            <div class="form-check mb-3" x-show="meta?.anonimo_habilitado">
                <input class="form-check-input" type="checkbox" id="anon" x-model="form.anonymous">
                <label class="form-check-label" for="anon">Reportar como anónimo</label>
            </div>

            <button class="btn btn-lg w-100 fw-semibold mb-4" :class="form.imminent ? 'btn-danger' : 'btn-warning'" :disabled="saving">
                <span x-show="saving" class="spinner-border spinner-border-sm me-1"></span>
                <span x-text="form.imminent ? 'Enviar RIESGO INMINENTE' : 'Guardar reporte'"></span>
            </button>
        </form>
    </main>

    <!-- Mis reportes -->
    <main class="app-main" x-show="!detailUuid && !actionUuid && tab === 'reportes'">
        <template x-if="myActions.length">
            <div class="mb-4">
                <h2 class="h6 text-body-secondary">Mis acciones <span class="badge text-bg-danger" x-show="myOverdueActions" x-text="myOverdueActions + ' vencida(s)'"></span></h2>
                <template x-for="a in myActions" :key="a.uuid">
                    <a class="obs-card" :href="'#/accion/' + a.uuid" :class="actionLate(a) ? 'obs-imminent' : ''">
                        <div class="d-flex justify-content-between gap-2">
                            <strong x-text="a.code"></strong>
                            <span class="badge" :class="a.pending ? 'text-bg-warning' : (a.status === 'cerrada' ? 'text-bg-secondary' : 'text-bg-primary')"
                                  x-text="a.pending ? 'Sin enviar' : a.status_label"></span>
                        </div>
                        <div class="small text-truncate" x-text="a.title"></div>
                        <div class="small" :class="actionLate(a) ? 'text-danger fw-semibold' : 'text-body-secondary'" x-text="dueText(a)"></div>
                    </a>
                </template>
            </div>
        </template>
        <h2 class="h6 text-body-secondary">Mis reportes</h2>
        <div class="text-body-secondary small mb-3" x-show="!myReports.length">Todavía no cargaste reportes desde este celular.</div>
        <template x-for="o in myReports" :key="o.uuid">
            <a class="obs-card" :href="'#/observacion/' + o.uuid" :class="o.imminent ? 'obs-imminent' : ''">
                <div class="d-flex justify-content-between gap-2">
                    <strong x-text="code(o)"></strong>
                    <span class="badge" :class="o.local ? (o.sync_error ? 'text-bg-danger' : 'text-bg-warning') : 'text-bg-success'"
                          x-text="o.local ? (o.sync_error ? 'Rechazado' : 'Pendiente de enviar') : (o.status_label || 'Enviado')"></span>
                </div>
                <div class="small text-truncate" x-text="o.description"></div>
                <div class="small text-body-secondary"><span x-text="fmtDate(o.created_at_device)"></span> · <span x-text="sectorLabel(o.sector_uuid)"></span></div>
            </a>
        </template>
        <template x-if="otherReports.length">
            <div><h2 class="h6 text-body-secondary mt-4">De mis sectores</h2>
                <template x-for="o in otherReports.slice(0, 50)" :key="o.uuid">
                    <a class="obs-card" :href="'#/observacion/' + o.uuid" :class="o.imminent ? 'obs-imminent' : ''">
                        <div class="d-flex justify-content-between gap-2"><strong x-text="code(o)"></strong><span class="badge text-bg-secondary" x-text="o.status_label"></span></div>
                        <div class="small text-truncate" x-text="o.description"></div>
                        <div class="small text-body-secondary"><span x-text="fmtDate(o.created_at_device)"></span> · <span x-text="sectorLabel(o.sector_uuid)"></span></div>
                    </a>
                </template></div>
        </template>
    </main>

    <!-- Avisos -->
    <main class="app-main" x-show="!detailUuid && !actionUuid && tab === 'avisos'">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="h6 text-body-secondary m-0">Avisos</h2>
            <button class="btn btn-sm btn-link" @click="markAllRead()" x-show="unread">Marcar leídos</button>
        </div>
        <div class="text-body-secondary small" x-show="!notifications.length">Sin avisos.</div>
        <template x-for="n in notifications" :key="n.uuid">
            <div class="obs-card" :class="{ 'obs-imminent': n.alert_uuid && !n.alert_acked, 'fw-semibold': !n.read }">
                <div class="d-flex justify-content-between gap-2"><span x-text="n.title"></span><span class="small text-body-secondary text-nowrap" x-text="fmtDate(n.at)"></span></div>
                <div class="small fw-normal" style="white-space: pre-line" x-text="n.body"></div>
                <div class="d-flex gap-2 mt-2">
                    <button class="btn btn-danger btn-sm" x-show="n.alert_uuid && !n.alert_acked" @click="ack(n)">Recibido</button>
                    <span class="small text-success fw-normal" x-show="n.alert_acked" x-text="'✓ confirmada por ' + n.alert_acked_by"></span>
                    <a class="btn btn-outline-secondary btn-sm" x-show="n.observation_uuid" :href="'#/observacion/' + n.observation_uuid">Ver</a>
                    <a class="btn btn-outline-secondary btn-sm" x-show="n.action_uuid" :href="'#/accion/' + n.action_uuid">Ver acción</a>
                </div>
            </div>
        </template>
    </main>

    <!-- Ajustes -->
    <main class="app-main" x-show="!detailUuid && !actionUuid && tab === 'ajustes'">
        <div class="card mb-3"><div class="card-body small">
            <div class="fw-semibold" x-text="meta?.usuario?.nombre"></div>
            <div class="text-body-secondary" x-text="(meta?.usuario?.rol || '') + ' · ' + (meta?.empresa?.nombre || '')"></div>
        </div></div>
        <div class="card mb-3"><div class="card-body">
            <div class="fw-semibold mb-1">Sincronización</div>
            <div class="small mb-2">
                <div>Pendientes: <strong x-text="sync.ops"></strong> envío(s) y <strong x-text="sync.photos"></strong> foto(s)</div>
                <div>Última: <span x-text="sync.lastSync ? fmtDate(new Date(sync.lastSync).toISOString()) : 'nunca'"></span></div>
                <div class="text-danger" x-show="sync.message" x-text="sync.message"></div>
            </div>
            <button class="btn btn-outline-primary btn-sm" @click="syncManual()">Sincronizar ahora</button>
            <template x-if="failedOps.length">
                <div class="mt-3"><div class="small fw-semibold text-danger">Rechazados por el servidor</div>
                    <template x-for="op in failedOps"><div class="border rounded p-2 small mt-1">
                        <div x-text="op.error"></div>
                        <div class="d-flex gap-2 mt-1"><button class="btn btn-sm btn-outline-secondary" @click="retryOp(op)">Reintentar</button>
                            <button class="btn btn-sm btn-outline-danger" @click="dropOp(op)">Descartar</button></div>
                    </div></template></div>
            </template>
        </div></div>
        <div class="card mb-3"><div class="card-body">
            <div class="fw-semibold mb-1">Alertas en el celular</div>
            <div class="small text-body-secondary mb-2" x-text="{on:'Activadas: te llegan aunque la app esté cerrada.', off:'Desactivadas.', denied:'Bloqueadas en el navegador (habilitalas en la configuración del sitio).', unsupported:'Este navegador no las soporta (en iPhone: instalá la app en la pantalla de inicio).', unknown:''}[pushState]"></div>
            <button class="btn btn-warning btn-sm" x-show="pushState === 'off'" @click="enablePush()">Activar alertas</button>
        </div></div>
        <div class="card mb-3" x-show="installPrompt"><div class="card-body">
            <div class="fw-semibold mb-1">Instalar la app</div>
            <button class="btn btn-outline-primary btn-sm" @click="install()">Agregar a la pantalla de inicio</button>
        </div></div>
        <div class="card mb-3"><div class="card-body">
            <div class="fw-semibold mb-1">Pruebas</div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="simoff" :checked="simOffline" @change="toggleSimOffline()">
                <label class="form-check-label small" for="simoff">Simular sin conexión</label>
            </div>
        </div></div>
        <button class="btn btn-outline-danger w-100 mb-4" @click="logout()">Cerrar sesión</button>
    </main>

    <nav class="tabbar">
        <a :class="{ active: tab === 'reportar' && !detailUuid }" href="#/reportar"><span>➕</span>Reportar</a>
        <a :class="{ active: tab === 'reportes' && !detailUuid }" href="#/reportes"><span>📋</span>Reportes<b class="tab-badge" x-show="myOverdueActions" x-text="myOverdueActions"></b></a>
        <a :class="{ active: tab === 'rondas' && !detailUuid }" href="#/rondas"><span>🚶</span>Rondas</a>
        <a :class="{ active: tab === 'avisos' && !detailUuid }" href="#/avisos"><span>🔔</span>Avisos<b class="tab-badge" x-show="unread" x-text="unread"></b></a>
        <a :class="{ active: tab === 'ajustes' && !detailUuid }" href="#/ajustes"><span>⚙️</span>Ajustes</a>
    </nav>
</div>

<!-- Escáner QR -->
<div class="scanner" x-show="scanner.open">
    <video x-ref="scanVideo" playsinline muted></video>
    <div class="scanner-bar">
        <div class="small mb-2" x-text="scanner.error || 'Apuntá al código QR del equipo'"></div>
        <button class="btn btn-light w-100" @click="closeScanner()">Cerrar</button>
    </div>
</div>

<div class="app-toast" x-show="toast" x-transition x-text="toast"></div>
</body>
</html>
