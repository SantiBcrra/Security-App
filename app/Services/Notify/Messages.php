<?php
declare(strict_types=1);

namespace App\Services\Notify;

use App\Models\Actions;
use App\Models\Observations;
use App\Services\ObservationWorkflow;

/** Textos de cada evento (título corto para la app/push y cuerpo para email/WhatsApp). */
final class Messages
{
    public const EVENTS = [
        'observation.imminent'  => 'Riesgo inminente reportado',
        'observation.escalated' => 'Riesgo inminente sin confirmar (escalamiento)',
        'observation.created'   => 'Observación nueva',
        'observation.closed'    => 'Observación cerrada',
        // Acciones CAPA (Etapa 10). El "sujeto" es la acción: assignee = responsable, reporter = quien la creó.
        'action.assigned'          => 'Acción asignada',
        'action.due_soon'          => 'Acción por vencer',
        'action.overdue'           => 'Acción vencida (cada día)',
        'action.overdue_escalated' => 'Acción vencida hace varios días (escalamiento)',
        'action.closed'            => 'Acción cerrada: pendiente de verificar',
        'action.verify_overdue'    => 'Verificación atrasada',
        'action.verified'          => 'Acción verificada',
        'action.rejected'          => 'Cierre rechazado (no fue eficaz)',
        'action.cancelled'         => 'Acción cancelada',
        // Inspecciones (Etapa 11). Sujeto = la inspección: reporter = quien inspeccionó.
        'inspection.critical_fail' => 'Inspección con falla en un ítem crítico',
        'inspection.due'           => 'Inspección programada para hoy',
        'inspection.overdue'       => 'Inspección programada vencida',
        // Incidentes (Etapa 12). Sujeto = el incidente; reporter = quien lo reportó. Nunca llevan datos de salud.
        'incident.serious'         => 'Accidente con baja o in itinere',
        'incident.reported'        => 'Incidente o accidente reportado',
        'incident.closed'          => 'Incidente cerrado',
        'incident.investigation_overdue' => 'Investigación de accidente sin empezar',
        'incident.art_pending'     => 'Falta el N° de siniestro de la ART',
        'incident.open_leaves'     => 'Resumen semanal de bajas abiertas',
        // Permisos de trabajo (Etapa 13). Sujeto = el permiso: reporter = solicitante, assignee = autorizante.
        'permit.requested'         => 'Permiso de trabajo para autorizar',
        'permit.decided'           => 'Permiso de trabajo autorizado o rechazado',
        'permit.expiring'          => 'Permiso de trabajo por vencer',
        'permit.expired'           => 'Permiso de trabajo vencido',
    ];

    /** Eventos que no se eligen en las reglas (los dispara el sistema con destinatarios fijos). */
    public const INTERNAL = ['observation.escalated'];

    /** Eventos críticos: llegan siempre (no se pueden silenciar) y se envían en el momento. */
    public const CRITICAL = ['observation.imminent', 'observation.escalated', 'incident.serious'];

    /** @return array{title:string, body:string, url:string, critical:bool} */
    public static function for(string $event, array $obs, array $extra = []): array
    {
        if (str_starts_with($event, 'action.')) {
            return self::forAction($event, $obs, $extra);
        }
        if (str_starts_with($event, 'inspection.')) {
            return self::forInspection($event, $obs);
        }
        if (str_starts_with($event, 'incident.')) {
            return self::forIncident($event, $obs, $extra);
        }
        if (str_starts_with($event, 'permit.')) {
            return self::forPermit($event, $obs, $extra);
        }
        $num = Observations::format((int) $obs['number']);
        $where = trim(($obs['sector_name'] ?? '') . ($obs['equipment_code'] ? ' · ' . $obs['equipment_code'] : ''), ' ·');
        $desc = mb_strimwidth((string) $obs['description'], 0, 220, '…');
        [$title, $body] = match ($event) {
            'observation.imminent'  => ["⚠ RIESGO INMINENTE · {$num}", "{$where}: {$desc}\nVerificá que la tarea esté frenada y confirmá que recibiste el aviso."],
            'observation.escalated' => ["⚠ SIN CONFIRMAR (nivel {$extra['level']}) · {$num}", "Nadie confirmó el riesgo inminente reportado en {$where}.\n{$desc}"],
            'observation.created'   => ["Observación {$obs['severity_name']} · {$num}", "{$where}: {$desc}"],
            'observation.closed'    => ["Tu observación {$num} fue cerrada", 'Estado: ' . ObservationWorkflow::label($obs['status']) . ($extra['comment'] ?? '' ? "\n" . $extra['comment'] : '')
                . "\nGracias por reportar."],
            default                 => [self::EVENTS[$event] ?? $event, $desc],
        };
        return [
            'title'    => $title,
            'body'     => $body,
            'url'      => '/panel/observaciones/' . $obs['uuid'],
            'critical' => in_array($event, self::CRITICAL, true),
        ];
    }

