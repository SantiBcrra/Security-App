<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\Sync\Pull;
use App\Services\Sync\Push;

/** Sincronización offline de la app: pull (cambios del servidor) y push (operaciones del celular). */
final class SyncController
{
    public function pull(Request $request): Response
    {
        return Response::json(Pull::run($request->input('cursor') !== null ? (string) $request->input('cursor') : null, (int) $request->input('limit', 500)));
    }

    public function push(Request $request): Response
    {
        $ops = $request->input('operations');
        if (!is_array($ops) || !$ops) {
            return Response::jsonError('Falta la lista "operations".', 422, 'invalid');
        }
        if (count($ops) > Push::MAX_OPS) {
            return Response::jsonError('Máximo ' . Push::MAX_OPS . ' operaciones por envío.', 413, 'too_many');
        }
        return Response::json(['results' => Push::run(array_values($ops))]);
    }
}
