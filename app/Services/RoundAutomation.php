<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Uuid;
use App\Models\NotificationMarks;
use App\Models\Patrols;
use App\Models\Settings;
use App\Services\Notify\Notifier;

/**
 * Automatizaciones de rondas que corre el cron (diseño: docs/design/automatizaciones-rondas.md).
 * Primero avisa la app al guardia (sin señal también); si sigue igual después de OTRO plazo igual, avisa a SyH y supervisores.
 * - Fase 2, sin progreso: la ronda lleva 2×N min sin marcar ningún punto (N = rondas.sin_progreso_minutos) y el celular SÍ
 *   está mandando su posición (si no manda nada ya avisa "guardia sin señal": así no se confunde falta de señal con no marcar).
 * - Fase 4, atrasada: ronda de una ruta con duración esperada E que sigue en curso a E + 2×T (T = rondas.atraso_minutos).
 * - Abandonada: en curso hace más de ABANDON_HOURS → la cierra el sistema (completa / incompleta) para que no quede abierta.
 * Cada alerta queda en patrol_round_alerts (se ve en el detalle de la ronda).
 */
final class RoundAutomation
{
    public const ABANDON_HOURS = 12;
    public const LABELS = ['no_progress' => 'Sin marcar puntos', 'late' => 'Ronda atrasada', 'abandoned' => 'Cerrada por el sistema (abandonada)',
        'battery' => 'Batería por terminarse', 'gps_off' => 'GPS apagado'];

    public static function enabled(): bool
    {
        return Settings::get('rondas.automatizaciones', '1') === '1';
    }

    public static function noProgressMinutes(): int
    {
        return max(1, min(240, (int) Settings::get('rondas.sin_progreso_minutos', '10')));
    }

    public static function lateMinutes(): int
    {
        return max(1, min(240, (int) Settings::get('rondas.atraso_minutos', '10')));
    }

