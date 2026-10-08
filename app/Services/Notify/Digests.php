<?php
declare(strict_types=1);

namespace App\Services\Notify;

use App\Core\Tenant;
use App\Core\View;
use App\Models\Actions;
use App\Models\NotificationMarks;
use App\Models\NotificationPrefs;
use App\Models\NotificationQueue;
use App\Models\Observations;
use App\Models\Settings;
use App\Services\ActionService;

/**
 * Resúmenes por email (diario y semanal) a responsables SyH y administradores. Corre desde el cron.
 * Los recordatorios de acciones están en ActionReminders.
 */
final class Digests
{
    public const HOUR = 8; // hora local desde la que se mandan los resúmenes del día

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
            'overdue'  => Actions::search(['overdue' => ActionService::today()], null, 200),
            'toVerify' => Actions::count(['status' => 'cerrada'], null),
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
                    . 'Acciones vencidas: ' . count($data['overdue']) . "\nAcciones para verificar: {$data['toVerify']}\n\n" . absolute_url('/panel/acciones/tablero'),
                'body_html' => View::render('emails/digest', $data + ['user' => $user], null),
                'event' => 'digest.' . $kind,
            ]);
        }
    }
}
