<?php
use App\Core\Cuit;
use App\Models\Incidents;
use App\Services\IncidentService;
$num = Incidents::format((int) $i['number']);
$docTitle = 'Datos para la ART · ' . $num;
$v = fn ($x) => ($x === null || $x === '') ? '<span class="empty">(completar)</span>' : e((string) $x);
$age = $p['birth_date'] ? (int) (new DateTimeImmutable($p['birth_date']))->diff(new DateTimeImmutable(fecha($i['occurred_at'], 'Y-m-d')))->format('%y') : null;
$seniority = $p['hire_date'] ? (new DateTimeImmutable($p['hire_date']))->diff(new DateTimeImmutable(fecha($i['occurred_at'], 'Y-m-d'))) : null;
require __DIR__ . '/_print_head.php';
?>
<div class="page">
    <header><div><h1>Datos para la denuncia del siniestro</h1><div><?= e($num) ?> · <?= e(IncidentService::typeLabel($i['type'])) ?></div></div>
        <div>ART: <?= $v($company['art']) ?></div></header>
    <p class="note">Resumen preparado por el sistema para presentar o cargar en el portal de la ART. Verificá los datos y los plazos con tu ART.</p>
    <h2>Empleador</h2>
    <table>
        <tr><td class="k">Razón social</td><td><?= $v($tenant['legal_name'] ?: $tenant['name']) ?></td></tr>
        <tr><td class="k">CUIT</td><td><?= $v($tenant['cuit'] ? Cuit::format($tenant['cuit']) : null) ?></td></tr>
        <tr><td class="k">Domicilio del establecimiento</td><td><?= $v($company['domicilio']) ?></td></tr>
        <tr><td class="k">Actividad (CIIU)</td><td><?= $v($company['actividad']) ?><?= $company['ciiu'] ? ' (' . e($company['ciiu']) . ')' : '' ?></td></tr>
        <tr><td class="k">ART · N° de contrato</td><td><?= $v($company['art']) ?> · <?= $v($company['art_contrato']) ?></td></tr>
    </table>
    <h2>Trabajador</h2>
    <table>
        <tr><td class="k">Apellido y nombre</td><td><?= $v($p['employee_id'] ? $p['last_name'] . ', ' . $p['first_name'] : $p['external_name']) ?></td></tr>
        <tr><td class="k">DNI · CUIL</td><td><?= $v($p['employee_dni'] ?? $p['external_dni']) ?> · <?= $v($p['cuil'] ? Cuit::format($p['cuil']) : null) ?></td></tr>
        <tr><td class="k">Fecha de nacimiento · edad</td><td><?= $v($p['birth_date'] ? date('d/m/Y', strtotime($p['birth_date'])) : null) ?><?= $age !== null ? ' · ' . e($age) . ' años' : '' ?></td></tr>
        <tr><td class="k">Sexo</td><td><?= $v(['F' => 'Femenino', 'M' => 'Masculino', 'X' => 'No binario / otro'][$p['gender'] ?? ''] ?? null) ?></td></tr>
        <tr><td class="k">Domicilio</td><td><?= $v($p['address']) ?></td></tr>
        <tr><td class="k">Puesto · legajo</td><td><?= $v($p['position_name']) ?> · <?= $v($p['file_number']) ?></td></tr>
        <tr><td class="k">Fecha de ingreso · antigüedad</td><td><?= $v($p['hire_date'] ? date('d/m/Y', strtotime($p['hire_date'])) : null) ?><?= $seniority ? ' · ' . e($seniority->y) . ' años y ' . e($seniority->m) . ' meses' : '' ?></td></tr>
        <?php if ($p['contractor_name']): ?><tr><td class="k">Empleador directo (contratista)</td><td><?= e($p['contractor_name']) ?></td></tr><?php endif; ?>
    </table>
    <h2>Siniestro</h2>
    <table>
        <tr><td class="k">Fecha y hora</td><td><?= e(fecha($i['occurred_at'], 'd/m/Y H:i')) ?></td></tr>
        <tr><td class="k">Tipo</td><td><?= e(IncidentService::typeLabel($i['type'])) ?><?= $i['type'] === 'in_itinere' ? ' (in itinere)' : '' ?></td></tr>
        <tr><td class="k">Lugar</td><td><?= $v(implode(' · ', array_filter([$i['site_name'], $i['sector_name'], $i['location_text']]))) ?></td></tr>
        <tr><td class="k">Forma de ocurrencia</td><td><?= $v($p['accident_form_name']) ?></td></tr>
        <tr><td class="k">Agente material</td><td><?= $v($p['injury_agent'] ?: ($i['equipment_code'] ? $i['equipment_code'] . ' · ' . $i['equipment_name'] : null)) ?></td></tr>
        <tr><td class="k">Naturaleza de la lesión</td><td><?= $v($p['injury_type_name']) ?></td></tr>
        <tr><td class="k">Zona del cuerpo</td><td><?= $v($p['body_part_name']) ?></td></tr>
        <tr><td class="k">Descripción</td><td style="white-space: pre-wrap"><?= e($i['description']) ?><?= $p['injury_description'] ? "\nLesión: " . e($p['injury_description']) : '' ?></td></tr>
        <tr><td class="k">Atención recibida</td><td><?= $v(IncidentService::ATTENTION[$p['medical_attention']] ?? null) ?></td></tr>
        <tr><td class="k">Baja</td><td><?= (int) $p['lost_time'] ? 'Sí, desde ' . e(date('d/m/Y', strtotime($p['leave_start']))) . ' · ' . e($lost['days']) . ' día(s)' . ($lost['provisional'] ? ' (sigue de baja)' : '') : 'No' ?></td></tr>
        <tr><td class="k">Testigos</td><td><?= $witnesses ? e(implode('; ', array_map(fn ($w) => $w['employee_id'] ? $w['last_name'] . ', ' . $w['first_name'] : $w['external_name'], $witnesses))) : $v(null) ?></td></tr>
        <tr><td class="k">N° de siniestro (ART)</td><td><?= $v($p['art_case_number']) ?></td></tr>
    </table>
    <div class="sign"><div>Firma y aclaración del empleador</div><div>Firma del trabajador</div></div>
    <p class="hash">Contiene datos de salud (Ley 25.326): uso exclusivo para la gestión del siniestro. Impreso <?= e(fecha(gmdate('Y-m-d H:i:s'), 'd/m/Y H:i')) ?></p>
</div>
</body>
</html>
