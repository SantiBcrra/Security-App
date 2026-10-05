<?php
declare(strict_types=1);

use App\Models\Patrols;
use App\Policies\Permissions;

return [
    'rondas: calcula distancia GPS y respeta un radio' => function (): void {
        assert_same(0.0, Patrols::distance(-34.6037, -58.3816, -34.6037, -58.3816));
        $d = Patrols::distance(-34.6037, -58.3816, -34.6040, -58.3816);
        assert_true($d > 30 && $d < 40, 'distancia aproximada');
    },
    'rondas: los roles operativos tienen permisos' => function (): void {
        $roles = Permissions::baseRoles();
        assert_true(in_array('crear', $roles['reportante']['permissions']['rondas']['acciones'], true));
        assert_true(in_array('cerrar', $roles['supervisor']['permissions']['rondas']['acciones'], true));
        assert_true(in_array('exportar', $roles['admin_empresa']['permissions']['rondas']['acciones'], true));
    },
];
