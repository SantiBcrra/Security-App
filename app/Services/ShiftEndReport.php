<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Services\Notify\Notifier;

/**
 * Reporte de fin de turno de un puesto (una franja, p. ej. Lun 20:00 → Mar 08:00), para RRHH y quien diga la regla
 * `guard.shift_report`: quién cubrió y en qué horario (relevos y huecos), entradas y salidas de personas y vehículos,
 * novedades del libro, si hubo o no incidentes, observaciones, rondas y pánicos. Se manda una vez al cerrarse la franja
 * (`PostCoverage::run`). El cuerpo lleva todo el contenido: las casillas de email fijas no tienen usuario para entrar.
 */
final class ShiftEndReport
{
    /** @return array{title:string, body:string, counts:array} */
    public static function build(array $c): array
    {
        $d = PostCoverage::detail($c);
        $from = $c['start_at'];
        $to = $c['end_at'];
        $siteId = (int) $c['site_id'];
        $db = DB::tenant();
        $hm = fn (?string $utc) => $utc ? fecha($utc, 'd/m H:i') : '—';
        $dur = fn (int $sec) => intdiv($sec, 3600) . ' h ' . str_pad((string) intdiv($sec % 3600, 60), 2, '0', STR_PAD_LEFT) . ' min';
        $post = $c['shift_name'] ?: $c['site_name'];
        $lines = [];

        // Guardias
        $lines[] = 'GUARDIAS';
        if (!$d['segments']) $lines[] = '· Nadie cubrió el puesto en esta franja.';
        $endLabel = ['relevo' => 'relevado', 'sin_persona' => 'dejó el puesto sin persona', 'auto' => 'cerrado por el sistema (no marcó el fin)', 'manual' => 'terminó'];
        foreach ($d['segments'] as $s) {
            $lines[] = "· {$s['user_name']}: {$hm($s['started_at'])} a {$hm($s['ended_at'])}" . ($s['ended_at'] ? ' (' . ($endLabel[$s['end_mode']] ?? 'terminó')
                . ($s['end_note'] ? ': ' . $s['end_note'] : '') . ')' : ' (sigue en turno)') . ((int) $s['start_inside'] === 0 && $s['start_inside'] !== null ? ' · empezó fuera del predio' : '');
        }
        foreach ($d['gaps'] as $g) {
            $lines[] = '· ' . ($g['justified'] ? 'Sin persona (motivo: ' . $g['reason'] . ')' : 'SIN GUARDIA') . ": {$hm($g['from'])} a {$hm($g['to'])} ({$dur($g['seconds'])})";
        }
        $lines[] = 'Cobertura: ' . ($d['percent'] !== null ? $d['percent'] . ' %' : '—') . " ({$dur($d['covered'])} de {$dur($d['elapsed'])}).";

        // Libro: entradas, salidas y novedades
        $q = $db->prepare("SELECT * FROM logbook_entries WHERE site_id = ? AND occurred_at >= ? AND occurred_at < ? AND kind IN ('persona', 'vehiculo', 'novedad')
            ORDER BY occurred_at, folio");
        $q->execute([$siteId, $from, $to]);
        $entries = $q->fetchAll();
        $moves = array_values(array_filter($entries, fn ($e) => $e['kind'] !== 'novedad'));
        $news = array_values(array_filter($entries, fn ($e) => $e['kind'] === 'novedad'));
        $lines[] = '';
        $lines[] = 'ENTRADAS Y SALIDAS (' . count($moves) . ')';
        if (!$moves) $lines[] = '· Sin ingresos anotados.';
        foreach ($moves as $e) {
            $lines[] = '· ' . fecha($e['occurred_at'], 'H:i') . ' entró ' . Logbook::label($e) . ' → ' . ($e['exited_at'] ? 'salió ' . fecha($e['exited_at'], 'H:i') : 'SIGUE ADENTRO');
        }
        $lines[] = '';
        $lines[] = 'NOVEDADES (' . count($news) . ')';
        if (!$news) $lines[] = '· Sin novedades.';
        foreach ($news as $e) {
            $cat = Logbook::CATEGORIES[$e['category'] ?? ''] ?? 'Novedad';
            $lines[] = '· ' . fecha($e['occurred_at'], 'H:i') . ($e['category'] === 'riesgo' ? ' ⚠ ' : ((int) $e['important'] === 1 ? ' ⚠ ' : ' ')) . "[{$cat}] " . mb_strimwidth((string) $e['text'], 0, 400, '…');
        }

        // Incidentes y observaciones del predio
        $q = $db->prepare('SELECT number, type, description, occurred_at FROM incidents WHERE site_id = ? AND occurred_at >= ? AND occurred_at < ? ORDER BY occurred_at');
        $q->execute([$siteId, $from, $to]);
        $incidents = $q->fetchAll();
        $lines[] = '';
        $lines[] = 'INCIDENTES Y ACCIDENTES: ' . ($incidents ? count($incidents) : 'no hubo');
        foreach ($incidents as $i) {
            $lines[] = '· ' . \App\Models\Incidents::format((int) $i['number']) . ' · ' . fecha($i['occurred_at'], 'H:i') . ' · '
                . (IncidentService::TYPES[$i['type']]['label'] ?? $i['type']) . ': ' . mb_strimwidth((string) $i['description'], 0, 300, '…');
        }
        $q = $db->prepare('SELECT number, description, imminent_risk, created_at_device FROM observations WHERE site_id = ? AND created_at_device >= ? AND created_at_device < ? ORDER BY created_at_device');
        $q->execute([$siteId, $from, $to]);
        $observations = $q->fetchAll();
        $lines[] = 'OBSERVACIONES: ' . ($observations ? count($observations) : 'no hubo');
        foreach ($observations as $o) {
            $lines[] = '· ' . \App\Models\Observations::format((int) $o['number']) . ((int) $o['imminent_risk'] === 1 ? ' ⚠ RIESGO INMINENTE' : '') . ' · '
                . fecha($o['created_at_device'], 'H:i') . ': ' . mb_strimwidth((string) $o['description'], 0, 300, '…');
        }

        // Rondas y pánicos de los guardias que cubrieron
        $ids = array_values(array_unique(array_map(fn ($s) => (int) $s['user_id'], $d['segments'])));
        $rounds = ['total' => 0, 'completa' => 0, 'incompleta' => 0, 'en_curso' => 0];
        $panics = 0;
        if ($ids) {
            $in = implode(',', $ids);
            $q = $db->prepare("SELECT status, COUNT(*) n FROM patrol_rounds WHERE user_id IN ({$in}) AND started_at >= ? AND started_at < ? AND status <> 'anulada' GROUP BY status");
            $q->execute([$from, $to]);
            foreach ($q->fetchAll() as $r) { $rounds['total'] += (int) $r['n']; $rounds[$r['status']] = (int) $r['n']; }
            $q = $db->prepare("SELECT COUNT(*) FROM panic_alerts WHERE user_id IN ({$in}) AND triggered_at_device >= ? AND triggered_at_device < ?");
            $q->execute([$from, $to]);
            $panics = (int) $q->fetchColumn();
        }
        $lines[] = 'RONDAS: ' . ($rounds['total'] ? "{$rounds['total']} ({$rounds['completa']} completas, {$rounds['incompleta']} incompletas)" : 'no se hicieron');
        $lines[] = 'PÁNICO: ' . ($panics ? "{$panics} alerta(s) — revisalas en el sistema" : 'no hubo');

        $inside = array_values(array_filter($moves, fn ($e) => $e['exited_at'] === null));
        $flags = [];
        if ($incidents) $flags[] = count($incidents) . ' incidente(s)';
        if (array_filter($news, fn ($e) => $e['category'] === 'riesgo')) $flags[] = 'condición insegura';
        if (array_filter($d['gaps'], fn ($g) => !$g['justified'])) $flags[] = 'puesto sin guardia';
        $title = 'Reporte de turno · ' . $post . ' · ' . fecha($from, 'd/m H:i') . ' a ' . fecha($to, 'd/m H:i')
            . ($flags ? ' · ⚠ ' . implode(', ', $flags) : ' · sin incidentes');
        $head = "{$c['site_name']} · franja del " . fecha($from, 'd/m/Y H:i') . ' al ' . fecha($to, 'd/m/Y H:i') . "\n"
            . 'Resumen: ' . count($moves) . ' ingreso(s), ' . count($inside) . ' sin salida, ' . count($news) . ' novedad(es), '
            . ($incidents ? count($incidents) . ' incidente(s)' : 'sin incidentes') . '.' . "\n";
        return ['title' => $title, 'body' => $head . "\n" . implode("\n", $lines),
            'counts' => ['moves' => count($moves), 'inside' => count($inside), 'news' => count($news), 'incidents' => count($incidents),
                'observations' => count($observations), 'rounds' => $rounds['total'], 'panics' => $panics]];
    }

    /** Manda el reporte de la franja (una sola vez). */
    public static function send(array $c): void
    {
        if (!\App\Models\NotificationMarks::claim("shift_report:{$c['id']}")) return;
        $r = self::build($c);
        Notifier::dispatch('guard.shift_report', $c + ['_post' => true, 'report_title' => $r['title'], 'report_body' => $r['body'], 'user_id' => null]);
    }
}