    private static function forAction(string $event, array $a, array $extra): array
    {
        $num = Actions::format((int) $a['number']);
        $due = date('d/m/Y', strtotime((string) $a['due_on']));
        $what = mb_strimwidth((string) $a['title'], 0, 160, '…');
        $where = ($a['sector_name'] ?? null) ? "\nSector: {$a['sector_name']}" : '';
        $who = "\nResponsable: " . ($a['responsible_name'] ?? '—');
        $days = (int) ($extra['days'] ?? 0);
        [$title, $body] = match ($event) {
            'action.assigned'          => ["Te asignaron una acción · {$num}", "{$what}\nFecha límite: {$due}" . $where],
            'action.due_soon'          => ["Acción por vencer · {$num}", "{$what}\nVence el {$due}" . ($days ? " (en {$days} día" . ($days === 1 ? '' : 's') . ')' : '') . $where],
            'action.overdue'           => ["Acción vencida · {$num}", "{$what}\nVenció el {$due}" . ($days ? " (hace {$days} día" . ($days === 1 ? '' : 's') . ')' : '') . $who],
            'action.overdue_escalated' => ["Acción vencida hace {$days} días · {$num}", "{$what}\nVenció el {$due}." . $who . $where],
            'action.closed'            => ["Acción para verificar · {$num}", "{$what}\nCerrada por " . ($a['closed_by_name'] ?? '—') . ': ' . mb_strimwidth((string) $a['closure_text'], 0, 300, '…')],
            'action.verify_overdue'    => ["Verificación atrasada · {$num}", "{$what}\nHabía que verificarla antes del " . date('d/m/Y', strtotime((string) $a['verify_due_on'])) . '.'],
            'action.verified'          => ["Acción verificada · {$num}", "{$what}\nSe verificó como eficaz." . (($extra['comment'] ?? '') !== '' ? "\n" . $extra['comment'] : '')],
            'action.rejected'          => ["Cierre rechazado · {$num}", "{$what}\nNo fue eficaz: " . ($extra['comment'] ?? '') . "\nFecha límite: {$due}"],
            'action.cancelled'         => ["Acción cancelada · {$num}", "{$what}\nMotivo: " . ($extra['comment'] ?? '—')],
            default                    => [self::EVENTS[$event] ?? $event, $what],
        };
        return ['title' => $title, 'body' => $body, 'url' => '/panel/acciones/' . $a['uuid'], 'critical' => false];
    }

    private static function forInspection(string $event, array $i): array
    {
        if (in_array($event, ['inspection.due', 'inspection.overdue'], true)) { // sujeto: la programada
            $what = $i['equipment_code'] ? $i['equipment_code'] . ' · ' . $i['equipment_name'] : ($i['sector_name'] ?? '');
            $due = date('d/m/Y', strtotime((string) $i['due_on']));
            [$title, $body] = $event === 'inspection.due'
                ? ["Inspección para hoy · {$i['template_name']}", "{$what}\nPrograma: {$i['program_name']}. Hacela hoy (" . $due . ').']
                : ["Inspección vencida · {$i['template_name']}", "{$what}\nVenció el {$due} y no se hizo. Programa: {$i['program_name']}."];
            $url = '/panel/inspecciones/nueva?' . http_build_query(array_filter(['plantilla' => $i['template_uuid'], 'equipo' => $i['equipment_uuid'],
                'sector' => $i['equipment_uuid'] ? null : $i['sector_uuid'], 'programada' => $i['uuid']]));
            return ['title' => $title, 'body' => $body, 'url' => $url, 'critical' => false];
        }
        $num = \App\Models\Inspections::format((int) $i['number']);
        $what = $i['equipment_code'] ? $i['equipment_code'] . ' · ' . $i['equipment_name'] : ($i['sector_name'] ?? '');
        $failed = array_filter(\App\Models\Inspections::answers((int) $i['id']), fn ($a) => $a['ok'] !== null && (int) $a['ok'] === 0 && (int) $a['critical'] === 1);
        $list = implode("\n", array_map(fn ($a) => '• ' . $a['item_text'] . ($a['comment'] ? ': ' . $a['comment'] : ''), $failed));
        [$title, $body] = match ($event) {
            'inspection.critical_fail' => ["⚠ Falla crítica · {$what} · {$num}", "{$i['template_name']} hecha por " . ($i['inspector_name'] ?? '—')
                . " con fallas críticas:\n{$list}\nSe crearon las acciones correctivas. Verificá que el equipo no se use hasta resolverlo."],
            default => [self::EVENTS[$event] ?? $event, $what],
        };
        return ['title' => $title, 'body' => $body, 'url' => '/panel/inspecciones/' . $i['uuid'], 'critical' => false];
    }