    /** @return array{no_progress:int, late:int, abandoned:int} */
    public static function run(?int $now = null): array
    {
        $out = ['no_progress' => 0, 'late' => 0, 'abandoned' => 0, 'device' => 0];
        if (!self::enabled()) {
            return $out;
        }
        $now ??= time();
        $previous = UserAuth::user();
        UserAuth::setCurrent(null);
        try {
            $stmt = DB::tenant()->prepare("SELECT r.*, u.name AS user_name, pr.name AS route_name, pr.expected_minutes,
                    (SELECT MAX(s.scanned_at_device) FROM patrol_scans s WHERE s.round_id = r.id) AS last_scan_at
                FROM patrol_rounds r JOIN users u ON u.id = r.user_id LEFT JOIN patrol_routes pr ON pr.id = r.route_id
                WHERE r.status = 'en_curso'");
            $stmt->execute();
            foreach ($stmt->fetchAll() as $r) {
                $started = strtotime($r['started_at'] . ' UTC');
                if ($now - $started >= self::ABANDON_HOURS * 3600) {
                    self::abandon($r, $now);
                    $out['abandoned']++;
                    continue;
                }
                if (self::checkNoProgress($r, $now)) $out['no_progress']++;
                if (self::checkLate($r, $now, $started)) $out['late']++;
                $out['device'] += self::checkDevice($r, $now);
            }
        } finally {
            UserAuth::setCurrent($previous);
        }
        return $out;
    }

    private static function checkNoProgress(array $r, int $now): bool
    {
        $last = $r['last_scan_at'] ?? $r['started_at'];
        $idle = $now - strtotime($last . ' UTC');
        $alive = $r['last_track_at'] !== null && $now - strtotime($r['last_track_at'] . ' UTC') <= GuardSafety::silentMinutes() * 60;
        if (!$alive || $idle < 2 * self::noProgressMinutes() * 60 || !NotificationMarks::claim("round_noprogress:{$r['id']}:{$last}")) {
            return false;
        }
        $minutes = intdiv($idle, 60);
        self::record((int) $r['id'], 'no_progress', ['minutos' => $minutes, 'desde' => $last]);
        Notifier::dispatch('guard.no_progress', $r + ['idle_minutes' => $minutes, 'since' => $last]);
        return true;
    }

    private static function checkLate(array $r, int $now, int $started): bool
    {
        $expected = (int) ($r['expected_minutes'] ?? 0);
        if ($r['route_id'] === null || $expected <= 0) {
            return false; // ronda libre o ruta sin duración esperada: no hay contra qué comparar
        }
        $elapsed = intdiv($now - $started, 60);
        if ($elapsed < $expected + 2 * self::lateMinutes() || !NotificationMarks::claim("round_late:{$r['id']}")) {
            return false;
        }
        $missing = Patrols::missingPoints((int) $r['id'], (int) $r['route_id']);
        self::record((int) $r['id'], 'late', ['esperados' => $expected, 'transcurridos' => $elapsed, 'faltan' => $missing]);
        Notifier::dispatch('guard.round_late', $r + ['elapsed_minutes' => $elapsed, 'missing' => $missing]);
        return true;
    }

    /**
     * Fase 5: celular del guardia durante la ronda (el último estado que mandó la app). Al guardia ya le avisó la app;
     * a SyH y supervisores: batería en la mitad del umbral sin cargar (se va a apagar) o GPS apagado hace GPS_OFF_MINUTES.
     * Una vez por ronda y por problema.
     */
    private static function checkDevice(array $r, int $now): int
    {
        $st = DeviceStatus::latestForUser((int) $r['user_id']);
        if ($st === null || strtotime($st['received_at'] . ' UTC') < strtotime($r['started_at'] . ' UTC')) {
            return 0; // sin datos de esta ronda
        }
        $sent = 0;
        $critical = intdiv(DeviceStatus::batteryThreshold(), 2);
        if ($st['battery'] !== null && $st['battery'] <= $critical && empty($st['charging']) && NotificationMarks::claim("round_device:{$r['id']}:battery")) {
            self::record((int) $r['id'], 'battery', ['bateria' => $st['battery']]);
            Notifier::dispatch('guard.device', $r + ['_kind' => 'battery', 'battery' => $st['battery']]);
            $sent++;
        }
        $off = empty($st['gps']) || empty($st['permission']);
        $since = $st['gps_off_since'] ?? $st['at'];
        if ($off && $now - strtotime($since . ' UTC') >= DeviceStatus::GPS_OFF_MINUTES * 60 && NotificationMarks::claim("round_device:{$r['id']}:gps")) {
            self::record((int) $r['id'], 'gps_off', ['desde' => $since, 'permiso' => !empty($st['permission'])]);
            Notifier::dispatch('guard.device', $r + ['_kind' => 'gps', 'since' => $since]);
            $sent++;
        }
        return $sent;
    }

    /** Cierra una ronda olvidada: hasta su última actividad conocida (marca o posición), nunca antes de empezar. */
    private static function abandon(array $r, int $now): void
    {
        $last = max((string) $r['started_at'], (string) ($r['last_scan_at'] ?? ''), (string) ($r['last_track_at'] ?? ''));
        $status = $r['route_id'] !== null && Patrols::missingPoints((int) $r['id'], (int) $r['route_id']) > 0 ? 'incompleta' : 'completa';
        $done = DB::tenant()->prepare("UPDATE patrol_rounds SET status = ?, finished_at = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'en_curso'");
        $done->execute([$status, $last, (int) $r['id']]);
        if ($done->rowCount() > 0) {
            self::record((int) $r['id'], 'abandoned', ['horas' => self::ABANDON_HOURS, 'cerrada_en' => $last, 'estado' => $status]);
        }
    }

    private static function record(int $roundId, string $type, array $detail): void
    {
        DB::tenant()->prepare('INSERT INTO patrol_round_alerts (uuid, round_id, type, detail, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([Uuid::v4(), $roundId, $type, json_encode($detail, JSON_UNESCAPED_UNICODE)]);
    }

    /** Alertas de una ronda para el detalle del panel. */
    public static function alerts(int $roundId): array
    {
        $s = DB::tenant()->prepare('SELECT type, detail, created_at FROM patrol_round_alerts WHERE round_id = ? ORDER BY created_at, id');
        $s->execute([$roundId]);
        return array_map(fn ($a) => $a + ['data' => json_decode((string) $a['detail'], true) ?: []], $s->fetchAll());
    }
}
