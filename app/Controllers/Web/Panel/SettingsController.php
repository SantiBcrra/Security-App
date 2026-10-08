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
