<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Tenant;
use App\Models\Incidents;
use App\Models\WorkedHours;

/**
 * Índices por mes (base para la Etapa 16):
 * - frecuencia (IF) = accidentes con baja × 1.000.000 / horas trabajadas;
 * - gravedad (IG) = días perdidos × 1.000 / horas trabajadas;
 * - incidencia (II) = accidentes con baja × 1.000 / dotación.
 * Los in itinere se informan aparte (no entran en los índices). Los días perdidos se imputan al mes del accidente.
 */
final class IncidentIndicators
{
    public static function year(int $year, ?int $siteId = null, ?string $today = null): array
    {
        $tz = new \DateTimeZone(Tenant::timezone() ?? 'UTC');
        $from = (new \DateTimeImmutable("{$year}-01-01", $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $to = (new \DateTimeImmutable(($year + 1) . '-01-01', $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $months = [];
        foreach (range(1, 12) as $m) {
            $months[sprintf('%d-%02d', $year, $m)] = ['hours' => 0.0, 'headcount' => null, 'accidents' => 0, 'itinere' => 0, 'lost_days' => 0, 'provisional' => false];
        }
        foreach (WorkedHours::forYear($year) as $site => $byMonth) {
            if ($siteId !== null && $site !== $siteId) {
                continue;
            }
            foreach ($byMonth as $month => $h) {
                $months[$month]['hours'] += $h['hours'];
                if ($h['headcount'] !== null) {
                    $months[$month]['headcount'] = ($months[$month]['headcount'] ?? 0) + $h['headcount'];
                }
            }
        }
        $counted = [];
        foreach (Incidents::forIndicators($from, $to) as $r) {
            if ($siteId !== null && (int) $r['site_id'] !== $siteId) {
                continue;
            }
            $month = (new \DateTimeImmutable($r['occurred_at'], new \DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m');
            if (!isset($counted[$r['id']])) {
                $counted[$r['id']] = true;
                if ($r['type'] === 'accidente_con_baja') {
                    $months[$month]['accidents']++;
                } elseif ($r['type'] === 'in_itinere') {
                    $months[$month]['itinere']++;
                }
            }
            if ($r['type'] === 'accidente_con_baja' && $r['lost_time'] !== null) {
                $lost = IncidentService::lostDays($r, $today);
                $months[$month]['lost_days'] += $lost['days'];
                $months[$month]['provisional'] = $months[$month]['provisional'] || $lost['provisional'];
            }
        }
        $total = ['hours' => 0.0, 'headcount' => null, 'accidents' => 0, 'itinere' => 0, 'lost_days' => 0, 'provisional' => false];
        $heads = [];
        foreach ($months as $k => $m) {
            $months[$k] += self::indices($m);
            $total['hours'] += $m['hours'];
            $total['accidents'] += $m['accidents'];
            $total['itinere'] += $m['itinere'];
            $total['lost_days'] += $m['lost_days'];
            $total['provisional'] = $total['provisional'] || $m['provisional'];
            if ($m['headcount'] !== null) {
                $heads[] = $m['headcount'];
            }
        }
        $total['headcount'] = $heads ? (int) round(array_sum($heads) / count($heads)) : null; // dotación promedio
        return ['months' => $months, 'total' => $total + self::indices($total)];
    }

    private static function indices(array $m): array
    {
        return [
            'if' => $m['hours'] > 0 ? round($m['accidents'] * 1_000_000 / $m['hours'], 2) : null,
            'ig' => $m['hours'] > 0 ? round($m['lost_days'] * 1000 / $m['hours'], 3) : null,
            'ii' => $m['headcount'] ? round($m['accidents'] * 1000 / $m['headcount'], 2) : null,
        ];
    }
}
