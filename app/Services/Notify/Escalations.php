<?php
declare(strict_types=1);

namespace App\Services\Notify;

use App\Models\Alerts;
use App\Models\ObservationEvents;
use App\Models\Observations;
use App\Models\Settings;
use App\Services\ObservationWorkflow;
use App\Services\UserAuth;

/**
 * Alertas críticas: se abren con el riesgo inminente; si nadie confirma "Recibido" en X minutos
 * suben de nivel (1 y 2) avisando a todos los responsables SyH y administradores por todos los
 * canales. Confirmar o cerrar la observación corta el escalamiento.
 */
final class Escalations
{
    public const MAX_LEVEL = 2;

    public static function minutes(): int
    {
        return max(1, (int) (Settings::get('notif.escalation_minutes') ?? 15));
    }

    /** Riesgo inminente: crea la alerta y avisa según las reglas. */
    public static function open(array $obs): void
    {
        $alertId = Alerts::create((int) $obs['id'], self::minutes());
        Notifier::dispatch('observation.imminent', $obs, ['alert_id' => $alertId]);
    }

    /** "Recibido". @return ?string error */
    public static function ack(array $alert, array $user): ?string
    {
        if ($alert['acked_at'] !== null) {
            return null; // ya estaba confirmada: no es un error
        }
        Alerts::update((int) $alert['id'], ['acked_at' => gmdate('Y-m-d H:i:s'), 'acked_by' => (int) $user['id'], 'acked_name' => $user['name'], 'next_escalation_at' => null]);
        ObservationEvents::add((int) $alert['observation_id'], 'alert_ack', [
            'comment' => 'Alerta de riesgo inminente confirmada (nivel ' . $alert['level'] . ').', 'user_id' => (int) $user['id'], 'actor_name' => $user['name'],
        ]);
        return null;
    }

    /** Al cerrar o descartar la observación, la alerta deja de escalar. */
    public static function closeFor(int $observationId): void
    {
        if ($alert = Alerts::openForObservation($observationId)) {
            Alerts::update((int) $alert['id'], ['closed_at' => gmdate('Y-m-d H:i:s'), 'next_escalation_at' => null]);
        }
    }

    /** Lo corre el cron. @return int alertas escaladas */
    public static function run(): int
    {
        $count = 0;
        foreach (Alerts::dueForEscalation() as $alert) {
            $obs = Observations::findById((int) $alert['observation_id']);
            if ($obs === null || !ObservationWorkflow::isOpen($obs['status'])) {
                Alerts::update((int) $alert['id'], ['closed_at' => gmdate('Y-m-d H:i:s'), 'next_escalation_at' => null]);
                continue;
            }
            $level = (int) $alert['level'] + 1;
            Alerts::escalate((int) $alert['id'], $level, $level < self::MAX_LEVEL ? self::minutes() : null);
            ObservationEvents::add((int) $obs['id'], 'escalation', [
                'comment' => "Nadie confirmó la alerta: escalada a nivel {$level} (responsables SyH y administradores).", 'actor_name' => 'Sistema',
            ]);
            $previous = UserAuth::user();
            UserAuth::setCurrent(null);
            Notifier::dispatch('observation.escalated', $obs, ['level' => $level, 'alert_id' => (int) $alert['id']], Recipients::managers());
            UserAuth::setCurrent($previous);
            $count++;
        }
        return $count;
    }
}
