<?php /** @var array $m mensaje (title, body, url, critical) @var array $user @var string $link */ ?>
<!doctype html>
<html lang="es">
<body style="margin:0;padding:24px;background:#f1f3f5;font-family:Arial,Helvetica,sans-serif;color:#212529">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;overflow:hidden">
    <tr><td style="background:<?= $m['critical'] ? '#dc3545' : '#212529' ?>;color:#ffffff;padding:14px 20px;font-size:13px">
        <?= e(config('app.name')) ?><?= ($t = App\Core\Tenant::current()) ? ' · ' . e($t['name']) : '' ?></td></tr>
    <tr><td style="padding:20px">
        <p style="margin:0 0 6px;font-size:13px;color:#6c757d">Hola, <?= e(explode(' ', $user['name'])[0]) ?></p>
        <h1 style="margin:0 0 12px;font-size:19px;<?= $m['critical'] ? 'color:#dc3545' : '' ?>"><?= e($m['title']) ?></h1>
        <p style="margin:0 0 18px;font-size:14px;line-height:1.5;white-space:pre-line"><?= e($m['body']) ?></p>
        <a href="<?= e($link) ?>" style="display:inline-block;background:<?= $m['critical'] ? '#dc3545' : '#0d6efd' ?>;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:6px;font-size:14px">
            <?= $m['critical'] ? 'Ver y confirmar "Recibido"' : 'Ver en el sistema' ?></a>
    </td></tr>
    <tr><td style="padding:12px 20px;font-size:11px;color:#868e96;border-top:1px solid #e9ecef">
        Aviso automático. Podés elegir qué avisos no críticos recibir en "Mi perfil".</td></tr>
</table>
</td></tr></table>
</body>
</html>
