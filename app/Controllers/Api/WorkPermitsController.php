<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Models\WorkPermits;
use App\Services\WorkPermitApi;
use App\Services\WorkPermitService;

/**
 * Permisos de trabajo en la app de campo. Lo que se hace sin señal viaja por la sincronización (WorkPermitApi::apply);
 * autorizar o rechazar es solo con conexión, para que quien autoriza vea el permiso actualizado.
 */
final class WorkPermitsController
{
    public function show(Request $request, string $uuid): Response
    {
        $p = $this->find($uuid);
        return $p === null ? Response::jsonError('No existe o no tenés acceso.', 404) : Response::json(WorkPermitApi::detail($p));
    }

    public function approve(Request $request, string $uuid): Response
    {
        $p = $this->find($uuid);
        if ($p === null) {
            return Response::jsonError('No existe o no tenés acceso.', 404);
        }
        $error = WorkPermitService::approve($p, (string) $request->input('signature', ''), $this->meta($request), (string) $request->input('comment', ''));
        return $error !== null ? Response::jsonError($error, 422) : Response::json(WorkPermitApi::detail(WorkPermits::findById((int) $p['id'])));
    }

    public function reject(Request $request, string $uuid): Response
    {
        $p = $this->find($uuid);
        if ($p === null) {
            return Response::jsonError('No existe o no tenés acceso.', 404);
        }
        $error = WorkPermitService::reject($p, (string) $request->input('comment', ''));
        return $error !== null ? Response::jsonError($error, 422) : Response::json(WorkPermitApi::detail(WorkPermits::findById((int) $p['id'])));
    }

    /** QR colgado en el lugar: cualquier usuario de la empresa ve si está vigente y quién trabaja. */
    public function verify(Request $request, string $uuid): Response
    {
        $p = WorkPermits::findByUuid(strtolower($uuid));
        return $p === null ? Response::jsonError('Ese QR no es de un permiso de esta empresa.', 404) : Response::json(WorkPermitApi::verification($p));
    }

    private function find(string $uuid): ?array
    {
        $p = WorkPermits::findByUuid(strtolower($uuid));
        return $p !== null && WorkPermitService::canView($p) ? $p : null;
    }

    private function meta(Request $request): array
    {
        return ['ip' => $request->ip(), 'user_agent' => mb_substr((string) $request->header('user-agent'), 0, 255) ?: 'App de campo'];
    }
}
