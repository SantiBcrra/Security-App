<?php /** Super-admins de la plataforma. @var array $admins @var array $me */ ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <h1 class="h4 m-0">Super-admins</h1>
    <a class="btn btn-primary btn-sm" href="<?= e(url('/admin/administradores/nuevo')) ?>">+ Nuevo super-admin</a>
</div>
<p class="small text-body-secondary">Administran la plataforma: empresas, base de datos, configuración y la app Android. No son usuarios de ninguna
    empresa. Conviene tener al menos dos, por si uno pierde la contraseña.</p>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover mb-0 align-middle">
    <thead><tr><th>Nombre</th><th>Email</th><th>Estado</th><th>Último ingreso</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($admins as $a): $isMe = (int) $a['id'] === (int) $me['id']; ?>
        <tr class="<?= (int) $a['is_active'] === 1 ? '' : 'text-body-secondary' ?>">
            <td><a class="fw-semibold text-decoration-none" href="<?= e(url('/admin/administradores/' . $a['uuid'])) ?>"><?= e($a['name']) ?></a>
                <?php if ($isMe): ?><span class="badge text-bg-light">vos</span><?php endif; ?></td>
            <td class="small"><?= e($a['email']) ?></td>
            <td><?= (int) $a['is_active'] === 1 ? '<span class="badge text-bg-success">Activo</span>' : '<span class="badge text-bg-secondary">Desactivado</span>' ?></td>
            <td class="small"><?= $a['last_login_at'] ? e(fecha($a['last_login_at'], 'd/m/Y H:i')) : '—' ?></td>
            <td class="text-end text-nowrap">
                <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/admin/administradores/' . $a['uuid'])) ?>">Editar</a>
                <?php if (!$isMe): ?>
                    <form class="d-inline" method="post" action="<?= e(url('/admin/administradores/' . $a['uuid'] . '/estado')) ?>"><?= csrf_field() ?>
                        <button class="btn btn-outline-secondary btn-sm"><?= (int) $a['is_active'] === 1 ? 'Desactivar' : 'Activar' ?></button></form>
                    <form class="d-inline" method="post" action="<?= e(url('/admin/administradores/' . $a['uuid'] . '/eliminar')) ?>"
                          onsubmit="return confirm('¿Eliminar a <?= e(addslashes($a['name'])) ?>? Ya no va a poder entrar.')"><?= csrf_field() ?>
                        <button class="btn btn-outline-danger btn-sm">Eliminar</button></form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
