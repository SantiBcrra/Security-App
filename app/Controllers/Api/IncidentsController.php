<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Models\Incidents;
use App\Services\IncidentService;

/** Detalle de un incidente para la app de campo. Nunca incluye datos de salud (eso es solo web, con permiso). */
final class IncidentsController
{
    public function show(Request $request, string $uuid): Response
    {
        $i = Incidents::findByUuid($uuid);
        if ($i === null || !IncidentService::canView($i)) {
            return Response::jsonError('No existe o no tenés acceso.', 404);
        }
        return Response::json([
            'uuid' => $i['uuid'], 'code' => Incidents::format((int) $i['number']), 'type' => $i['type'], 'type_label' => IncidentService::typeLabel($i['type']),
            'status' => $i['status'], 'status_label' => IncidentService::STATES[$i['status']]['label'],
            'occurred_at' => str_replace(' ', 'T', $i['occurred_at']) . 'Z', 'sector' => $i['sector_name'],
            'equipment' => $i['equipment_code'] ? $i['equipment_code'] . ' · ' . $i['equipment_name'] : null, 'location' => $i['location_text'],
            'description' => $i['description'], 'immediate_actions' => $i['immediate_actions'], 'reporter' => $i['reporter_name'],
            'people' => array_map(fn ($p) => ['name' => $p['employee_id'] ? $p['last_name'] . ', ' . $p['first_name'] : $p['external_name'],
                'role' => IncidentService::ROLES[$p['role']]], Incidents::people((int) $i['id'])),
        ]);
    }
}
