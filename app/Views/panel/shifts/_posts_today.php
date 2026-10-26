<?php
/** Turnos → Hoy: puestos (franjas a cubrir del día, quién está ahora, cobertura). @var array $posts (PostCoverage::day) */
use App\Services\PostCoverage;
$dur = fn (int $sec) => intdiv($sec, 3600) . ' h ' . str_pad((string) intdiv($sec % 3600, 60), 2, '0', STR_PAD_LEFT) . ' min';
?>
<div class="card shadow-sm mb-3">
    <div class="card-header fw-semibold">Puestos (los cubre un grupo de guardias)</div>
    <div class="list-group list-group-flush">
        <?php foreach ($posts as $c):
            [$label, $color] = PostCoverage::STATUS[$c['status']] ?? [$c['status'], 'light'];
            $unjust = array_filter($c['gaps'], fn ($g) => !$g['justified']);
            $duty = $c['on_duty']; ?>
            <a class="list-group-item list-group-item-action" href="<?= e(url('/panel/rondas/turnos/puesto/' . $c['uuid'])) ?>">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <div>
                        <strong><?= e($c['shift_name'] ?: $c['site_name']) ?></strong> <span class="text-body-secondary small">· <?= e($c['site_name']) ?></span>
                        <span class="badge text-bg-<?= e($color) ?>"><?= e($label) ?></span>
                    </div>
                    <div class="small">
                        <?php if ($duty): ?>
                            <span class="badge text-bg-success">De turno</span> <strong><?= e($duty['user_name']) ?></strong> desde las <?= e(fecha($duty['started_at'], 'H:i')) ?>
                        <?php elseif (strtotime($c['start_at'] . ' UTC') <= time() && strtotime($c['end_at'] . ' UTC') > time()): ?>
                            <span class="badge text-bg-danger">Sin guardia ahora</span>
                        <?php endif; ?>
                        <?php if ($c['percent'] !== null): ?> · cubierto <?= (int) $c['percent'] ?> %<?php endif; ?>
                        <?php if ($unjust): ?> · <span class="text-danger"><?= count($unjust) ?> hueco(s) sin guardia</span><?php endif; ?>
                    </div>
                </div>
                <?= App\Core\View::render('panel/shifts/_coverage_bar', ['c' => $c], null) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="card-footer small text-body-secondary">Verde: con guardia · rojo: sin guardia · gris: dejado sin persona con motivo. Tocá un puesto para ver quién lo cubrió y los relevos.</div>
</div>
