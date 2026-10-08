<?php
declare(strict_types=1);

namespace App\Services\Notify;

use App\Models\InspectionSchedules;
use App\Models\NotificationMarks;
use App\Services\ActionService;
use App\Services\InspectionPlanner;

/**
 * Cron de inspecciones: genera las programadas y avisa una sola vez cada cosa (NotificationMarks):
 * el día que vence ("para hoy") y, si quedó sin hacer, al día siguiente ("vencida").
 */
final class InspectionReminders
{
    /** @return array{generated:int, due:int, overdue:int} */
    public static function run(?string $today = null): array
    {
        $today ??= ActionService::today();
        $out = ['generated' => InspectionPlanner::generate($today), 'due' => 0, 'overdue' => 0];
        foreach (InspectionSchedules::search(['status' => 'pendiente', 'due_on_from' => $today, 'due_on_to' => $today], null, 2000) as $s) {
            if (NotificationMarks::claim("insp_due:{$s['id']}")) {
                Notifier::dispatch('inspection.due', $s);
                $out['due']++;
            }
        }
        $week = (new \DateTimeImmutable($today))->modify('-7 days')->format('Y-m-d');
        foreach (InspectionSchedules::search(['status' => 'pendiente', 'due_on_lt' => $today, 'due_on_from' => $week], null, 2000) as $s) {
            if (NotificationMarks::claim("insp_late:{$s['id']}")) {
                Notifier::dispatch('inspection.overdue', $s);
                $out['overdue']++;
            }
        }
        return $out;
    }
}
