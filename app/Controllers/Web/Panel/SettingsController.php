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
            'guards'    => ['phones' => (string) (Settings::get('guardias.panico_telefonos') ?? ''), 'track' => \App\Services\GuardSafety::trackSeconds(),
                'silent' => \App\Services\GuardSafety::silentMinutes()],
            'epp'       => self::eppSettings(),
            'logoUrl'   => ($t = \App\Core\Tenant::current()) ? \App\Controllers\Web\App\HomeController::logoUrl($t) : null,
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

    /** Guardias (app Android): teléfonos para el SMS de pánico, cada cuánto manda la posición y cuándo avisar "sin señal". */
    public function updateGuards(Request $request): Response
    {
        $phones = [];
        foreach (preg_split('/[\s,;]+/', (string) $request->input('phones', '')) ?: [] as $raw) {
            $p = preg_replace('/[^\d+]/', '', $raw);
            if ($p === '') {
                continue;
            }
            if (strlen($p) < 8 || strlen($p) > 16) {
                Flash::add('danger', "Teléfono inválido: {$raw}. Usá el formato internacional, por ejemplo +5491122334455.");
                return Response::redirect('/panel/configuracion#guardias');
            }
            $phones[] = $p;
        }
        if (count($phones) > 3) {
            Flash::add('danger', 'Hasta 3 teléfonos de pánico.');
            return Response::redirect('/panel/configuracion#guardias');
        }
        $track = (int) $request->input('track', 60);
        $silent = (int) $request->input('silent', 10);
        if ($track < 15 || $track > 600 || $silent < 3 || $silent > 240 || $silent * 60 < $track * 2) {
            Flash::add('danger', 'Posición: entre 15 y 600 segundos. "Sin señal": entre 3 y 240 minutos, y al menos el doble que el intervalo de posición.');
            return Response::redirect('/panel/configuracion#guardias');
        }
        $before = ['telefonos' => Settings::get('guardias.panico_telefonos'), 'posicion' => Settings::get('rondas.track_segundos'), 'sin_senal' => Settings::get('rondas.minutos_sin_senal')];
        Settings::set('guardias.panico_telefonos', implode(',', $phones));
        Settings::set('rondas.track_segundos', (string) $track);
        Settings::set('rondas.minutos_sin_senal', (string) $silent);
        Audit::tenant('settings.guards', 'settings', null, $before, ['telefonos' => implode(',', $phones), 'posicion' => $track, 'sin_senal' => $silent]);
        Flash::add('success', 'Configuración de guardias guardada. Los celulares la toman en la próxima sincronización.');
        return Response::redirect('/panel/configuracion#guardias');
    }

    private const LOGO_MAX_BYTES = 1024 * 1024;

    /** Logo de la empresa (se ve en el menú lateral). PNG, JPG o WEBP de hasta 1 MB. */
    public function uploadLogo(Request $request): Response
    {
        $tenant = \App\Core\Tenant::current();
        try {
            $path = \App\Core\TenantFiles::storeUpload($tenant['uuid'], $request->files['logo'] ?? [], 'logo', \App\Core\TenantFiles::IMAGE_TYPES, self::LOGO_MAX_BYTES);
        } catch (\DomainException $e) {
            Flash::add('danger', 'Logo: ' . $e->getMessage());
            return Response::redirect('/panel/configuracion#logo');
        }
        \App\Models\Tenants::update($tenant['uuid'], ['logo_path' => $path]);
        Audit::tenant('settings.logo', 'settings', null, ['logo' => $tenant['logo_path']], ['logo' => $path]);
        Flash::add('success', 'Logo actualizado.');
        return Response::redirect('/panel/configuracion#logo');
    }

    public function removeLogo(Request $request): Response
    {
        $tenant = \App\Core\Tenant::current();
        \App\Models\Tenants::update($tenant['uuid'], ['logo_path' => null]);
        Audit::tenant('settings.logo', 'settings', null, ['logo' => $tenant['logo_path']], ['logo' => null]);
        Flash::add('success', 'Logo quitado.');
        return Response::redirect('/panel/configuracion#logo');
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

    public const EPP = ['aviso_dias' => ['epp.aviso_dias', 15, 1, 90]];
    public static function eppSettings(): array { return array_map(fn($d) => (string)(Settings::get($d[0]) ?? $d[1]), self::EPP); }
    public function updateEpp(Request $request): Response
    {
        foreach (self::EPP as $field => [$key, $default, $min, $max]) {
            $v = (int)$request->input($field, $default);
            if ($v < $min || $v > $max) { Flash::add('danger', 'El aviso de EPP debe estar entre '.$min.' y '.$max.' días.'); return Response::redirect('/panel/configuracion'); }
            Settings::set($key, (string)$v);
        }
        Audit::tenant('settings.epp', 'settings', null, null, self::eppSettings());
        Flash::add('success', 'Configuración de EPP guardada.');
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
