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
