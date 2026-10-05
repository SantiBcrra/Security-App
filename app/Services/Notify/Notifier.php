<?php
declare(strict_types=1);

namespace App\Services\Notify;

use App\Core\DB;
use App\Core\Logger;
use App\Core\View;
use App\Models\Employees;
use App\Models\NotificationPrefs;
use App\Models\NotificationQueue;
use App\Models\NotificationRules;
use App\Models\Notifications;
use App\Models\Observations;
use App\Models\Sectors;
use App\Services\UserAuth;

/**
 * Despacha un evento según las reglas de la empresa: crea los avisos en la app y encola email,
 * push y WhatsApp. Los críticos se intentan enviar en el mismo request (lo que falle queda en cola).
 * Nunca rompe la operación que lo dispara: los errores se loguean.
 */
final class Notifier
{
    /**
     * @param array $obs fila de Observations (con nombres)
     * @param ?array $forceRecipients usuarios fijos (escalamiento) en vez de las reglas
     * @return array{users:int, queued:int}
     */
    public static function dispatch(string $event, array $obs, array $extra = [], ?array $forceRecipients = null, ?array $forceChannels = null): array
    {
        try {
            return self::run($event, $obs, $extra, $forceRecipients, $forceChannels);
        } catch (\Throwable $e) {
            Logger::error('Notificaciones: falló el despacho', ['event' => $event, 'error' => $e->getMessage()]);
            return ['users' => 0, 'queued' => 0];
        }
    }

    private static function run(string $event, array $obs, array $extra, ?array $forceRecipients, ?array $forceChannels): array
    {
        $obs = Observations::findById((int) $obs['id']) ?? $obs; // siempre con los nombres actualizados
        $message = Messages::for($event, $obs, $extra);
        $targets = []; // user_id => [user, canales]
        if ($forceRecipients !== null) {
            foreach ($forceRecipients as $id => $user) {
                $targets[$id] = [$user, $forceChannels ?? ['app', 'email', 'push', 'whatsapp']];
            }
        } else {
            foreach (NotificationRules::activeFor($event) as $rule) {
                if (!self::ruleMatches($rule, $obs)) {
                    continue;
                }
                foreach (Recipients::resolve($rule['recipients'], $obs) as $id => $user) {
                    $targets[$id] = [$user, array_values(array_unique(array_merge($targets[$id][1] ?? [], $rule['channels'])))];
                }
            }
        }
        // Quien hizo la acción no necesita que se le avise (salvo críticos: igual les llega a los demás).
        $actor = UserAuth::user();
        if ($actor !== null && !$message['critical']) {
            unset($targets[(int) $actor['id']]);
        }

        $queued = [];
        foreach ($targets as $userId => [$user, $channels]) {
            $prefs = $message['critical'] ? [] : NotificationPrefs::forUser($userId);
            foreach ($channels as $channel) {
                if (!Channels::enabled($channel) || ($prefs[$channel] ?? true) === false) {
                    continue;
                }
                if ($channel === 'app') {
                    Notifications::create($userId, $event, $message['title'], $message['body'], $message['url'], $message['critical'], $extra['alert_id'] ?? null);
                    continue;
                }
                foreach (self::addresses($channel, $user) as $address) {
                    $queued[] = NotificationQueue::add([
                        'channel' => $channel, 'user_id' => $userId, 'to_address' => $address,
                        'subject' => $message['title'], 'body_text' => self::text($message),
                        'body_html' => $channel === 'email' ? self::html($message, $user) : null,
                        'payload' => json_encode(['url' => $message['url'], 'event' => $event, 'observation' => $obs['uuid'],
                            'app_url' => absolute_url('/movil') . '#/observacion/' . $obs['uuid']]),
                        'event' => $event, 'entity_uuid' => $obs['uuid'], 'is_critical' => $message['critical'] ? 1 : 0,
                    ]);
                }
            }
        }
        if ($message['critical'] && $queued) {
            QueueRunner::run(20, 15, $queued); // en el mismo request, con timeouts cortos
        }
        return ['users' => count($targets), 'queued' => count($queued)];
    }

    private static function ruleMatches(array $rule, array $obs): bool
    {
        if ($rule['min_severity_level'] !== null && (int) ($obs['severity_level'] ?? 0) < (int) $rule['min_severity_level']) {
            return false;
        }
        if ($rule['sector_id'] !== null) {
            return $obs['sector_id'] !== null && in_array((int) $obs['sector_id'], Sectors::withDescendants([(int) $rule['sector_id']]), true);
        }
        return true;
    }

    /** @return list<string> */
    private static function addresses(string $channel, array $user): array
    {
        return match ($channel) {
            'email'    => $user['email'] ? [$user['email']] : [],
            'push'     => self::pushTokens((int) $user['id']),
            'whatsapp' => ($phone = self::phone((int) $user['id'])) ? [$phone] : [],
            default    => [],
        };
    }

    /** Destinos push: suscripciones web (PWA, "web:{id}") y tokens Expo de una futura app nativa. */
    private static function pushTokens(int $userId): array
    {
        $web = array_map(fn ($s) => 'web:' . $s['id'], \App\Models\PushSubscriptions::forUser($userId));
        $stmt = DB::tenant()->prepare('SELECT DISTINCT push_token FROM user_devices WHERE user_id = ? AND push_token IS NOT NULL
            AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()');
        $stmt->execute([$userId]);
        return array_merge($web, $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** Teléfono del empleado vinculado al usuario, en formato internacional para WhatsApp (Argentina: 549 + área + número). */
    private static function phone(int $userId): ?string
    {
        $emp = Employees::findBy('user_id', $userId);
        $digits = preg_replace('/\D/', '', (string) ($emp['phone'] ?? ''));
        if ($digits === '') {
            return null;
        }
        $digits = ltrim($digits, '0');
        if (str_starts_with($digits, '54')) {
            return $digits;
        }
        $digits = preg_replace('/^(\d{2,4})15/', '$1', $digits); // saca el "15" de celulares locales
        return '549' . $digits;
    }

    private static function text(array $m): string
    {
        return $m['body'] . "\n\nVer en el sistema: " . absolute_url($m['url']);
    }

    private static function html(array $m, array $user): string
    {
        return View::render('emails/notification', ['m' => $m, 'user' => $user, 'link' => absolute_url($m['url'])], null);
    }
}
