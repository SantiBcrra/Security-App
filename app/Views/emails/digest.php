<?php /** Resumen diario/semanal. */ ?>
<!doctype html>
<html lang="es">
<body style="margin:0;padding:24px;background:#f1f3f5;font-family:Arial,Helvetica,sans-serif;color:#212529">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden">
    <tr><td style="background:#212529;color:#fff;padding:14px 20px;font-size:13px"><?= e(config('app.name')) ?> · <?= e($tenant['name']) ?></td></tr>
    <tr><td style="padding:20px">
        <h1 style="margin:0 0 14px;font-size:19px"><?= $kind === 'daily' ? 'Resumen diario' : 'Resumen semanal' ?> de seguridad</h1>
        <table role="presentation" width="100%" cellpadding="8" style="border-collapse:collapse;font-size:14px;margin-bottom:16px">
            <tr><td style="border:1px solid #e9ecef"><strong style="font-size:20px"><?= e($new) ?></strong><br>nuevas <?= $kind === 'daily' ? 'en las últimas 24 h' : 'en la semana' ?></td>
                <td style="border:1px solid #e9ecef"><strong style="font-size:20px"><?= e($pending) ?></strong><br>pendientes</td>
                <td style="border:1px solid #e9ecef;<?= $imminent ? 'color:#dc3545' : '' ?>"><strong style="font-size:20px"><?= e($imminent) ?></strong><br>riesgo inminente sin cerrar</td>
                <td style="border:1px solid #e9ecef;<?= $overdue ? 'color:#dc3545' : '' ?>"><strong style="font-size:20px"><?= e(count($overdue)) ?></strong><br>acciones vencidas</td></tr>
        </table>
        <?php if ($overdue): ?>
            <h2 style="font-size:15px;margin:0 0 6px">Acciones vencidas</h2>
            <ul style="font-size:13px;padding-left:18px;margin:0 0 16px">
                <?php foreach ($overdue as $o): ?>
                    <li><a href="<?= e(absolute_url('/panel/observaciones/' . $o['uuid'])) ?>"><?= e(App\Models\Observations::format((int) $o['number'])) ?></a>
                        · <?= e($o['assigned_name'] ?? '—') ?> · venció <?= e(date('d/m/Y', strtotime($o['action_due_on']))) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if ($latest): ?>
            <h2 style="font-size:15px;margin:0 0 6px">Nuevas</h2>
            <ul style="font-size:13px;padding-left:18px;margin:0 0 16px">
                <?php foreach ($latest as $o): ?>
                    <li><a href="<?= e(absolute_url('/panel/observaciones/' . $o['uuid'])) ?>"><?= e(App\Models\Observations::format((int) $o['number'])) ?></a>
                        <?= $o['imminent_risk'] ? '<strong style="color:#dc3545">RIESGO INMINENTE</strong>' : '' ?>
                        · <?= e($o['severity_name']) ?> · <?= e($o['sector_name']) ?> · <?= e(mb_strimwidth($o['description'], 0, 90, '…')) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <a href="<?= e(absolute_url('/panel/observaciones?estado=pendientes')) ?>" style="display:inline-block;background:#0d6efd;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;font-size:14px">Ver pendientes</a>
    </td></tr>
</table>
</td></tr></table>
</body>
</html>
