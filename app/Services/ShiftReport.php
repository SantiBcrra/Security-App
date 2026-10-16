<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Horas trabajadas de los guardias (inicio y fin reales de cada turno). Por guardia: turnos, horas programadas y reales,
 * llegadas tarde, salidas anticipadas, tiempo fuera del predio, ausencias justificadas y no justificadas, turnos no
 * programados. Período en días locales de la empresa.
 */
final class ShiftReport
{
    /** Minutos de un turno (reales y programados) y sus desvíos. */
    public static function measure(array $s, ?int $now = null): array
    {
        $now ??= time();
        $start = strtotime($s['start_at'] . ' UTC');
        $end = strtotime($s['end_at'] . ' UTC');
        $planned = $s['unscheduled'] ? 0 : intdiv(max(0, $end - $start), 60);
        $worked = $late = $early = 0;
        if ($s['started_at'] !== null) {
            $a = strtotime($s['started_at'] . ' UTC');
            $b = $s['ended_at'] !== null ? strtotime($s['ended_at'] . ' UTC') : min($now, $end);
            $worked = intdiv(max(0, $b - $a), 60);
            if (!$s['unscheduled']) {
                $late = max(0, intdiv($a - $start - (int) $s['grace_minutes'] * 60, 60));
                if ($s['ended_at'] !== null && $s['end_mode'] === 'manual') $early = max(0, intdiv($end - $b, 60) - 5); // 5 min de margen
            }
        }
        return ['planned' => $planned, 'worked' => $worked, 'late' => $late, 'early' => $early, 'out' => intdiv((int) $s['out_seconds'], 60)];
    }

    /** @return array{rows: list<array>, total: array, shifts: list<array>} */
    public static function summary(string $fromDay, string $toDay, ?int $userId = null, ?int $siteId = null): array
    {
        [$from] = ShiftPlanner::dayRange($fromDay);
        [, $to] = ShiftPlanner::dayRange($toDay);
        $shifts = ShiftService::between($from, $to, $userId, $siteId);
        $blank = ['shifts' => 0, 'planned' => 0, 'worked' => 0, 'late_count' => 0, 'late' => 0, 'early_count' => 0, 'early' => 0,
            'out' => 0, 'exits' => 0, 'absent' => 0, 'justified' => 0, 'unscheduled' => 0, 'no_end' => 0];
        $rows = [];
        $total = $blank;
        foreach ($shifts as $s) {
            $m = self::measure($s);
            $r = $rows[$s['user_id']] ?? $blank + ['name' => $s['user_name'], 'uuid' => $s['user_uuid']];
            $add = ['shifts' => in_array($s['status'], ['en_curso', 'cumplido'], true) ? 1 : 0, 'planned' => $m['planned'], 'worked' => $m['worked'],
                'late_count' => $m['late'] > 0 ? 1 : 0, 'late' => $m['late'], 'early_count' => $m['early'] > 0 ? 1 : 0, 'early' => $m['early'],
                'out' => $m['out'], 'exits' => (int) $s['exits_count'], 'absent' => $s['status'] === 'ausente' ? 1 : 0,
                'justified' => $s['status'] === 'justificado' ? 1 : 0, 'unscheduled' => (int) $s['unscheduled'],
                'no_end' => $s['end_mode'] === 'auto' ? 1 : 0];
            foreach ($add as $k => $v) { $r[$k] += $v; $total[$k] += $v; }
            $rows[$s['user_id']] = $r;
        }
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));
        return ['rows' => $rows, 'total' => $total, 'shifts' => $shifts];
    }

    public static function hours(int $minutes): string
    {
        return sprintf('%d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** CSV (";" + BOM, como el resto de los exportables). */
    public static function csv(array $report, string $type): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        if ($type === 'turnos') {
            fputcsv($out, ['Guardia', 'Predio', 'Inicio programado', 'Fin programado', 'Inicio real', 'Fin real', 'Estado', 'Horas reales', 'Llegó tarde (min)',
                'Se fue antes (min)', 'Minutos fuera del predio', 'Salidas', 'No programado'], ';');
            foreach ($report['shifts'] as $s) {
                $m = self::measure($s);
                fputcsv($out, [$s['user_name'], $s['site_name'], fecha($s['start_at'], 'd/m/Y H:i'), fecha($s['end_at'], 'd/m/Y H:i'),
                    $s['started_at'] ? fecha($s['started_at'], 'd/m/Y H:i') : '', $s['ended_at'] ? fecha($s['ended_at'], 'd/m/Y H:i') . ($s['end_mode'] === 'auto' ? ' (automático)' : '') : '',
                    ShiftService::STATUS[$s['status']] ?? $s['status'], self::hours($m['worked']), $m['late'], $m['early'], $m['out'], (int) $s['exits_count'], $s['unscheduled'] ? 'Sí' : ''], ';');
            }
        } else {
            fputcsv($out, ['Guardia', 'Turnos', 'Horas programadas', 'Horas reales', 'Llegadas tarde', 'Minutos tarde', 'Salidas anticipadas', 'Minutos antes',
                'Minutos fuera del predio', 'Salidas del predio', 'Ausencias sin justificar', 'Ausencias justificadas', 'Turnos no programados', 'Sin marcar el fin'], ';');
            foreach ($report['rows'] as $r) {
                fputcsv($out, [$r['name'], $r['shifts'], self::hours($r['planned']), self::hours($r['worked']), $r['late_count'], $r['late'], $r['early_count'], $r['early'],
                    $r['out'], $r['exits'], $r['absent'], $r['justified'], $r['unscheduled'], $r['no_end']], ';');
            }
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }
}
