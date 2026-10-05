<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;

/**
 * Alertas de riesgo inminente: abre la alerta (con escalamiento) y avisa en el mismo request por
 * app, email, push y WhatsApp según las reglas; lo que falle queda en la cola para reintentar.
 */
final class ObservationAlerts
{
    public static function imminent(array $observation): void
    {
        Logger::info('Riesgo inminente reportado', ['observacion' => $observation['uuid'], 'numero' => $observation['number']]);
        \App\Services\Notify\Escalations::open($observation);
    }
}
