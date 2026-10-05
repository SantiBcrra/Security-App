<h1 class="h4 mb-3">Tareas programadas</h1>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm mb-3">
            <div class="card-header"><strong>Cron por URL</strong></div>
            <div class="card-body small">
                <p>Envía la cola de notificaciones, escala alertas sin confirmar, avisa acciones vencidas y manda los resúmenes. Programalo cada 1 a 5 minutos:</p>
                <input class="form-control form-control-sm font-monospace mb-2" readonly value="<?= e($cronUrl) ?>" onfocus="this.select()">
                <ul class="mb-2">
                    <li><strong>cPanel → Cron Jobs</strong> (cada minuto): <code>curl -s "<?= e($cronUrl) ?>" &gt;/dev/null</code></li>
                    <li>O un servicio gratuito como <strong>cron-job.org</strong> apuntando a esa URL.</li>
                </ul>
                <p class="mb-2">Respaldo: si el servidor usa PHP-FPM, cada 5 minutos se procesa un lote corto después de responder una página (lazy cron).</p>
                <p class="mb-2">Última corrida: <strong><?= $lastRun ? e(fecha($lastRun, 'd/m/Y H:i:s')) : 'nunca' ?></strong></p>
                <?php if ($summary): ?><pre class="bg-body-tertiary p-2 rounded small mb-2"><?= e(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre><?php endif; ?>
                <form method="post" action="<?= e(url('/admin/tareas/ejecutar')) ?>"><?= csrf_field() ?><button class="btn btn-primary btn-sm">Ejecutar ahora</button></form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between"><strong>Emails guardados (transporte "archivo")</strong>
                <span class="small"><?= $transport === 'smtp' ? 'Hoy se envía por SMTP' : 'Activo' ?></span></div>
            <?php if (!$mails): ?>
                <p class="small text-body-secondary p-3 mb-0">Todavía no hay emails guardados.</p>
            <?php else: ?>
                <div class="list-group list-group-flush small" style="max-height: 260px; overflow:auto">
                    <?php foreach ($mails as $m): ?><a class="list-group-item list-group-item-action font-monospace" href="?mail=<?= e(rawurlencode($m)) ?>"><?= e($m) ?></a><?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($mail): ?>
            <div class="card shadow-sm mt-3">
                <div class="card-header small">
                    <div><strong>Para:</strong> <?= e($mail['headers']['To'] ?? '') ?></div>
                    <div><strong>Asunto:</strong> <?= e($mail['headers']['Subject'] ?? '') ?></div>
                </div>
                <div class="card-body small" style="white-space: pre-wrap"><?= e($mail['text']) ?></div>
                <?php if ($mail['html']): ?><div class="card-footer small text-body-secondary">También tiene versión HTML (la que ven los clientes de correo).</div><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
