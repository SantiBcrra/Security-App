<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;

/**
 * Alertas de riesgo inminente. Hoy: queda registrada y destacada en el panel.
 * Etapa 7: acá se envían push / WhatsApp / email en el mismo request (con timeouts cortos) y lo
 * que falle queda en la cola para reintentar.
 */
final class ObservationAlerts
{
    public static function imminent(array $observation): void
    {
        Logger::info('Riesgo inminente reportado', ['observacion' => $observation['uuid'], 'numero' => $observation['number']]);
    }
}
