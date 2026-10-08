<?php
declare(strict_types=1);

namespace App\Services\Notify;

use App\Core\Tenant;
use App\Models\Incidents;
use App\Models\NotificationMarks;
use App\Models\Settings;
use App\Services\IncidentService;

/**
 * Recordatorios de incidentes (cron, una sola vez cada uno con NotificationMarks):
 * - investigación obligatoria sin empezar a los N días (incidentes.dias_investigacion, 3);
 * - lesionado sin N° de siniestro de la ART a las N horas (incidentes.horas_art, 48; la empresa lo ajusta a su ART);
 * - los lunes, resumen de personas de baja sin alta.
 */
final class IncidentReminders
{
    /** @return array{investigation:int, art:int, leaves:int} */
    public static function run(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $out = ['investigation' => 0, 'art' => 0, 'leaves' => 0];
        $days = max(1, (int) Settings::get('incidentes.dias_investigacion', '3'));
        $types = array_keys(array_filter(IncidentService::TYPES, fn ($t) => $t['investigation']));
        foreach (Incidents::pendingInvestigation($types, $now->modify("-{$days} days")->format('Y-m-d H:i:s')) as $i) {
            if (NotificationMarks::claim("inc_inv:{$i['id']}")) {
                Notifier::dispatch('incident.investigation_overdue', $i, ['days' => $days]);
                $out['investigation']++;
            }
        }
        $hours = max(1, (int) Settings::get('incidentes.horas_art', '48'));
        foreach (Incidents::missingArtCase($now->modify("-{$hours} hours")->format('Y-m-d H:i:s')) as $i) {
            if (NotificationMarks::claim("inc_art:{$i['id']}")) {
                Notifier::dispatch('incident.art_pending', $i, ['hours' => $hours]);
                $out['art']++;
            }
        }
        $local = $now->setTimezone(new \DateTimeZone(Tenant::timezone() ?? 'UTC'));
        if ($local->format('N') === '1' && NotificationMarks::claim('inc_leaves:' . $local->format('o-W'))) {
            $count = count(Incidents::openLeaves());
            if ($count > 0) {
                Notifier::dispatch('incident.open_leaves', ['id' => 0], ['count' => $count]);
                $out['leaves'] = $count;
            }
        }
        return $out;
    }
}
