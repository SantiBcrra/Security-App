<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 m-0">Notificaciones</h1>
    <form method="post" action="<?= e(url('/panel/notificaciones/leer-todas')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary">Marcar todas como leídas</button></form>
</div>
<div class="card shadow-sm">
    <?php if (!$items): ?>
        <p class="text-body-secondary text-center py-5 mb-0">No tenés notificaciones.</p>
    <?php else: ?>
        <ul class="list-group list-group-flush">
            <?php foreach ($items as $n): ?>
                <li class="list-group-item d-flex justify-content-between gap-3 <?= $n['is_critical'] && $n['alert_uuid'] && !$n['alert_acked_at'] ? 'list-group-item-danger' : '' ?> <?= $n['read_at'] ? '' : 'fw-semibold' ?>">
                    <a class="text-decoration-none text-reset flex-grow-1" href="<?= e(url('/panel/notificaciones/' . $n['uuid'])) ?>">
                        <?= e($n['title']) ?>
                        <div class="small fw-normal text-body-secondary" style="white-space: pre-line"><?= e(mb_strimwidth((string) $n['body'], 0, 240, '…')) ?></div>
                    </a>
                    <div class="text-end text-nowrap small">
                        <div class="text-body-secondary"><?= e(fecha($n['created_at'], 'd/m/Y H:i')) ?></div>
                        <?php if ($n['alert_uuid'] && !$n['alert_acked_at']): ?>
                            <form method="post" action="<?= e(url('/panel/alertas/' . $n['alert_uuid'] . '/recibido')) ?>" class="mt-1">
                                <?= csrf_field() ?><button class="btn btn-danger btn-sm">Recibido</button>
                            </form>
                        <?php elseif ($n['alert_uuid']): ?>
                            <div class="text-success">✓ confirmada por <?= e($n['alert_acked_name']) ?></div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