    private static function forPermit(string $event, array $p, array $extra): array
    {
        $num = \App\Models\WorkPermits::format((int) $p['number']);
        $types = implode(' + ', array_map(fn ($t) => \App\Services\WorkPermitService::TYPES[$t]['short'] ?? $t, json_decode((string) $p['types'], true) ?: []));
        $where = trim(($p['sector_name'] ?? '') . ($p['equipment_code'] ? ' · ' . $p['equipment_code'] : '') . ($p['location_text'] ? ' · ' . $p['location_text'] : ''), ' ·');
        $task = mb_strimwidth((string) $p['task'], 0, 200, '…');
        $ends = fecha($p['ends_at'] ?? $p['valid_until'], 'd/m H:i');
        [$title, $body] = match ($event) {
            'permit.requested' => ["Permiso para autorizar · {$types} · {$num}", "{$where}: {$task}\nSolicita " . ($p['requested_by_name'] ?? '—')
                . ', de ' . fecha($p['valid_from'], 'd/m H:i') . " a {$ends}."],
            'permit.decided'   => ($extra['decision'] ?? '') === 'aprobado'
                ? ["Permiso autorizado · {$num}", "{$types} · {$where}. Autorizó " . ($p['approved_by_name'] ?? '—') . ". Vence {$ends}. Antes de empezar firman los ejecutores."]
                : ["Permiso rechazado · {$num}", "{$types} · {$where}. A corregir: " . ($extra['comment'] ?? '')],
            'permit.expiring'  => ["Permiso por vencer · {$num}", "{$types} · {$where}. Vence a las {$ends}: cerralo o extendelo antes."],
            'permit.expired'   => ["Permiso vencido · {$num}", "{$types} · {$where}. Venció a las {$ends}. Si se sigue trabajando, hace falta un permiso nuevo."],
            default            => [self::EVENTS[$event] ?? $event, $task],
        };
        return ['title' => $title, 'body' => $body, 'url' => '/panel/permisos/' . $p['uuid'], 'critical' => in_array($event, self::CRITICAL, true)];
    }

    private static function forIncident(string $event, array $i, array $extra): array
    {
        if ($event === 'incident.open_leaves') { // resumen (sin un incidente puntual ni nombres)
            return ['title' => 'Bajas abiertas: ' . (int) ($extra['count'] ?? 0), 'body' => 'Hay ' . (int) ($extra['count'] ?? 0)
                . ' persona(s) de baja sin alta médica cargada. Revisá el seguimiento.', 'url' => '/panel/incidentes?bajas=1', 'critical' => false];
        }
        $num = \App\Models\Incidents::format((int) $i['number']);
        $type = \App\Services\IncidentService::typeLabel($i['type']);
        $where = trim(($i['sector_name'] ?? '') . ($i['equipment_code'] ? ' · ' . $i['equipment_code'] : ''), ' ·');
        $desc = mb_strimwidth((string) $i['description'], 0, 220, '…');
        $injured = (int) ($i['injured_count'] ?? 0);
        [$title, $body] = match ($event) {
            'incident.serious'  => ["⚠ {$type} · {$num}", ($where !== '' ? "{$where}: " : '') . "{$desc}\n"
                . ($injured ? "Lesionados: {$injured}. " : '') . 'Coordiná la atención y avisá a la ART.'],
            'incident.reported' => ["{$type} · {$num}", ($where !== '' ? "{$where}: " : '') . $desc],
            'incident.closed'   => ["Incidente {$num} cerrado", "{$type}." . (($extra['comment'] ?? '') !== '' ? "\n" . $extra['comment'] : '')],
            'incident.investigation_overdue' => ["Investigación pendiente · {$num}", "{$type} del " . fecha($i['occurred_at'], 'd/m/Y')
                . ' sin investigación empezada (' . (int) ($extra['days'] ?? 0) . " días).\n{$desc}"],
            'incident.art_pending' => ["Falta el N° de siniestro ART · {$num}", "{$type} del " . fecha($i['occurred_at'], 'd/m/Y')
                . ': hay lesionados sin N° de siniestro cargado. Verificá que se haya hecho la denuncia ante la ART.'],
            default             => [self::EVENTS[$event] ?? $event, $desc],
        };
        return ['title' => $title, 'body' => $body, 'url' => '/panel/incidentes/' . $i['uuid'], 'critical' => in_array($event, self::CRITICAL, true)];
    }

    /** Prioridad de una acción como "nivel" (para el filtro de severidad mínima de las reglas). */
    public static function actionLevel(string $priority): int
    {
        return ['baja' => 1, 'media' => 2, 'alta' => 3, 'critica' => 4][$priority] ?? 2;
    }
}
