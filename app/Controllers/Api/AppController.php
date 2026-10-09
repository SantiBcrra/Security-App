<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\ApiAuth;
use App\Services\AppDistribution;
use App\Services\Consent;
use App\Services\UserAuth;

/** App Android: última versión publicada (sin sesión, la consulta también la pantalla de ingreso) y consentimiento. */
final class AppController
{
    public function android(Request $request): Response
    {
        $m = AppDistribution::manifest();
        return $m === null ? Response::jsonError('Todavía no hay una versión publicada.', 404, 'no_release') : Response::json($m);
    }

    /** Pánico directo (sin esperar la cola): la app lo manda apenas se activa; si falla, va por SMS y queda en la cola. */
    public function panic(Request $request): Response
    {
        try {
            $r = \App\Services\GuardSafety::panic($request->post, 'datos');
        } catch (\App\Core\UserError $e) {
            return Response::jsonError($e->getMessage(), 422);
        }
        return Response::json(['uuid' => $r['panic']['uuid'], 'duplicate' => $r['duplicate'], 'recibido' => true]);
    }

    public function consent(Request $request): Response
    {
        return Response::json(['version' => Consent::VERSION, 'texto' => Consent::text(), 'aceptado' => Consent::accepted((int) UserAuth::user()['id'])]);
    }

    public function accept(Request $request): Response
    {
        $ok = Consent::accept((int) UserAuth::user()['id'], (string) $request->input('version', ''), ApiAuth::device()['uuid'] ?? null, $request->ip());
        return $ok ? Response::json(['version' => Consent::VERSION, 'aceptado' => true])
            : Response::jsonError('El texto cambió: volvé a leerlo y aceptalo.', 409, 'consent_outdated');
    }
}
