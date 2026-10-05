<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\Uploads;

/** Fotos por partes: alta → partes (PUT ?offset=) → completar. Reanudable e idempotente. */
final class UploadsController
{
    public function init(Request $request): Response
    {
        return self::respond(Uploads::init($request->post));
    }

    public function status(Request $request, string $uuid): Response
    {
        $up = Uploads::find($uuid);
        return $up ? self::respond(['upload_uuid' => $up['uuid'], 'status' => $up['status'], 'size' => (int) $up['size_bytes'],
            'received_bytes' => (int) $up['received_bytes'], 'attachment_uuid' => $up['attachment_uuid']]) : Response::jsonError('Subida inexistente.', 404);
    }

    public function chunk(Request $request, string $uuid): Response
    {
        return self::respond(Uploads::chunk($uuid, (int) $request->input('offset', -1), $request->rawBody()));
    }

    public function complete(Request $request, string $uuid): Response
    {
        return self::respond(Uploads::complete($uuid));
    }

    private static function respond(array $result): Response
    {
        if (isset($result['error'])) {
            $code = (int) ($result['code'] ?? 422);
            unset($result['code']);
            $error = $result['error'];
            unset($result['error']);
            $body = json_encode(['ok' => false, 'data' => $result ?: null, 'error' => ['message' => $error, 'code' => 'upload']], JSON_UNESCAPED_UNICODE);
            return new Response($body, $code, ['Content-Type' => 'application/json; charset=utf-8']);
        }
        return Response::json($result);
    }
}
