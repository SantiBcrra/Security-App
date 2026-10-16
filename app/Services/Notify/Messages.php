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
        'permit.gas_alarm'         => 'Gases fuera de rango en un permiso',
        'permit.suspended'         => 'Permiso de trabajo suspendido',
        // Guardias (app Android). Sujeto = la alerta de pánico o la ronda.
        'guard.panic'              => 'Botón de pánico de un guardia',
        'guard.silent'             => 'Guardia sin señal durante una ronda',
        'guard.off_site'           => 'Punto de ronda marcado lejos del lugar o con GPS falso',
        'guard.no_progress'        => 'Ronda sin marcar puntos',
        'guard.round_late'         => 'Ronda atrasada',
        'guard.device'             => 'Celular de un guardia con problemas durante la ronda',
        'guard.round_not_started'  => 'Ronda programada sin empezar',
        'guard.round_missed'       => 'Ronda programada no realizada',
        'guard.daily_summary'      => 'Resumen diario de rondas',
        'guard.shift_not_started'  => 'Turno de guardia sin empezar',
        'guard.shift_missed'       => 'Guardia ausente (no vino al turno)',
        'guard.shift_no_end'       => 'Turno sin marcar el fin (cerrado solo)',
        'guard.shift_start_outside'=> 'Turno empezado fuera del predio',
        'guard.shift_exit'         => 'Guardia salió del predio (con motivo)',
        'guard.shift_exit_alarm'   => 'Guardia fuera del predio sin motivo',
        'guard.shift_silent'       => 'Guardia en turno sin contacto o sin GPS',
        'guard.shift_uncovered'    => 'Puesto sin cubrir (ausencia sin reemplazo)',
        'guard.absence_requested'  => 'Pedido de ausencia de un guardia',
        'guard.absence_decided'    => 'Respuesta a un pedido de ausencia',
        'ppe.due_soon' => 'EPP por vencer', 'ppe.overdue' => 'EPP vencido', 'ppe.missing' => 'EPP pendiente',
    ];

    /** Eventos que no se eligen en las reglas (los dispara el sistema con destinatarios fijos). */
    public const INTERNAL = ['observation.escalated'];

    /** Eventos críticos: llegan siempre (no se pueden silenciar) y se envían en el momento. */
    public const CRITICAL = ['observation.imminent', 'observation.escalated', 'incident.serious', 'permit.gas_alarm', 'guard.panic'];

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
        if (str_starts_with($event, 'ppe.')) return self::forPpe($event, $obs, $extra);
        if (str_starts_with($event, 'guard.')) {
            return self::forGuard($event, $obs, $extra);
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

    private static function forGuard(string $event, array $r, array $extra): array
    {
        if (str_starts_with($event, 'guard.shift_') || str_starts_with($event, 'guard.absence_')) {
            return self::forShift($event, $r);
        }
        if ($event === 'guard.no_progress') {
            $who = $r['user_name'] ?? 'Un guardia';
            return ['title' => 'Ronda sin marcar puntos · ' . $who,
                'body' => "{$who} está en ronda (" . ($r['route_name'] ?: 'ronda libre') . ') y hace ' . (int) ($r['idle_minutes'] ?? 0)
                    . ' min que no marca ningún punto (último a las ' . fecha($r['since'] ?? $r['started_at'], 'H:i') . '). El celular sigue mandando su ubicación: comunicate con él.',
                'url' => '/panel/rondas/ronda/' . $r['uuid'], 'critical' => false];
        }
        if ($event === 'guard.daily_summary') {
            $m = $r['r'];
            $ro = $m['rounds'];
            $sl = $m['slots'];
            $lines = ["Rondas: {$ro['rounds']} ({$ro['completas']} completas, {$ro['incompletas']} incompletas"
                . ($ro['atrasadas'] ? ", {$ro['atrasadas']} atrasadas" : '') . ($ro['abandonadas'] ? ", {$ro['abandonadas']} abandonadas" : '') . ').'];
            if ($sl['total'] > 0) {
                $lines[] = "Programadas: {$sl['total']} — {$sl['tiempo']} a tiempo, {$sl['tarde']} tarde, {$sl['no']} no realizadas"
                    . ($sl['cumplimiento'] !== null ? " ({$sl['cumplimiento']} % a tiempo)" : '') . '.';
            }
            $lines[] = "Marcas: {$m['scans']['total']}" . ($m['scans']['fuera'] ? ", {$m['scans']['fuera']} fuera de lugar" : '') . ($m['scans']['dudosas'] ? ", {$m['scans']['dudosas']} dudosas" : '')
                . (!empty($m['scans']['fuera_orden']) ? ", {$m['scans']['fuera_orden']} fuera de orden" : '') . '.';
            if ($r['panics']) $lines[] = "Pánicos: {$r['panics']}.";
            if ($m['idle']) $lines[] = 'Sin actividad: ' . implode(', ', array_column(array_slice($m['idle'], 0, 8), 'name')) . (count($m['idle']) > 8 ? '…' : '') . '.';
            $top = array_values(array_filter($m['points'], fn ($p) => $p['problemas'] > 0));
            if ($top) $lines[] = 'Puntos con problemas: ' . implode(', ', array_map(fn ($p) => "{$p['code']} ({$p['problemas']})", array_slice($top, 0, 5))) . '.';
            return ['title' => 'Rondas de ayer · ' . date('d/m', strtotime($r['day'])), 'body' => implode("\n", $lines),
                'url' => '/panel/rondas/metricas?desde=' . $r['day'] . '&hasta=' . $r['day'], 'critical' => false];
        }
        if ($event === 'guard.round_not_started' || $event === 'guard.round_missed') {
            $when = fecha($r['due_at'] ?? null, 'H:i');
            $route = '"' . ($r['route_name'] ?? '') . '"' . (!empty($r['schedule_name']) ? ' (' . $r['schedule_name'] . ')' : '');
            $url = '/panel/rondas/programacion?fecha=' . fecha($r['due_at'] ?? null, 'Y-m-d');
            return $event === 'guard.round_missed'
                ? ['title' => 'Ronda no realizada · ' . ($r['route_name'] ?? ''),
                    'body' => "La ronda {$route} de las {$when} no se hizo: venció el plazo para empezarla. A cargo: " . ($r['guards'] ?? '—') . '.',
                    'url' => $url, 'critical' => false]
                : ['title' => 'Ronda sin empezar · ' . ($r['route_name'] ?? ''),
                    'body' => "La ronda {$route} de las {$when} todavía no empezó (" . (int) ($r['late_minutes'] ?? 0) . ' min tarde). A cargo: '
                        . ($r['guards'] ?? '—') . '. Comunicate con el guardia.',
                    'url' => $url, 'critical' => false];
        }
        if ($event === 'guard.device') {
            $who = $r['user_name'] ?? 'Un guardia';
            $round = ' durante la ronda (' . ($r['route_name'] ?: 'ronda libre') . ')';
            return ($r['_kind'] ?? '') === 'battery'
                ? ['title' => 'Batería por terminarse · ' . $who,
                    'body' => "El celular de {$who} tiene " . (int) ($r['battery'] ?? 0) . " % de batería{$round} y no está cargando. Si se apaga no hay pánico ni recorrido: comunicate con él.",
                    'url' => '/panel/rondas/ronda/' . $r['uuid'], 'critical' => false]
                : ['title' => 'GPS apagado · ' . $who,
                    'body' => "{$who} tiene la ubicación apagada desde las " . fecha($r['since'] ?? $r['started_at'], 'H:i') . "{$round}: no se registra su recorrido ni dónde marca. Pedile que la active.",
                    'url' => '/panel/rondas/ronda/' . $r['uuid'], 'critical' => false];
        }
        if ($event === 'guard.round_late') {
            $who = $r['user_name'] ?? 'Un guardia';
            $missing = (int) ($r['missing'] ?? 0);
            return ['title' => 'Ronda atrasada · ' . $who,
                'body' => "La ronda \"" . ($r['route_name'] ?? '') . "\" de {$who} empezó a las " . fecha($r['started_at'], 'H:i') . ' y sigue en curso: lleva '
                    . (int) ($r['elapsed_minutes'] ?? 0) . ' min (esperados ' . (int) ($r['expected_minutes'] ?? 0) . ').'
                    . ($missing > 0 ? " Faltan {$missing} punto(s)." : ' Ya marcó todos los puntos pero no la finalizó.') . ' Comunicate con él.',
                'url' => '/panel/rondas/ronda/' . $r['uuid'], 'critical' => false];
        }
        if ($event === 'guard.off_site') {
            $who = $r['user_name'] ?? 'Un guardia';
            $point = '«' . ($r['point_code'] ?? '') . ' · ' . ($r['point_name'] ?? '') . '»';
            $round = ' durante la ronda "' . ($r['route_name'] ?: 'ronda libre') . '", a las ' . fecha($r['scanned_at'] ?? $r['started_at'], 'H:i');
            $d = (float) ($r['distance_m'] ?? 0);
            $far = $d >= 1000 ? number_format($d / 1000, 1, ',', '.') . ' km' : (int) round($d) . ' m';
            return ($r['_kind'] ?? '') === 'simulada'
                ? ['title' => '⚠ GPS falso en una ronda · ' . $who,
                    'body' => "{$who} marcó {$point}{$round} con una ubicación simulada (una app que falsea el GPS). No se puede saber dónde estaba: revisá la ronda y hablá con él.",
                    'url' => '/panel/rondas/ronda/' . $r['uuid'], 'critical' => false]
                : ['title' => '⚠ Punto marcado lejos del lugar · ' . $who,
                    'body' => "{$who} marcó {$point}{$round} a {$far} del punto. Puede haber movido el QR o la etiqueta, o marcado desde otro lugar: revisá el recorrido de la ronda. (Si vuelve a pasar en esta ronda no se repite el aviso.)",
                    'url' => '/panel/rondas/ronda/' . $r['uuid'], 'critical' => false];
        }
        if ($event === 'guard.silent') {
            return ['title' => 'Guardia sin señal · ' . ($r['user_name'] ?? ''),
                'body' => ($r['user_name'] ?? 'Un guardia') . ' está en ronda (' . ($r['route_name'] ?: 'ronda libre') . ') y no envía su ubicación desde las '
                    . fecha($r['since'] ?? $r['started_at'], 'H:i') . '. Puede estar sin señal o con el celular apagado: comunicate con él.',
                'url' => '/panel/rondas/ronda/' . $r['uuid'], 'critical' => false];
        }
        $level = (int) ($extra['level'] ?? 0);
        $where = $r['lat'] !== null ? 'Ubicación: https://maps.google.com/?q=' . $r['lat'] . ',' . $r['lng'] . ($r['accuracy_m'] !== null ? ' (±' . (int) $r['accuracy_m'] . ' m)' : '')
            : 'Sin ubicación (el celular no tenía GPS).';
        return ['title' => ($level > 0 ? "⚠ PÁNICO SIN ATENDER (nivel {$level}) · " : '⚠ PÁNICO · ') . ($r['user_name'] ?? 'guardia'),
            'body' => ($r['user_name'] ?? 'Un guardia') . ' activó el botón de pánico a las ' . fecha($r['triggered_at_device'], 'H:i')
                . ($r['route_name'] ? ' durante la ronda "' . $r['route_name'] . '"' : '') . ".
{$where}
Llamalo y marcá la alerta como atendida.",
            'url' => '/panel/rondas/panico/' . $r['uuid'], 'critical' => true];
    }

    /** Turnos de guardias y ausencias (docs/design/turnos-guardias.md). */
    private static function forShift(string $event, array $r): array
    {
        $who = $r['user_name'] ?? 'Un guardia';
        $site = $r['site_name'] ?? '';
        $url = isset($r['uuid']) && str_starts_with($event, 'guard.shift_') && $event !== 'guard.shift_uncovered'
            ? '/panel/rondas/turno/' . $r['uuid'] : '/panel/rondas/turnos/ausencias';
        $span = fn () => fecha($r['start_at'] ?? null, 'd/m H:i') . ' a ' . fecha($r['end_at'] ?? null, 'H:i');
        $map = fn ($lat, $lng) => $lat !== null ? "\nUbicación: https://maps.google.com/?q={$lat},{$lng}" : '';
        $far = function ($d): string {
            $d = (float) $d;
            return $d >= 1000 ? number_format($d / 1000, 1, ',', '.') . ' km' : (int) round($d) . ' m';
        };
        [$title, $body] = match ($event) {
            'guard.shift_not_started' => ["Turno sin empezar · {$who}", "{$who} tenía que empezar el turno en {$site} a las " . fecha($r['start_at'], 'H:i')
                . ' y todavía no lo empezó (' . (int) ($r['late_minutes'] ?? 0) . ' min tarde). Comunicate con él.'],
            'guard.shift_missed' => ["Guardia ausente · {$who}", "{$who} no empezó el turno de {$site} ({$span()}): quedó como ausente sin justificar."],
            'guard.shift_no_end' => ["Turno sin marcar el fin · {$who}", "{$who} no marcó el fin del turno de {$site} ({$span()}). Se cerró solo con la hora programada."],
            'guard.shift_start_outside' => [($r['_kind'] ?? '') === 'simulada' ? "⚠ Turno empezado con GPS falso · {$who}" : "Turno empezado fuera del predio · {$who}",
                ($r['_kind'] ?? '') === 'simulada'
                    ? "{$who} empezó el turno de {$site} con una ubicación simulada (app que falsea el GPS): no se sabe dónde estaba."
                    : "{$who} empezó el turno de {$site} a " . $far($r['distance_m'] ?? 0) . ' del predio.' . $map($r['start_lat'] ?? null, $r['start_lng'] ?? null)],
            'guard.shift_exit' => ["Salió del predio · {$who}", "{$who} salió del predio {$site} a las " . fecha($r['exit']['left_at'] ?? null, 'H:i')
                . '. Motivo: ' . ($r['reason_label'] ?? '') . (!empty($r['exit']['note']) ? ' — ' . $r['exit']['note'] : '') . '.'
                . $map($r['exit']['lat'] ?? null, $r['exit']['lng'] ?? null)],
            'guard.shift_exit_alarm' => ["⚠ Fuera del predio sin motivo · {$who}", "{$who} salió del predio {$site} a las " . fecha($r['exit']['left_at'] ?? null, 'H:i')
                . ' y no indicó el motivo. Comunicate con él.' . $map($r['exit']['lat'] ?? null, $r['exit']['lng'] ?? null)],
            'guard.shift_silent' => ($r['_kind'] ?? '') === 'gps'
                ? ["GPS apagado en turno · {$who}", "{$who} está en turno en {$site} con la ubicación apagada desde las " . fecha($r['since'] ?? null, 'H:i') . '. Pedile que la active.']
                : ["Guardia en turno sin contacto · {$who}", "{$who} está en turno en {$site} y su celular no se comunica desde las " . fecha($r['since'] ?? null, 'H:i')
                    . '. Puede estar sin señal o con el celular apagado: comunicate con él.'],
            'guard.shift_uncovered' => ["Puesto sin cubrir · {$site}", "{$who} tiene ausencia aprobada (" . ($r['type_label'] ?? '') . ') del ' . date('d/m', strtotime((string) $r['date_from']))
                . ' al ' . date('d/m', strtotime((string) $r['date_to'])) . ' y no tiene reemplazo: ' . (int) ($r['slots'] ?? 0) . ' turno(s) sin cubrir desde el '
                . fecha($r['first_start'] ?? null, 'd/m H:i') . '.'],
            'guard.absence_requested' => ["Pedido de ausencia · {$who}", "{$who} pide ausencia (" . ($r['type_label'] ?? '') . ') del ' . date('d/m', strtotime((string) $r['date_from']))
                . ' al ' . date('d/m', strtotime((string) $r['date_to'])) . (!empty($r['reason']) ? ': ' . $r['reason'] : '') . '. Aprobala o rechazala en Turnos → Ausencias.'],
            'guard.absence_decided' => [$r['status'] === 'aprobada' ? 'Ausencia aprobada' : 'Ausencia rechazada',
                'Tu pedido de ausencia del ' . date('d/m', strtotime((string) $r['date_from'])) . ' al ' . date('d/m', strtotime((string) $r['date_to'])) . ' fue '
                . ($r['status'] === 'aprobada' ? 'aprobado' : 'rechazado') . (!empty($r['decision_note']) ? ': ' . $r['decision_note'] : '.')],
            default => [self::EVENTS[$event] ?? $event, ''],
        };
        return ['title' => $title, 'body' => $body, 'url' => $url, 'critical' => false];
    }

    private static function forPpe(string $event, array $e, array $extra): array
    {
        $name=$e['employee_name']??'un empleado'; $sector=$e['sector_name']??'';
        $body=$event==='ppe.due_soon'?'Hay '.(int)($extra['count']??0).' empleado(s) con EPP por vencer · '.$sector.'.':($event==='ppe.overdue'?$name.' tiene EPP vencido.':$name.' tiene EPP pendiente de entrega.');
        return ['title'=>self::EVENTS[$event]??'Seguimiento de EPP','body'=>$body,'url'=>'/panel/epp','critical'=>false];
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
            'permit.gas_alarm' => ["⚠ GASES FUERA DE RANGO · {$num}", "{$where}: " . implode('; ', (array) ($extra['out'] ?? []))
                . ($p['status'] === 'suspendido' ? '. El permiso quedó SUSPENDIDO: evacuar el espacio y ventilar antes de volver a medir.' : '. No ingresar hasta tener una medición en rango.')],
            'permit.suspended' => ["Permiso suspendido · {$num}", "{$types} · {$where}. Motivo: " . ($extra['comment'] ?? $p['status_reason'] ?? '—')],
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
