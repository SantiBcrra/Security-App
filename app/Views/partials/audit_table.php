<?php
/** @var list<array> $events  filas de audit_log o platform_audit_log */
$actorOf = fn (array $ev) => $ev['admin_name'] ?? $ev['actor_name'] ?? ($ev['actor_type'] ?? '') ?: 'sistema';
$summary = function (?string $json): string {
    $data = $json ? json_decode($json, true) : null;
    if (!is_array($data)) {
        return '';
    }
    $parts = [];
    foreach ($data as $k => $v) {
        $parts[] = $k . ': ' . (is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE));
    }
    return mb_strimwidth(implode(' · ', $parts), 0, 140, '…');
};
?>
<?php if (!$events): ?>
    <p class="text-body-secondary small mb-0 p-3">Sin eventos todavía.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm mb-0 small align-middle">
            <thead><tr><th>Fecha</th><th>Quién</th><th>Acción</th><th>Detalle</th></tr></thead>
            <tbody>
            <?php foreach ($events as $ev): ?>
                <tr>
                    <td class="text-nowrap"><?= e(fecha($ev['created_at'], 'd/m/Y H:i')) ?></td>
                    <td><?= e($actorOf($ev)) ?></td>
                    <td><code><?= e($ev['action']) ?></code></td>
                    <td class="text-body-secondary">
                        <?php if ($ev['before_data']): ?><div><span class="badge text-bg-light">antes</span> <?= e($summary($ev['before_data'])) ?></div><?php endif; ?>
                        <?php if ($ev['after_data']): ?><div><span class="badge text-bg-light">después</span> <?= e($summary($ev['after_data'])) ?></div><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
