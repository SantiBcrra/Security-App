<?php
declare(strict_types=1);

namespace App\Services\Notify;

use App\Models\Observations;
use App\Services\ObservationWorkflow;

/** Textos de cada evento (título corto para la app/push y cuerpo para email/WhatsApp). */
final class Messages
{
    public const EVENTS = [
        'observation.imminent'  => 'Riesgo inminente reportado',
        'observation.escalated' => 'Riesgo inminente sin confirmar (escalamiento)',
        'observation.created'   => 'Observación nueva',
        'observation.assigned'  => 'Acción asignada',
        'observation.overdue'   => 'Acción vencida',
        'observation.closed'    => 'Observación cerrada',
    ];

    /** Eventos críticos: llegan siempre (no se pueden silenciar) y se envían en el momento. */
    public const CRITICAL = ['observation.imminent', 'observation.escalated'];

    /** @return array{title:string, body:string, url:string, critical:bool} */
    public static function for(string $event, array $obs, array $extra = []): array
    {
        $num = Observations::format((int) $obs['number']);
        $where = trim(($obs['sector_name'] ?? '') . ($obs['equipment_code'] ? ' · ' . $obs['equipment_code'] : ''), ' ·');
        $desc = mb_strimwidth((string) $obs['description'], 0, 220, '…');
        [$title, $body] = match ($event) {
            'observation.imminent'  => ["⚠ RIESGO INMINENTE · {$num}", "{$where}: {$desc}\nVerificá que la tarea esté frenada y confirmá que recibiste el aviso."],
            'observation.escalated' => ["⚠ SIN CONFIRMAR (nivel {$extra['level']}) · {$num}", "Nadie confirmó el riesgo inminente reportado en {$where}.\n{$desc}"],
            'observation.created'   => ["Observación {$obs['severity_name']} · {$num}", "{$where}: {$desc}"],
            'observation.assigned'  => ["Te asignaron una acción · {$num}", trim(($obs['action_text'] ?? '') . "\nFecha compromiso: "
                . ($obs['action_due_on'] ? date('d/m/Y', strtotime($obs['action_due_on'])) : '—') . "\n{$where}: {$desc}")],
            'observation.overdue'   => ["Acción vencida · {$num}", 'Venció el ' . date('d/m/Y', strtotime((string) $obs['action_due_on'])) . ': '
                . ($obs['action_text'] ?? '') . "\nResponsable: " . ($obs['assigned_name'] ?? '—')],
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
}
