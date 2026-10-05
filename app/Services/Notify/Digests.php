<?php
declare(strict_types=1);

namespace App\Services\Notify;

use App\Core\Tenant;
use App\Core\View;
use App\Models\NotificationMarks;
use App\Models\NotificationPrefs;
use App\Models\NotificationQueue;
use App\Models\Observations;
use App\Models\Settings;

/**
 * Recordatorios de acciones vencidas (una vez por día por observación) y resúmenes por email
 * (diario y semanal) a responsables SyH y administradores. Corre desde el cron.
 */
final class Digests
{
    public const HOUR = 8; // hora local desde la que se mandan los resúmenes del día

    /** @return int recordatorios enviados */
    public static function overdue(): int
    {
        $today = self::localDate();
        $count = 0;
        foreach (Observations::search(['status' => 'accion_asignada'], null, 500) as $obs) {
            if ($obs['action_due_on'] !== null && $obs['action_due_on'] < $today && NotificationMarks::claim("overdue:{$obs['id']}:{$today}")) {
                Notifier::dispatch('observation.overdue', $obs);
                $count++;
            }
        }
        return $count;
    }

    /** @return list<string> resúmenes generados ('daily', 'weekly') */
    public static function run(?\DateTimeImmutable $now = null): array
    {
        $local = ($now ?? new \DateTimeImmutable('now'))->setTimezone(new \DateTimeZone(Tenant::timezone() ?? 'UTC'));
        if ((int) $local->format('G') < self::HOUR) {
            return [];
        }
        $done = [];
        if (Settings::get('notif.digest_daily', '1') === '1' && NotificationMarks::claim('digest:daily:' . $local->format('Y-m-d'))) {
            self::send('daily', $local->modify('-1 day'));
            $done[] = 'daily';
        }
        if ($local->format('N') === '1' && Settings::get('notif.digest_weekly', '1') === '1' && NotificationMarks::claim('digest:weekly:' . $local->format('o-W'))) {
            self::send('weekly', $local->modify('-7 days'));
            $done[] = 'weekly';
        }
        return $done;
    }

    private static function send(string $kind, \DateTimeImmutable $sinceLocal): void
    {
        $since = $sinceLocal->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $pending = ['status' => ['abierta', 'en_analisis', 'accion_asignada']];
        $data = [
            'kind'     => $kind,
            'tenant'   => Tenant::current(),
            'new'      => Observations::count(['from' => $since], null),
            'pending'  => Observations::count($pending, null),
            'imminent' => Observations::count($pending + ['imminent' => 1], null),
            'overdue'  => array_values(array_filter(Observations::search(['status' => 'accion_asignada'], null, 200),
                fn ($o) => $o['action_due_on'] !== null && $o['action_due_on'] < self::localDate())),
            'latest'   => Observations::search(['from' => $since], null, 15),
        ];
        $subject = ($kind === 'daily' ? 'Resumen diario' : 'Resumen semanal') . ' de seguridad · ' . $data['tenant']['name'];
        foreach (Recipients::managers() as $userId => $user) {
            if (!$user['email'] || (NotificationPrefs::forUser($userId)['email'] ?? true) === false) {
                continue;
            }
            NotificationQueue::add([
                'channel' => 'email', 'user_id' => $userId, 'to_address' => $user['email'], 'subject' => $subject,
                'body_text' => "Observaciones nuevas: {$data['new']}\nPendientes: {$data['pending']}\nRiesgos inminentes sin cerrar: {$data['imminent']}\n"
                    . 'Acciones vencidas: ' . count($data['overdue']) . "\n\n" . absolute_url('/panel/observaciones'),
                'body_html' => View::render('emails/digest', $data + ['user' => $user], null),
                'event' => 'digest.' . $kind,
            ]);
        }
    }

    private static function localDate(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(Tenant::timezone() ?? 'UTC')))->format('Y-m-d');
    }
}
