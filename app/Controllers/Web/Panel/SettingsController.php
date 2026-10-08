<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Settings;
use App\Services\Audit;

/** Configuración de la empresa (por ahora: observaciones). */
final class SettingsController
{
    public function show(Request $request): Response
    {
        return Response::html(View::render('panel/settings', [
            'title'     => 'Configuración',
            'anonymous' => Settings::bool('observaciones.anonimo_habilitado'),
            'company'   => array_map(fn ($k) => Settings::get($k) ?? '', self::COMPANY),
            'tenant'    => \App\Core\Tenant::current(),
            'permits'   => self::permitSettings(),
        ], 'layouts/app'));
    }

    /** Datos del empleador para la denuncia ante la ART (Etapa 12). Razón social y CUIT vienen del alta de la empresa. */
    public const COMPANY = ['domicilio' => 'empresa.domicilio', 'actividad' => 'empresa.actividad', 'ciiu' => 'empresa.ciiu',
        'art' => 'empresa.art_nombre', 'art_contrato' => 'empresa.art_contrato', 'art_telefono' => 'empresa.art_telefono'];

    public function updateCompany(Request $request): Response
    {
        $before = array_map(fn ($k) => Settings::get($k), self::COMPANY);
        $after = [];
        foreach (self::COMPANY as $field => $key) {
            $value = mb_substr(trim((string) $request->input($field, '')), 0, 191);
            Settings::set($key, $value !== '' ? $value : null);
            $after[$field] = $value !== '' ? $value : null;
        }
        Audit::tenant('settings.company', 'settings', null, $before, $after);
        Flash::add('success', 'Datos de la empresa guardados.');
        return Response::redirect('/panel/configuracion');
    }

    /** Permisos de trabajo (Etapa 13): campo => [setting, defecto, mínimo, máximo]. */
    public const PERMITS = [
        'max_horas'   => ['permisos.max_horas', 12, 1, 24],
        'vigia'       => ['permisos.vigia_minutos', 30, 0, 240],
        'gas_o2_min'  => ['permisos.gas_o2_min', 19.5, 0, 100],
        'gas_o2_max'  => ['permisos.gas_o2_max', 23.5, 0, 100],
        'gas_lel_max' => ['permisos.gas_lel_max', 10, 0, 100],
        'gas_co_max'  => ['permisos.gas_co_max', 25, 0, 10000],
        'gas_h2s_max' => ['permisos.gas_h2s_max', 10, 0, 10000],
    ];

    public static function permitSettings(): array
    {
        return array_map(fn ($d) => (string) (Settings::get($d[0]) ?? $d[1]), self::PERMITS);
    }

    public function updatePermits(Request $request): Response
    {
        $before = self::permitSettings();
        $values = [];
        foreach (self::PERMITS as $field => [$key, $default, $min, $max]) {
            $raw = str_replace(',', '.', trim((string) $request->input($field, '')));
            if (!is_numeric($raw) || (float) $raw < $min || (float) $raw > $max) {
                Flash::add('danger', "Valor inválido en permisos de trabajo ({$field}): entre {$min} y {$max}.");
                return Response::redirect('/panel/configuracion');
            }
            $values[$key] = (string) (0 + $raw);
        }
        if ((float) $values['permisos.gas_o2_min'] >= (float) $values['permisos.gas_o2_max']) {
            Flash::add('danger', 'El O₂ mínimo tiene que ser menor que el máximo.');
            return Response::redirect('/panel/configuracion');
        }
        foreach ($values as $key => $value) {
            Settings::set($key, $value);
        }
        Audit::tenant('settings.permits', 'settings', null, $before, self::permitSettings());
        Flash::add('success', 'Configuración de permisos de trabajo guardada.');
        return Response::redirect('/panel/configuracion');
    }

    public function update(Request $request): Response
    {
        $before = Settings::bool('observaciones.anonimo_habilitado');
        $after = (bool) $request->input('anonymous');
        Settings::set('observaciones.anonimo_habilitado', $after ? '1' : '0');
        if ($before !== $after) {
            Audit::tenant('settings.update', 'settings', null, ['reporte_anonimo' => $before], ['reporte_anonimo' => $after]);
        }
        Flash::add('success', 'Configuración guardada.');
        return Response::redirect('/panel/configuracion');
    }
}
