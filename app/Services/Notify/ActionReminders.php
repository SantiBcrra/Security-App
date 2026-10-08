<?php
declare(strict_types=1);

namespace App\Services\Notify;

use App\Models\Actions;
use App\Models\NotificationMarks;
use App\Models\Settings;
use App\Services\ActionService;

/**
 * Recordatorios de acciones CAPA (los corre el cron por empresa). Cada aviso se manda una sola vez
 * gracias a NotificationMarks, aunque el cron corra muchas veces por día:
 * - por vencer: una vez, N días antes (setting acciones.aviso_dias, 3);
 * - vencida: una vez por día mientras siga vencida;
 * - escalamiento: una vez, al cumplir M días de atraso (acciones.escalar_dias, 3);
 * - verificación atrasada: una vez por día.
 * Cambiar la fecha límite genera marcas nuevas (la fecha va en la clave).
 */
final class ActionReminders
{
    /** @return array{due_soon:int, overdue:int, escalated:int, verify_overdue:int} */
    public static function run(?string $today = null): array
    {
        $today ??= ActionService::today();
        $soonDays = max(1, (int) Settings::get('acciones.aviso_dias', '3'));
        $escalateDays = max(1, (int) Settings::get('acciones.escalar_dias', '3'));
        $limit = (new \DateTimeImmutable($today))->modify("+{$soonDays} days")->format('Y-m-d');
        $out = ['due_soon' => 0, 'overdue' => 0, 'escalated' => 0, 'verify_overdue' => 0];

        foreach (Actions::search(['status' => Actions::OPEN], null, 2000) as $a) {
            $days = self::daysBetween($today, $a['due_on']); // >0: faltan; <0: atraso
            if ($days >= 0 && $a['due_on'] <= $limit) {
                if (NotificationMarks::claim("act_soon:{$a['id']}:{$a['due_on']}")) {
                    Notifier::dispatch('action.due_soon', $a, ['days' => $days]);
                    $out['due_soon']++;
                }
                continue;
            }
            if ($days < 0) {
                $late = -$days;
                if (NotificationMarks::claim("act_late:{$a['id']}:{$today}")) {
                    Notifier::dispatch('action.overdue', $a, ['days' => $late]);
                    $out['overdue']++;
                }
                if ($late >= $escalateDays && NotificationMarks::claim("act_esc:{$a['id']}:{$a['due_on']}")) {
                    Notifier::dispatch('action.overdue_escalated', $a, ['days' => $late]);
                    $out['escalated']++;
                }
            }
        }
        foreach (Actions::search(['status' => 'cerrada'], null, 2000) as $a) {
            if ($a['verify_due_on'] !== null && $a['verify_due_on'] < $today && NotificationMarks::claim("act_verify:{$a['id']}:{$today}")) {
                Notifier::dispatch('action.verify_overdue', $a);
                $out['verify_overdue']++;
            }
        }
        return $out;
    }

    private static function daysBetween(string $from, string $to): int
    {
        return (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->format('%r%a');
    }
}
