<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Core\Tenant;
use App\Core\TenantFiles;
use App\Models\ActionAttachments;
use App\Models\ActionEvents;
use App\Models\Actions;
use App\Services\ActionService;
use App\Services\ActionWorkflow;

/** Detalle de una acción para la app de campo (línea de tiempo y evidencia). */
final class ActionsController
{
    private const EVENT_LABELS = ['created' => 'Acción creada', 'started' => 'Tomada', 'updated' => 'Datos modificados', 'comment' => 'Comentario',
        'evidence' => 'Evidencia agregada', 'closed' => 'Cerrada con evidencia', 'verified' => 'Verificada: eficaz',
        'rejected' => 'Rechazada: no fue eficaz', 'cancelled' => 'Cancelada', 'migrated' => 'Migrada'];

    public function show(Request $request, string $uuid): Response
    {
        $a = Actions::findByUuid($uuid);
        if ($a === null || !ActionService::canView($a)) {
            return Response::jsonError('No existe o no tenés acceso.', 404);
        }
        return Response::json([
            'uuid' => $a['uuid'], 'code' => Actions::format((int) $a['number']), 'status' => $a['status'], 'status_label' => ActionWorkflow::label($a['status']),
            'closed_by' => $a['closed_by_name'], 'verified_by' => $a['verified_by_name'], 'verification_text' => $a['verification_text'],
            'events' => array_map(fn ($e) => [
                'type' => $e['type'], 'label' => self::EVENT_LABELS[$e['type']] ?? $e['type'], 'comment' => $e['comment'], 'actor' => $e['actor_name'],
                'at' => str_replace(' ', 'T', $e['created_at']) . 'Z',
            ], ActionEvents::forAction((int) $a['id'])),
            'files' => array_map(fn ($f) => ['uuid' => $f['uuid'], 'kind' => $f['kind'], 'cycle' => (int) $f['cycle'], 'image' => str_starts_with($f['mime'], 'image/'),
                'name' => $f['original_name'], 'url' => url('/api/v1/actions/' . $a['uuid'] . '/files/' . $f['uuid'])], ActionAttachments::forAction((int) $a['id'])),
        ]);
    }

    /** Miniatura (o el archivo, si no es imagen) de una evidencia. */
    public function file(Request $request, string $uuid, string $file): Response
    {
        $a = Actions::findByUuid($uuid);
        $att = $a && ActionService::canView($a) ? ActionAttachments::find((int) $a['id'], $file) : null;
        $path = $att ? TenantFiles::path(Tenant::current()['uuid'], $att['thumb_path'] ?: $att['path']) : null;
        return $path ? TenantFiles::response($path, 3600) : Response::jsonError('Archivo inexistente.', 404);
    }
}
