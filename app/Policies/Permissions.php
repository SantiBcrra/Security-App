<?php
declare(strict_types=1);

namespace App\Policies;

/**
 * Fuente única de módulos, acciones, alcances y roles base.
 * Permisos de un rol (JSON en roles.permissions):
 *   {"observaciones": {"acciones": ["ver", "crear"], "alcance": "propios"}, ...}
 * Un módulo ausente = sin acceso.
 */
final class Permissions
{
    public const MODULES = [
        'usuarios'         => 'Usuarios',
        'roles'            => 'Roles',
        'configuracion'    => 'Configuración',
        'datos_maestros'   => 'Datos maestros',
        'observaciones'    => 'Observaciones',
        'acciones'         => 'Acciones correctivas',
        'inspecciones'     => 'Inspecciones',
        'incidentes'       => 'Incidentes',
        'permisos_trabajo' => 'Permisos de trabajo',
        'epp'              => 'EPP',
        'capacitaciones'   => 'Capacitaciones',
        'reportes'         => 'Reportes',
        'rondas'           => 'Rondas de guardias',
    ];

    public const ACTIONS = [
        'ver'      => 'Ver',
        'crear'    => 'Crear',
        'editar'   => 'Editar',
        'cerrar'   => 'Cerrar',
        'exportar' => 'Exportar',
        'verificar'=> 'Verificar',
    ];

    /** Acciones que solo existen en algunos módulos (en el resto no se ofrecen ni se guardan). */
    public const MODULE_ONLY_ACTIONS = [
        'verificar' => ['acciones'], // verificación de eficacia de una acción CAPA
    ];

    /** todo = toda la empresa; sectores = solo sus sectores asignados (Etapa 4); propios = lo que creó. */
    public const SCOPES = [
        'todo'     => 'Toda la empresa',
        'sectores' => 'Sus sectores',
        'propios'  => 'Solo lo propio',
    ];

    /** Módulos de seguridad e higiene (los que gestiona el responsable de SyH). */
    public const SH_MODULES = ['observaciones', 'acciones', 'inspecciones', 'incidentes', 'permisos_trabajo', 'epp', 'capacitaciones', 'reportes', 'rondas'];

    public const ADMIN_ROLE = 'admin_empresa';
    public const AUDITOR_ROLE = 'auditor';

    /** @return list<string> acciones que tienen sentido en el módulo */
    public static function actionsFor(string $module): array
    {
        return array_values(array_filter(array_keys(self::ACTIONS),
            fn ($a) => !isset(self::MODULE_ONLY_ACTIONS[$a]) || in_array($module, self::MODULE_ONLY_ACTIONS[$a], true)));
    }

    public static function can(array $permissions, string $module, string $action): bool
    {
        return in_array($action, $permissions[$module]['acciones'] ?? [], true);
    }

    public static function scope(array $permissions, string $module): ?string
    {
        return isset($permissions[$module]) ? ($permissions[$module]['alcance'] ?? 'todo') : null;
    }

    /**
     * Limpia permisos que llegan de un formulario: solo módulos/acciones/alcances conocidos.
     * @param array $input ['observaciones' => ['acciones' => ['ver', ...], 'alcance' => 'todo'], ...]
     */
    public static function normalize(array $input): array
    {
        $clean = [];
        foreach (self::MODULES as $module => $_) {
            $actions = array_values(array_intersect(self::actionsFor($module), (array) ($input[$module]['acciones'] ?? [])));
            if (!$actions) {
                continue;
            }
            if (!in_array('ver', $actions, true)) {
                array_unshift($actions, 'ver'); // cualquier acción implica poder ver
            }
            $scope = (string) ($input[$module]['alcance'] ?? 'todo');
            $clean[$module] = ['acciones' => $actions, 'alcance' => isset(self::SCOPES[$scope]) ? $scope : 'todo'];
        }
        return $clean;
    }

    public static function decode(?string $json): array
    {
        $data = json_decode((string) $json, true);
        return is_array($data) ? self::normalize($data) : [];
    }

    /** Roles base que se cargan en cada empresa nueva. */
    public static function baseRoles(): array
    {
        $all = array_keys(self::ACTIONS);
        $grant = function (array $modules, array $actions, string $scope): array {
            $perms = [];
            foreach ($modules as $m) {
                $perms[$m] = ['acciones' => array_values(array_intersect($actions, self::actionsFor($m))), 'alcance' => $scope];
            }
            return $perms;
        };
        $shWork = array_values(array_diff(self::SH_MODULES, ['reportes']));

        return [
            self::ADMIN_ROLE => [
                'name'        => 'Administrador de la empresa',
                'description' => 'Configuración total de la empresa, usuarios y todos los módulos.',
                'permissions' => $grant(array_keys(self::MODULES), $all, 'todo'),
            ],
            'responsable_hys' => [
                'name'        => 'Responsable de Seguridad e Higiene',
                'description' => 'Gestiona todos los módulos de seguridad e higiene de la empresa.',
                'permissions' => $grant(self::SH_MODULES, $all, 'todo')
                    + $grant(['datos_maestros'], ['ver', 'crear', 'editar', 'exportar'], 'todo')
                    + $grant(['usuarios'], ['ver'], 'todo'),
            ],
            'supervisor' => [
                'name'        => 'Supervisor',
                'description' => 'Ve y gestiona solo sus sectores asignados.',
                'permissions' => $grant($shWork, ['ver', 'crear', 'editar', 'cerrar'], 'sectores')
                    + $grant(['reportes'], ['ver', 'exportar'], 'sectores')
                    + $grant(['datos_maestros'], ['ver'], 'todo'),
            ],
            'reportante' => [
                'name'        => 'Reportante',
                'description' => 'Operario o guardia: crea reportes y ve los propios.',
                'permissions' => $grant(['observaciones', 'incidentes'], ['ver', 'crear'], 'propios')
                    + $grant(['rondas'], ['ver', 'crear', 'cerrar'], 'propios')
                    + $grant(['acciones'], ['ver'], 'propios'), // como responsable igual toma y cierra las suyas
            ],
            self::AUDITOR_ROLE => [
                'name'        => 'Auditor externo',
                'description' => 'Solo lectura (ART, consultor). Acceso con fecha de vencimiento.',
                'permissions' => $grant(array_merge(self::SH_MODULES, ['datos_maestros']), ['ver', 'exportar'], 'todo'),
            ],
        ];
    }
}
