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
        ], 'layouts/app'));
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
