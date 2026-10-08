<?php
use App\Models\Incidents;
use App\Services\IncidentService;
$tenant = App\Core\Tenant::current();
$num = Incidents::format((int) $i['number']);
$docTitle = $num . ' · ' . $tenant['name'];
$personName = fn (array $p) => $p['employee_id'] ? $p['last_name'] . ', ' . $p['first_name'] : (string) $p['external_name'];
require __DIR__ . '/_print_head.php';
?>
<div class="page">
    <header><div><h1>Informe de <?= e(mb_strtolower(IncidentService::typeLabel($i['type']))) ?></h1><div><?= e($tenant['name']) ?> · <?= e($num) ?></div></div>
        <div><?= e(IncidentService::STATES[$i['status']]['label']) ?></div></header>
    <table>
        <tr><td class="k">Fecha y hora del hecho</td><td><?= e(fecha($i['occurred_at'], 'd/m/Y H:i')) ?></td></tr>
        <tr><td class="k">Sector</td><td><?= e($i['sector_name'] ?? '—') ?><?= $i['location_text'] ? ' · ' . e($i['location_text']) : '' ?></td></tr>
        <?php if ($i['equipment_code']): ?><tr><td class="k">Equipo</td><td><?= e($i['equipment_code'] . ' · ' . $i['equipment_name']) ?></td></tr><?php endif; ?>
        <tr><td class="k">Gravedad potencial</td><td><?= e($i['severity_name'] ?? '—') ?></td></tr>
        <tr><td class="k">Reportado por</td><td><?= e($i['reporter_name'] ?? '—') ?> · <?= e(fecha($i['received_at'], 'd/m/Y H:i')) ?></td></tr>
    </table>
    <h2>Descripción (reporte original)</h2>
    <p style="white-space: pre-wrap"><?= e($original['descripcion'] ?? $i['description']) ?></p>
    <?php if (!empty($original['acciones_inmediatas'])): ?><p><strong>Qué se hizo en el momento:</strong> <?= e($original['acciones_inmediatas']) ?></p><?php endif; ?>
    <h2>Personas</h2>
    <table>
        <?php foreach ($people as $p): $lost = IncidentService::lostDays($p, $today); ?>
            <tr><td class="k"><?= e(IncidentService::ROLES[$p['role']]) ?></td><td><strong><?= e($personName($p)) ?></strong>
                <?= $p['employee_id'] ? ' · DNI ' . e($p['employee_dni']) . ($p['position_name'] ? ' · ' . e($p['position_name']) : '') : '' ?>
                <?php if ($health && $p['role'] === 'lesionado'): ?>
                    <br>Lesión: <?= e(implode(' · ', array_filter([$p['injury_type_name'], $p['body_part_name'], $p['injury_description']])) ?: '—') ?>
                    <br>Días perdidos: <?= e($lost['days']) ?><?= $lost['provisional'] ? ' (provisorios: sigue de baja)' : '' ?>
                <?php endif; ?>
                <?php if ($p['statement']): ?><br><em><?= e($p['statement']) ?></em><?php endif; ?></td></tr>
        <?php endforeach; ?>
    </table>
    <?php if (!$health): ?><p class="note">Los datos de salud de los lesionados no se incluyen (reservados a Seguridad e Higiene).</p><?php endif; ?>
    <?php if ($investigation): ?>
        <h2>Investigación<?= $investigation['completed_at'] ? ' (terminada ' . e(fecha($investigation['completed_at'], 'd/m/Y')) . ')' : ' (en curso)' ?></h2>
        <?php if ($investigation['team']): ?><p><strong>Equipo:</strong> <?= e(implode(', ', array_filter(array_map(fn ($id) => App\Models\Users::findById((int) $id)['name'] ?? null, $investigation['team'])))) ?></p><?php endif; ?>
        <?php if (array_filter($investigation['five_whys']['whys'])): ?>
            <p><strong>5 porqués</strong><?= $investigation['five_whys']['problem'] ? ' — ' . e($investigation['five_whys']['problem']) : '' ?></p>
            <ol><?php foreach ($investigation['five_whys']['whys'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ol>
        <?php endif; ?>
        <?php if ($tree): ?>
            <p><strong>Árbol de causas</strong></p>
            <?php foreach ($tree as $n): ?><div style="padding-left: <?= (int) $n['depth'] * 7 ?>mm"><?= $n['depth'] ? '↳ ' : '' ?><em><?= e(App\Services\IncidentInvestigation::NODE_TYPES[$n['type']]) ?>:</em> <?= e($n['text']) ?></div><?php endforeach; ?>
        <?php endif; ?>
        <?php if ($investigation['root_causes']): ?><p><strong>Causas raíz:</strong> <?= e(implode(', ', array_map(fn ($id) => App\Models\CatalogItems::findById((int) $id)['name'] ?? '?', $investigation['root_causes']))) ?></p><?php endif; ?>
        <?php if ($investigation['conclusions']): ?><p style="white-space: pre-wrap"><strong>Conclusiones:</strong> <?= e($investigation['conclusions']) ?></p><?php endif; ?>
        <?php if ($investigation['lessons']): ?><p style="white-space: pre-wrap"><strong>Lecciones aprendidas:</strong> <?= e($investigation['lessons']) ?></p><?php endif; ?>
        <?php if ($derived): ?>
            <p><strong>Acciones derivadas</strong></p>
            <table><?php foreach ($derived as $a): ?><tr><td class="k"><?= e(App\Models\Actions::format((int) $a['number'])) ?></td>
                <td><?= e($a['title']) ?> · <?= e($a['responsible_name']) ?> · límite <?= e(date('d/m/Y', strtotime($a['due_on']))) ?> · <?= e(App\Services\ActionWorkflow::label($a['status'])) ?></td></tr><?php endforeach; ?></table>
        <?php endif; ?>
    <?php endif; ?>
    <h2>Línea de tiempo</h2>
    <table>
        <?php foreach ($events as $ev): ?>
            <tr><td class="k"><?= e(fecha($ev['created_at'], 'd/m/Y H:i')) ?> · <?= e($ev['actor_name']) ?></td>
                <td><?= e($ev['type'] === 'status' ? IncidentService::STATES[$ev['to_status']]['label'] ?? $ev['to_status'] : ucfirst(str_replace('_', ' ', $ev['type']))) ?><?= $ev['comment'] ? ': ' . e($ev['comment']) : '' ?></td></tr>
        <?php endforeach; ?>
    </table>
    <div class="sign"><div>Responsable de Seguridad e Higiene</div><div>Supervisor del sector</div></div>
    <p class="hash">Integridad del reporte original (SHA-256): <?= e($i['original_hash']) ?> · Impreso <?= e(fecha(gmdate('Y-m-d H:i:s'), 'd/m/Y H:i')) ?></p>
</div>
</body>
</html>
