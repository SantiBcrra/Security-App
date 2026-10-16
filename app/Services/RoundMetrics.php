<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Tenant;
use App\Models\NotificationMarks;
use App\Models\Patrols;
use App\Services\Notify\Notifier;

/**
 * Métricas y resúmenes de rondas (automatizaciones, fase 6): período en días locales de la empresa, filtrable por ruta y
 * guardia. Rondas (completas / incompletas / atrasadas / abandonadas), rondas programadas (a tiempo / tarde / no realizadas),
 * marcas por método y ubicación, por guardia, por punto (salteados, fuera de lugar, dudosos), guardias sin actividad,
 * historial de alertas con su resolución, CSV y resumen diario a SyH y supervisores.
 */
final class RoundMetrics
{
    public const SUMMARY_HOUR = 8;
    public const ALERT_LABELS = RoundAutomation::LABELS + ['panic' => 'Pánico', 'missed' => 'Ronda programada no realizada', 'off_site' => 'Marca fuera de lugar'];

    /** Días locales → rango UTC [desde, hasta). */
    public static function range(string $fromDay, string $toDay): array
    {
        $tz = new \DateTimeZone(Tenant::timezone() ?: 'America/Argentina/Buenos_Aires');
        $utc = new \DateTimeZone('UTC');
        return [(new \DateTimeImmutable("{$fromDay} 00:00:00", $tz))->setTimezone($utc)->format('Y-m-d H:i:s'),
            (new \DateTimeImmutable("{$toDay} 00:00:00", $tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    /**
     * @param array{route_id?:?int, user_id?:?int} $f filtros (user_id también para alcance "propios")
     */
    public static function report(string $fromDay, string $toDay, array $f = []): array
    {
        [$from, $to] = self::range($fromDay, $toDay);
        $db = DB::tenant();
        $where = ['r.started_at >= ?', 'r.started_at < ?'];
        $params = [$from, $to];
        if (!empty($f['route_id'])) { $where[] = 'r.route_id = ?'; $params[] = (int) $f['route_id']; }
        if (!empty($f['user_id'])) { $where[] = 'r.user_id = ?'; $params[] = (int) $f['user_id']; }
        $w = implode(' AND ', $where);
        $late = "pr.expected_minutes IS NOT NULL AND TIMESTAMPDIFF(MINUTE, r.started_at, COALESCE(r.finished_at, UTC_TIMESTAMP())) > pr.expected_minutes";

        // Rondas por guardia (y totales a partir de ahí)
        $q = $db->prepare("SELECT r.user_id, u.name AS user_name, COUNT(*) AS rounds,
                SUM(r.status = 'completa') AS completas, SUM(r.status = 'incompleta') AS incompletas, SUM(r.status = 'en_curso') AS en_curso,
                SUM(CASE WHEN {$late} THEN 1 ELSE 0 END) AS atrasadas,
                SUM((SELECT COUNT(*) FROM patrol_round_alerts a WHERE a.round_id = r.id AND a.type = 'abandoned')) AS abandonadas,
                SUM((SELECT COUNT(*) FROM patrol_round_alerts a WHERE a.round_id = r.id)) AS alertas,
                ROUND(AVG(CASE WHEN r.finished_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, r.started_at, r.finished_at) END)) AS duracion_media,
                MAX(r.started_at) AS ultima
            FROM patrol_rounds r JOIN users u ON u.id = r.user_id LEFT JOIN patrol_routes pr ON pr.id = r.route_id
            WHERE {$w} GROUP BY r.user_id, u.name ORDER BY u.name");
        $q->execute($params);
        $guards = [];
        foreach ($q->fetchAll() as $g) {
            $guards[(int) $g['user_id']] = array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, $g) + ['marcas' => 0, 'fuera' => 0, 'dudosas' => 0, 'nfc' => 0, 'fuera_orden' => 0,
                'prog_total' => 0, 'prog_tiempo' => 0, 'prog_tarde' => 0, 'prog_no' => 0];
        }

        // Marcas: método y ubicación (por guardia y totales)
        $q = $db->prepare("SELECT r.user_id, COUNT(*) AS n, SUM(s.location_status IN ('lejos','simulada')) AS fuera,
                SUM(s.location_status IN ('impreciso','sin_gps')) AS dudosas, SUM(s.method = 'nfc') AS nfc, SUM(s.location_status = 'ok') AS ok,
                SUM(s.out_of_order = 1) AS fuera_orden
            FROM patrol_scans s JOIN patrol_rounds r ON r.id = s.round_id WHERE {$w} GROUP BY r.user_id");
        $q->execute($params);
        $scans = ['total' => 0, 'ok' => 0, 'fuera' => 0, 'dudosas' => 0, 'nfc' => 0, 'fuera_orden' => 0];
        foreach ($q->fetchAll() as $m) {
            foreach (['total' => 'n', 'ok' => 'ok', 'fuera' => 'fuera', 'dudosas' => 'dudosas', 'nfc' => 'nfc', 'fuera_orden' => 'fuera_orden'] as $k => $col) $scans[$k] += (int) $m[$col];
            if (isset($guards[(int) $m['user_id']])) {
                $guards[(int) $m['user_id']] = ['marcas' => (int) $m['n'], 'fuera' => (int) $m['fuera'], 'dudosas' => (int) $m['dudosas'], 'nfc' => (int) $m['nfc'],
                    'fuera_orden' => (int) $m['fuera_orden']] + $guards[(int) $m['user_id']];
            }
        }

        // Rondas programadas del período (no realizadas = a cargo de todos los asignados)
        $sw = ['sl.due_at >= ?', 'sl.due_at < ?', "sl.status <> 'anulada'"];
        $sp = [$from, $to];
        if (!empty($f['route_id'])) { $sw[] = 'sl.route_id = ?'; $sp[] = (int) $f['route_id']; }
        $q = $db->prepare('SELECT sl.status, sl.on_time, sl.schedule_id, ro.user_id FROM patrol_slots sl LEFT JOIN patrol_rounds ro ON ro.id = sl.round_id
            WHERE ' . implode(' AND ', $sw));
        $q->execute($sp);
        $slots = ['total' => 0, 'tiempo' => 0, 'tarde' => 0, 'no' => 0, 'pendientes' => 0];
        $assignees = [];
        foreach ($q->fetchAll() as $sl) {
            $who = $sl['user_id'] !== null ? [(int) $sl['user_id']] : ($assignees[$sl['schedule_id']] ??= PatrolPlanner::assignees((int) $sl['schedule_id']));
            if (!empty($f['user_id']) && !in_array((int) $f['user_id'], $who, true)) continue;
            $kind = match (true) { $sl['status'] === 'realizada' && (int) $sl['on_time'] === 1 => 'tiempo', $sl['status'] === 'realizada' => 'tarde',
                $sl['status'] === 'no_realizada' => 'no', default => 'pendientes' };
            $slots['total']++;
            $slots[$kind]++;
            if ($kind === 'pendientes') continue;
            foreach ($who as $uid) {
                if (!isset($guards[$uid])) {
                    $u = \App\Models\Users::findById($uid);
                    if (!$u) continue;
                    $guards[$uid] = ['user_id' => $uid, 'user_name' => $u['name'], 'rounds' => 0, 'completas' => 0, 'incompletas' => 0, 'en_curso' => 0, 'atrasadas' => 0,
                        'abandonadas' => 0, 'alertas' => 0, 'duracion_media' => null, 'ultima' => null, 'marcas' => 0, 'fuera' => 0, 'dudosas' => 0, 'nfc' => 0, 'fuera_orden' => 0,
                        'prog_total' => 0, 'prog_tiempo' => 0, 'prog_tarde' => 0, 'prog_no' => 0];
                }
                $guards[$uid]['prog_total']++;
                $guards[$uid]['prog_' . $kind]++;
            }
        }
        $closed = $slots['tiempo'] + $slots['tarde'] + $slots['no'];
        $slots['cumplimiento'] = $closed > 0 ? (int) round(100 * $slots['tiempo'] / $closed) : null;

        // Por punto: marcas, fuera de lugar, dudosas y salteados (rondas de ruta terminadas sin ese punto)
        $q = $db->prepare("SELECT p.id, p.code, p.name, COUNT(*) AS marcas, SUM(s.location_status IN ('lejos','simulada')) AS fuera,
                SUM(s.location_status IN ('impreciso','sin_gps')) AS dudosas, SUM(s.method = 'nfc') AS nfc
            FROM patrol_scans s JOIN patrol_rounds r ON r.id = s.round_id JOIN patrol_points p ON p.id = s.point_id WHERE {$w} GROUP BY p.id, p.code, p.name");
        $q->execute($params);
        $points = [];
        foreach ($q->fetchAll() as $p) $points[(int) $p['id']] = ['code' => $p['code'], 'name' => $p['name'], 'marcas' => (int) $p['marcas'], 'fuera' => (int) $p['fuera'],
            'dudosas' => (int) $p['dudosas'], 'nfc' => (int) $p['nfc'], 'salteados' => 0];
        $q = $db->prepare("SELECT p.id, p.code, p.name, COUNT(*) AS n FROM patrol_rounds r JOIN patrol_route_points rp ON rp.route_id = r.route_id
                JOIN patrol_points p ON p.id = rp.point_id LEFT JOIN patrol_scans s ON s.round_id = r.id AND s.point_id = rp.point_id
            WHERE {$w} AND r.status IN ('completa','incompleta') AND s.id IS NULL GROUP BY p.id, p.code, p.name");
        $q->execute($params);
        foreach ($q->fetchAll() as $p) {
            $points[(int) $p['id']] ??= ['code' => $p['code'], 'name' => $p['name'], 'marcas' => 0, 'fuera' => 0, 'dudosas' => 0, 'nfc' => 0, 'salteados' => 0];
            $points[(int) $p['id']]['salteados'] = (int) $p['n'];
        }
        foreach ($points as &$p) $p['problemas'] = $p['salteados'] + $p['fuera'] + $p['dudosas'];
        unset($p);
        uasort($points, fn ($a, $b) => [$b['problemas'], $b['salteados']] <=> [$a['problemas'], $a['salteados']] ?: strcmp($a['code'], $b['code']));

        // Guardias asignados a rutas activas que no hicieron ninguna ronda en el período (de esa ruta, si se filtra por ruta)
        $idle = [];
        if (empty($f['user_id'])) {
            $q = $db->prepare("SELECT DISTINCT u.id, u.name, (SELECT MAX(started_at) FROM patrol_rounds x WHERE x.user_id = u.id) AS ultima
                FROM patrol_route_assignments a JOIN patrol_routes pr ON pr.id = a.route_id JOIN users u ON u.id = a.user_id
                WHERE a.is_active = 1 AND pr.is_active = 1 AND pr.deleted_at IS NULL AND u.is_active = 1" . (!empty($f['route_id']) ? ' AND pr.id = ' . (int) $f['route_id'] : '') . "
                AND NOT EXISTS (SELECT 1 FROM patrol_rounds r WHERE r.user_id = u.id AND r.started_at >= ? AND r.started_at < ? " . (!empty($f['route_id']) ? ' AND r.route_id = ' . (int) $f['route_id'] : '') . ") ORDER BY u.name");
            $q->execute([$from, $to]);
            $idle = $q->fetchAll();
        }

        $totals = ['rounds' => 0, 'completas' => 0, 'incompletas' => 0, 'en_curso' => 0, 'atrasadas' => 0, 'abandonadas' => 0, 'alertas' => 0];
        foreach ($guards as $g) foreach ($totals as $k => $_) $totals[$k] += (int) ($g[$k] ?? 0);
        uasort($guards, fn ($a, $b) => strcmp((string) $a['user_name'], (string) $b['user_name']));
        return ['from' => $fromDay, 'to' => $toDay, 'rounds' => $totals, 'slots' => $slots, 'scans' => $scans, 'guards' => array_values($guards),
            'points' => array_values($points), 'idle' => $idle, 'alerts' => self::alerts($from, $to, $f)];
    }

    /** Historial: alertas de rondas, pánicos (con quién lo atendió y en cuánto), marcas fuera de lugar y programadas no realizadas. */
    public static function alerts(string $from, string $to, array $f = []): array
    {
        $db = DB::tenant();
        $uf = !empty($f['user_id']) ? ' AND r.user_id = ' . (int) $f['user_id'] : '';
        $rf = !empty($f['route_id']) ? ' AND r.route_id = ' . (int) $f['route_id'] : '';
        $rows = [];
        $q = $db->prepare("SELECT a.type, a.detail, a.created_at AS at, r.uuid AS round_uuid, u.name AS user_name, pr.name AS route_name, r.status AS round_status
            FROM patrol_round_alerts a JOIN patrol_rounds r ON r.id = a.round_id JOIN users u ON u.id = r.user_id LEFT JOIN patrol_routes pr ON pr.id = r.route_id
            WHERE a.created_at >= ? AND a.created_at < ?{$uf}{$rf}");
        $q->execute([$from, $to]);
        foreach ($q->fetchAll() as $a) {
            $rows[] = ['type' => $a['type'], 'at' => $a['at'], 'user' => $a['user_name'], 'route' => $a['route_name'] ?: 'Ronda libre', 'round_uuid' => $a['round_uuid'],
                'detail' => '', 'resolution' => $a['round_status'] === 'en_curso' ? 'Ronda todavía en curso' : 'Ronda ' . $a['round_status']];
        }
        $q = $db->prepare("SELECT p.uuid, p.triggered_at_device AS at, p.acked_at, p.acked_name, p.ack_comment, p.sms_sent, u.name AS user_name, r.uuid AS round_uuid, pr.name AS route_name
            FROM panic_alerts p JOIN users u ON u.id = p.user_id LEFT JOIN patrol_rounds r ON r.id = p.round_id LEFT JOIN patrol_routes pr ON pr.id = r.route_id
            WHERE p.triggered_at_device >= ? AND p.triggered_at_device < ?" . (!empty($f['user_id']) ? ' AND p.user_id = ' . (int) $f['user_id'] : '') . $rf);
        $q->execute([$from, $to]);
        foreach ($q->fetchAll() as $p) {
            $mins = $p['acked_at'] ? intdiv(max(0, strtotime($p['acked_at'] . ' UTC') - strtotime($p['at'] . ' UTC')), 60) : null;
            $rows[] = ['type' => 'panic', 'at' => $p['at'], 'user' => $p['user_name'], 'route' => $p['route_name'] ?: '—', 'round_uuid' => $p['round_uuid'],
                'detail' => $p['sms_sent'] ? 'con SMS' : '', 'panic_uuid' => $p['uuid'],
                'resolution' => $p['acked_at'] ? "Atendido por {$p['acked_name']} en {$mins} min" . ($p['ack_comment'] ? ': ' . $p['ack_comment'] : '') : 'SIN ATENDER'];
        }
        $q = $db->prepare("SELECT s.scanned_at_device AS at, s.location_status, s.distance_m, u.name AS user_name, r.uuid AS round_uuid, pr.name AS route_name, p.code
            FROM patrol_scans s JOIN patrol_rounds r ON r.id = s.round_id JOIN users u ON u.id = r.user_id JOIN patrol_points p ON p.id = s.point_id
            LEFT JOIN patrol_routes pr ON pr.id = r.route_id
            WHERE s.location_status IN ('lejos','simulada') AND s.scanned_at_device >= ? AND s.scanned_at_device < ?{$uf}{$rf}");
        $q->execute([$from, $to]);
        foreach ($q->fetchAll() as $s) {
            $d = (float) $s['distance_m'];
            $rows[] = ['type' => 'off_site', 'at' => $s['at'], 'user' => $s['user_name'], 'route' => $s['route_name'] ?: 'Ronda libre', 'round_uuid' => $s['round_uuid'],
                'detail' => $s['code'] . ': ' . ($s['location_status'] === 'simulada' ? 'GPS falso' : ($d >= 1000 ? number_format($d / 1000, 1, ',', '.') . ' km' : (int) $d . ' m')),
                'resolution' => ''];
        }
        if (empty($f['user_id'])) {
            $q = $db->prepare("SELECT sl.due_at AS at, sl.schedule_id, r.name AS route_name FROM patrol_slots sl JOIN patrol_routes r ON r.id = sl.route_id
                WHERE sl.status = 'no_realizada' AND sl.due_at >= ? AND sl.due_at < ?" . (!empty($f['route_id']) ? ' AND sl.route_id = ' . (int) $f['route_id'] : ''));
            $q->execute([$from, $to]);
            foreach ($q->fetchAll() as $sl) {
                $rows[] = ['type' => 'missed', 'at' => $sl['at'], 'user' => self::names(PatrolPlanner::assignees((int) $sl['schedule_id'])), 'route' => $sl['route_name'],
                    'round_uuid' => null, 'detail' => 'de las ' . fecha($sl['at'], 'H:i'), 'resolution' => 'No se hizo'];
            }
        }
        usort($rows, fn ($a, $b) => strcmp($b['at'], $a['at']));
        return array_slice($rows, 0, 500);
    }

    private static function names(array $ids): string
    {
        if (!$ids) return '—';
        return implode(', ', DB::tenant()->query('SELECT name FROM users WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY name')->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** CSV (";" + BOM, como el resto de las exportaciones). @param string $type guardias | puntos | alertas */
    public static function csv(array $report, string $type): string
    {
        $out = fopen('php://temp', 'r+');
        $put = fn (array $row) => fputcsv($out, $row, ';');
        if ($type === 'puntos') {
            $put(['Código', 'Punto', 'Marcas', 'Con NFC', 'Fuera de lugar', 'Dudosas', 'Salteado (veces)']);
            foreach ($report['points'] as $p) $put([$p['code'], $p['name'], $p['marcas'], $p['nfc'], $p['fuera'], $p['dudosas'], $p['salteados']]);
        } elseif ($type === 'alertas') {
            $put(['Fecha', 'Tipo', 'Guardia', 'Ruta', 'Detalle', 'Resolución']);
            foreach ($report['alerts'] as $a) $put([fecha($a['at']), self::ALERT_LABELS[$a['type']] ?? $a['type'], $a['user'], $a['route'], $a['detail'], $a['resolution']]);
        } else {
            $put(['Guardia', 'Rondas', 'Completas', 'Incompletas', 'Atrasadas', 'Abandonadas', 'Duración media (min)', 'Marcas', 'Fuera de lugar', 'Dudosas',
                'Programadas', 'A tiempo', 'Tarde', 'No realizadas', 'Alertas', 'Última ronda', 'Fuera de orden']);
            foreach ($report['guards'] as $g) $put([$g['user_name'], $g['rounds'], $g['completas'], $g['incompletas'], $g['atrasadas'], $g['abandonadas'], $g['duracion_media'] ?? '',
                $g['marcas'], $g['fuera'], $g['dudosas'], $g['prog_total'], $g['prog_tiempo'], $g['prog_tarde'], $g['prog_no'], $g['alertas'], $g['ultima'] ? fecha($g['ultima']) : '', $g['fuera_orden'] ?? 0]);
            foreach ($report['idle'] as $i) $put([$i['name'], 0, '', '', '', '', '', '', '', '', '', '', '', '', '', $i['ultima'] ? fecha($i['ultima']) : 'nunca', '']);
        }
        rewind($out);
        return "\xEF\xBB\xBF" . stream_get_contents($out);
    }

    /**
     * Cron: después de las 8 h, resumen del día anterior a SyH y supervisores (`guard.daily_summary`), una vez por día.
     * Solo si hubo actividad de rondas o rondas programadas.
     */
    public static function dailySummary(?int $now = null): bool
    {
        if (!RoundAutomation::enabled()) return false;
        $local = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new \DateTimeZone(Tenant::timezone() ?: 'America/Argentina/Buenos_Aires'));
        if ((int) $local->format('G') < self::SUMMARY_HOUR) return false;
        $day = $local->modify('-1 day')->format('Y-m-d');
        $r = self::report($day, $day);
        if ($r['rounds']['rounds'] === 0 && $r['slots']['total'] === 0) return false;
        if (!NotificationMarks::claim("round_summary:{$day}")) return false;
        $panics = count(array_filter($r['alerts'], fn ($a) => $a['type'] === 'panic'));
        $previous = UserAuth::user();
        UserAuth::setCurrent(null);
        try {
            Notifier::dispatch('guard.daily_summary', ['id' => 0, 'uuid' => $day, 'day' => $day, 'r' => $r, 'panics' => $panics, 'user_id' => null]);
        } finally {
            UserAuth::setCurrent($previous);
        }
        return true;
    }
}
