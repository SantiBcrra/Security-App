<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Models\Actions;
use App\Models\Inspections;
use App\Services\InspectionService;
use App\Services\InspectionStructure;

/** Detalle de una inspección para la app de campo (respuestas, resultado y acciones creadas). */
final class InspectionsController
{
    public function show(Request $request, string $uuid): Response
    {
        $i = Inspections::findByUuid($uuid);
        if ($i === null || !InspectionService::canView($i)) {
            return Response::jsonError('No existe o no tenés acceso.', 404);
        }
        return Response::json([
            'uuid' => $i['uuid'], 'code' => Inspections::format((int) $i['number']), 'template' => $i['template_name'],
            'equipment' => $i['equipment_code'] ? $i['equipment_code'] . ' · ' . $i['equipment_name'] : null, 'sector' => $i['sector_name'],
            'inspector' => $i['inspector_name'], 'done_at' => str_replace(' ', 'T', $i['done_at_device']) . 'Z',
            'result' => $i['result'], 'result_label' => InspectionService::RESULTS[$i['result']]['label'], 'score' => $i['score'] !== null ? (int) $i['score'] : null,
            'status' => $i['status'], 'notes' => $i['notes'],
            'answers' => array_map(fn ($a) => [
                'section' => $a['section_title'], 'text' => $a['item_text'], 'value' => InspectionStructure::valueLabel($a['value']),
                'ok' => $a['ok'] === null ? null : (bool) (int) $a['ok'], 'critical' => (bool) (int) $a['critical'], 'comment' => $a['comment'],
                'action_uuid' => $a['action_uuid'], 'action_code' => $a['action_number'] ? Actions::format((int) $a['action_number']) : null,
            ], Inspections::answers((int) $i['id'])),
        ]);
    }
}
