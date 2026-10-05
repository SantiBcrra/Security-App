<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Models\ObservationAttachments;
use App\Models\ObservationEvents;
use App\Models\Observations;
use App\Services\ObservationService;
use App\Services\ObservationWorkflow;

/** Detalle de una observación para la app (con línea de tiempo y fotos). */
final class ObservationsController
{
    public function show(Request $request, string $uuid): Response
    {
        $obs = Observations::findByUuid($uuid);
        if ($obs === null || !ObservationService::canView($obs)) {
            return Response::jsonError('No existe o no tenés acceso.', 404);
        }
        $events = array_map(fn ($e) => [
            'type' => $e['type'], 'from' => $e['from_status'], 'to' => $e['to_status'], 'to_label' => $e['to_status'] ? ObservationWorkflow::label($e['to_status']) : null,
            'comment' => $e['comment'], 'actor' => $e['actor_name'], 'at' => str_replace(' ', 'T', $e['created_at']) . 'Z',
            'data' => $e['data'] ? json_decode($e['data'], true) : null,
        ], ObservationEvents::forObservation((int) $obs['id']));
        return Response::json([
            'uuid' => $obs['uuid'], 'number' => (int) $obs['number'], 'code' => Observations::format((int) $obs['number']),
            'status' => $obs['status'], 'status_label' => ObservationWorkflow::label($obs['status']),
            'category' => $obs['category_name'], 'severity' => $obs['severity_name'], 'severity_color' => $obs['severity_color'],
            'risk' => $obs['risk_name'], 'sector' => $obs['sector_name'], 'site' => $obs['site_name'],
            'equipment' => $obs['equipment_code'] ? $obs['equipment_code'] . ' · ' . $obs['equipment_name'] : null,
            'description' => $obs['description'], 'location' => $obs['location_text'], 'imminent' => (bool) $obs['imminent_risk'],
            'reporter' => $obs['is_anonymous'] ? null : $obs['reporter_name'], 'assigned' => $obs['assigned_name'],
            'action' => $obs['action_text'], 'action_due_on' => $obs['action_due_on'],
            'created_at_device' => str_replace(' ', 'T', $obs['created_at_device']) . 'Z',
            'photos' => array_map(fn ($p) => ['uuid' => $p['uuid'], 'sha256' => $p['sha256'],
                'url' => url('/api/v1/observations/' . $obs['uuid'] . '/photos/' . $p['uuid'])], ObservationAttachments::forObservation((int) $obs['id'])),
            'events' => $events,
            'actions' => array_keys(ObservationWorkflow::available($obs['status'])),
        ]);
    }

    public function photo(Request $request, string $uuid, string $photo): Response
    {
        $obs = Observations::findByUuid($uuid);
        $att = $obs && ObservationService::canView($obs) ? ObservationAttachments::find((int) $obs['id'], $photo) : null;
        $file = $att ? \App\Core\TenantFiles::path(\App\Core\Tenant::current()['uuid'], $att['thumb_path'] ?: $att['path']) : null;
        return $file ? \App\Core\TenantFiles::response($file, 3600) : Response::jsonError('Foto inexistente.', 404);
    }
}
