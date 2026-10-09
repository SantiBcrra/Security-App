<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\UserError;
use App\Core\Uuid;
use App\Models\NotificationMarks;
use App\Models\Patrols;
use App\Models\Settings;
use App\Services\Notify\Escalations;
use App\Services\Notify\Notifier;
use App\Services\Notify\Recipients;

/**
 * Seguridad del guardia (app Android, entrega 3):
 * - posiciones durante la ronda (lote, también las guardadas sin señal) → recorrido en el mapa;
 * - botón de pánico (datos, cola o constancia del SMS) → aviso CRÍTICO y re-aviso hasta que alguien lo atiende;
 * - "guardia sin señal": ronda en curso sin posiciones en N minutos → aviso una vez por hueco (cron).
 */
final class GuardSafety
{
    public const MAX_POINTS = 200;

    public static function trackSeconds(): int
    {
        return max(15, min(600, (int) (Settings::get('rondas.track_segundos') ?? 60)));
    }

    public static function silentMinutes(): int
    {
        return max(3, min(240, (int) (Settings::get('rondas.minutos_sin_senal') ?? 10)));
    }

    /** @return list<string> teléfonos de pánico (máx. 3), solo dígitos y + */
    public static function panicPhones(): array
    {
        $raw = (string) (Settings::get('guardias.panico_telefonos') ?? '');
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $p) {
            $p = preg_replace('/[^\d+]/', '', $p);
            if (strlen((string) $p) >= 8) {
                $out[] = $p;
            }
        }
        return array_slice(array_values(array_unique($out)), 0, 3);
    }

    /**
     * Lote de posiciones de una ronda propia. Idempotente por uuid de cada punto.
     * @param array $data round_uuid, points [{uuid, at (ISO), lat, lng, accuracy_m, battery}]
     * @return array{saved:int, duplicate:int}
     */
    public static function track(array $data): array
    {
        $round = Patrols::round((string) ($data['round_uuid'] ?? ''));
        if (!$round || (int) $round['user_id'] !== (int) UserAuth::user()['id']) {
            throw new UserError('La ronda no está disponible.');
        }
        $points = array_slice((array) ($data['points'] ?? []), 0, self::MAX_POINTS);
        $db = DB::tenant();
        $insert = $db->prepare('INSERT IGNORE INTO patrol_tracks (uuid, round_id, user_id, recorded_at_device, received_at, lat, lng, accuracy_m, battery, created_at)
            VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, ?, ?, UTC_TIMESTAMP())');
        $saved = 0;
        $last = null;
        foreach ($points as $p) {
            if (!is_array($p) || !Uuid::isValid((string) ($p['uuid'] ?? '')) || !is_numeric($p['lat'] ?? null) || !is_numeric($p['lng'] ?? null)) {
                continue;
            }
            $lat = (float) $p['lat'];
            $lng = (float) $p['lng'];
            if (abs($lat) > 90 || abs($lng) > 180) {
                continue;
            }
            try {
                $at = Patrols::deviceTime($p['at'] ?? null);
            } catch (UserError) {
                continue;
            }
            // Después del fin de la ronda no se guarda (el celular dejó de seguirlo; un rezago no vale).
            if ($round['finished_at'] !== null && $at > $round['finished_at']) {
                continue;
            }
            $acc = is_numeric($p['accuracy_m'] ?? null) ? min(max((float) $p['accuracy_m'], 0.0), 999999999.0) : null;
            $battery = is_numeric($p['battery'] ?? null) ? max(0, min(100, (int) $p['battery'])) : null;
            $insert->execute([strtolower((string) $p['uuid']), (int) $round['id'], (int) $round['user_id'], $at, $lat, $lng, $acc, $battery]);
            $saved += $insert->rowCount();
            $last = max($last ?? $at, $at);
        }
        if ($last !== null) {
            $db->prepare('UPDATE patrol_rounds SET last_track_at = GREATEST(COALESCE(last_track_at, ?), ?), updated_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([$last, $last, (int) $round['id']]);
        }
        return ['saved' => $saved, 'duplicate' => count($points) - $saved];
    }

    public static function tracks(int $roundId): array
    {
        $stmt = DB::tenant()->prepare('SELECT recorded_at_device, lat, lng, accuracy_m, battery FROM patrol_tracks WHERE round_id = ? ORDER BY recorded_at_device, id');
        $stmt->execute([$roundId]);
        return $stmt->fetchAll();
    }

    /**
     * Pánico. Idempotente por uuid del celular: la app lo manda directo y además lo deja en la cola por si se corta.
     * @param array $data uuid, at (ISO), lat, lng, accuracy_m, round_uuid?, sms_sent?
     * @param string $via datos | cola
     * @return array{panic: array, duplicate: bool}
     */
    public static function panic(array $data, string $via): array
    {
        $uuid = strtolower((string) ($data['uuid'] ?? ''));
        if (!Uuid::isValid($uuid)) {
            throw new UserError('Falta el identificador de la alerta.');
        }
        if (($existing = self::findPanic($uuid)) !== null) {
            if (!empty($data['sms_sent']) && !(int) $existing['sms_sent']) {
                DB::tenant()->prepare('UPDATE panic_alerts SET sms_sent = 1, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([(int) $existing['id']]);
            }
            return ['panic' => self::findPanic($uuid), 'duplicate' => true];
        }
        $user = UserAuth::user();
        $round = !empty($data['round_uuid']) ? Patrols::round((string) $data['round_uuid']) : null;
        if ($round !== null && (int) $round['user_id'] !== (int) $user['id']) {
            $round = null;
        }
        $lat = is_numeric($data['lat'] ?? null) && abs((float) $data['lat']) <= 90 ? (float) $data['lat'] : null;
        $lng = is_numeric($data['lng'] ?? null) && abs((float) $data['lng']) <= 180 ? (float) $data['lng'] : null;
        $at = Patrols::deviceTime($data['at'] ?? null);
        DB::tenant()->prepare('INSERT INTO panic_alerts (uuid, user_id, round_id, lat, lng, accuracy_m, triggered_at_device, received_at, via, sms_sent, level,
            next_escalation_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, ?, 0, UTC_TIMESTAMP() + INTERVAL ? MINUTE, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute([$uuid, (int) $user['id'], $round['id'] ?? null, $lat, $lng,
                is_numeric($data['accuracy_m'] ?? null) ? min(max((float) $data['accuracy_m'], 0.0), 999999999.0) : null,
                $at, $via === 'cola' ? 'cola' : 'datos', empty($data['sms_sent']) ? 0 : 1, Escalations::minutes()]);
        $panic = self::findPanic($uuid);
        Audit::tenant('guard.panic', 'panic_alert', $uuid, null, ['ubicacion' => $lat !== null ? "{$lat},{$lng}" : null, 'via' => $via]);
        Notifier::dispatch('guard.panic', $panic);
        return ['panic' => $panic, 'duplicate' => false];
    }

    public static function findPanic(string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare(self::PANIC_SELECT . ' WHERE p.uuid = ?');
        $stmt->execute([$uuid]);
        return $stmt->fetch() ?: null;
    }

    /** Sin atender primero, después las más recientes. */
    public static function panics(int $limit = 50): array
    {
        return DB::tenant()->query(self::PANIC_SELECT . ' ORDER BY (p.acked_at IS NULL) DESC, p.triggered_at_device DESC LIMIT ' . max(1, $limit))->fetchAll();
    }

    public static function openPanics(): array
    {
        return DB::tenant()->query(self::PANIC_SELECT . ' WHERE p.acked_at IS NULL ORDER BY p.triggered_at_device DESC')->fetchAll();
    }

    /** "Atendido": corta el re-aviso. Queda quién y qué se hizo. */
    /** Estado de atención de varios pánicos (para la lista de avisos de la app). @return array<string, ?string> uuid => quién atendió (null = sin atender) */
    public static function ackState(array $uuids): array
    {
        $uuids = array_values(array_unique(array_filter($uuids)));
        if ($uuids === []) {
            return [];
        }
        $st = DB::tenant()->prepare('SELECT uuid, acked_at, acked_name FROM panic_alerts WHERE uuid IN (' . implode(',', array_fill(0, count($uuids), '?')) . ')');
        $st->execute($uuids);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[$r['uuid']] = $r['acked_at'] !== null ? (string) ($r['acked_name'] ?: 'alguien') : null;
        }
        return $out;
    }

    public static function ack(array $panic, string $comment): ?string
    {
        if ($panic['acked_at'] !== null) {
            return null;
        }
        if (mb_strlen(trim($comment)) < 3) {
            return 'Contá qué se hizo (por ejemplo: "se llamó al guardia, está bien").';
        }
        $user = UserAuth::user();
        DB::tenant()->prepare('UPDATE panic_alerts SET acked_at = UTC_TIMESTAMP(), acked_by = ?, acked_name = ?, ack_comment = ?, next_escalation_at = NULL,
            updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([(int) $user['id'], $user['name'], mb_substr(trim($comment), 0, 255), (int) $panic['id']]);
        Audit::tenant('guard.panic_ack', 'panic_alert', $panic['uuid'], null, ['comentario' => trim($comment)]);
        return null;
    }

    /** Cron: re-aviso de pánicos sin atender y guardias sin señal. @return array{escalated:int, silent:int} */
    public static function run(?int $now = null): array
    {
        $now ??= time();
        $nowSql = gmdate('Y-m-d H:i:s', $now);
        $out = ['escalated' => 0, 'silent' => 0];
        $previous = UserAuth::user();
        UserAuth::setCurrent(null);
        try {
            $due = DB::tenant()->prepare(self::PANIC_SELECT . ' WHERE p.acked_at IS NULL AND p.next_escalation_at IS NOT NULL AND p.next_escalation_at <= ?');
            $due->execute([$nowSql]);
            foreach ($due->fetchAll() as $p) {
                $level = (int) $p['level'] + 1;
                $next = $level < Escalations::MAX_LEVEL ? gmdate('Y-m-d H:i:s', $now + Escalations::minutes() * 60) : null;
                DB::tenant()->prepare('UPDATE panic_alerts SET level = ?, next_escalation_at = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$level, $next, (int) $p['id']]);
                Notifier::dispatch('guard.panic', ['level' => $level] + $p, ['level' => $level], Recipients::managers());
                $out['escalated']++;
            }
            // Rondas en curso de las últimas 12 h sin posición (o sin ninguna desde el inicio) en N minutos.
            $limit = gmdate('Y-m-d H:i:s', $now - self::silentMinutes() * 60);
            $stmt = DB::tenant()->prepare("SELECT r.id, r.uuid, r.user_id, r.started_at, r.last_track_at, u.name AS user_name, pr.name AS route_name
                FROM patrol_rounds r JOIN users u ON u.id = r.user_id LEFT JOIN patrol_routes pr ON pr.id = r.route_id
                WHERE r.status = 'en_curso' AND r.started_at > ? AND COALESCE(r.last_track_at, r.started_at) < ?");
            $stmt->execute([gmdate('Y-m-d H:i:s', $now - 12 * 3600), $limit]);
            foreach ($stmt->fetchAll() as $r) {
                $since = $r['last_track_at'] ?? $r['started_at'];
                if (NotificationMarks::claim("guard_silent:{$r['id']}:{$since}")) {
                    Notifier::dispatch('guard.silent', $r + ['since' => $since]);
                    $out['silent']++;
                }
            }
        } finally {
            UserAuth::setCurrent($previous);
        }
        return $out;
    }

    private const PANIC_SELECT = 'SELECT p.*, u.name AS user_name, u.dni AS user_dni, r.uuid AS round_uuid, pr.name AS route_name
        FROM panic_alerts p JOIN users u ON u.id = p.user_id LEFT JOIN patrol_rounds r ON r.id = p.round_id LEFT JOIN patrol_routes pr ON pr.id = r.route_id';
}
