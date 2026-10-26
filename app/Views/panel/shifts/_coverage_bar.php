<?php
/**
 * Barra de tiempo de una franja de puesto: tramos de cada guardia (verde), huecos justificados (gris rayado, "sin persona")
 * y sin justificar (rojo); lo que todavía no pasó queda vacío. @var array $c (PostCoverage::detail)
 */
$start = strtotime($c['start_at'] . ' UTC');
$end = strtotime($c['end_at'] . ' UTC');
$total = max(1, $end - $start);
$pct = fn (int $a, int $b) => max(0, min(100, 100 * ($b - $a) / $total));
$left = fn (int $a) => max(0, min(100, 100 * ($a - $start) / $total));
$now = time();
?>
<div class="position-relative rounded bg-body-secondary" style="height: 22px; overflow: hidden" aria-hidden="true">
    <?php foreach ($c['segments'] as $s):
        $a = max($start, strtotime($s['started_at'] . ' UTC'));
        $b = min($end, $s['ended_at'] ? strtotime($s['ended_at'] . ' UTC') : min($now, $end)); ?>
        <div class="position-absolute top-0 h-100 bg-success border-end border-light" title="<?= e($s['user_name'] . ' · ' . fecha($s['started_at'], 'd/m H:i') . ' a ' . ($s['ended_at'] ? fecha($s['ended_at'], 'd/m H:i') : 'ahora')) ?>"
             style="left: <?= $left($a) ?>%; width: <?= $pct($a, $b) ?>%"></div>
    <?php endforeach; ?>
    <?php foreach ($c['gaps'] as $g):
        $a = strtotime($g['from'] . ' UTC');
        $b = strtotime($g['to'] . ' UTC'); ?>
        <div class="position-absolute top-0 h-100 <?= $g['justified'] ? 'bg-secondary' : 'bg-danger' ?>" style="left: <?= $left($a) ?>%; width: <?= $pct($a, $b) ?>%;<?= $g['justified'] ? ' opacity:.5' : '' ?>"
             title="<?= e(($g['justified'] ? 'Sin persona (' . $g['reason'] . ')' : 'Sin guardia') . ' · ' . fecha($g['from'], 'd/m H:i') . ' a ' . fecha($g['to'], 'd/m H:i')) ?>"></div>
    <?php endforeach; ?>
    <?php if ($now > $start && $now < $end): ?><div class="position-absolute top-0 h-100 bg-dark" style="left: <?= $left($now) ?>%; width: 2px" title="Ahora"></div><?php endif; ?>
</div>
<div class="d-flex justify-content-between small text-body-secondary mt-1"><span><?= e(fecha($c['start_at'], 'd/m H:i')) ?></span><span><?= e(fecha($c['end_at'], 'd/m H:i')) ?></span></div>
