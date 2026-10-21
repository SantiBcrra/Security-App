<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Tenant;
use App\Models\Settings;

/**
 * Módulos que cada empresa puede activar o desactivar (Configuración → Módulos), para arrancar con un menú simple e ir
 * sumando herramientas. Un módulo desactivado es, para todo el sistema, un módulo sin permiso: `UserAuth::can()` da false,
 * así desaparece del menú, del inicio, de sus páginas y de la app del celular, sin tocar cada pantalla. Los datos quedan:
 * al reactivarlo vuelve todo como estaba. Inicio, datos maestros, usuarios, roles y configuración no se pueden desactivar.
 */
final class Modules
{
    /** Grupo del menú => [módulo => [nombre, para qué sirve]]. En el orden del menú lateral. */
    public const GROUPS = [
        'Operación' => [
            'rondas'           => ['Rondas', 'Rondas de guardias con QR / NFC, GPS, pánico y programación'],
            'libro'            => ['Libro de guardia', 'Novedades, ingreso y egreso de personas y vehículos, relevo de turno con firma'],
            'permisos_trabajo' => ['Permisos de trabajo', 'Trabajos en altura, en caliente, espacios confinados, bloqueos'],
            'epp'              => ['EPP', 'Entregas de elementos de protección personal con firma'],
        ],
        'Prevención' => [
            'observaciones' => ['Observaciones', 'Reportes de actos y condiciones inseguras'],
            'inspecciones'  => ['Inspecciones', 'Checklists de equipos y sectores, programados'],
            'acciones'      => ['Acciones', 'Acciones correctivas y su seguimiento (las crean los otros módulos)'],
            'incidentes'    => ['Incidentes', 'Accidentes, incidentes e investigación'],
        ],
    ];

    private const SETTING = 'modulos.desactivados';
    private static array $cache = [];

    /** @return list<string> */
    public static function disabled(): array
    {
        $tenant = Tenant::current()['uuid'] ?? null;
        if ($tenant === null) return [];
        if (!array_key_exists($tenant, self::$cache)) {
            try {
                $list = json_decode((string) Settings::get(self::SETTING, '[]'), true);
            } catch (\Throwable) {
                $list = []; // base sin la tabla de ajustes todavía (instalación / migraciones)
            }
            self::$cache[$tenant] = array_values(array_intersect(is_array($list) ? $list : [], self::keys()));
        }
        return self::$cache[$tenant];
    }

    public static function enabled(string $module): bool
    {
        return !in_array($module, self::disabled(), true);
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(array_merge(...array_values(self::GROUPS)));
    }

    /** @param list<string> $enabled los que quedan activos (los demás se desactivan) */
    public static function save(array $enabled): array
    {
        $off = array_values(array_diff(self::keys(), $enabled));
        Settings::set(self::SETTING, json_encode($off));
        self::$cache = [];
        return $off;
    }

    public static function forget(): void
    {
        self::$cache = [];
    }
}
