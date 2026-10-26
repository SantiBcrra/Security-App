<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Spreadsheet;
use App\Core\UserError;
use App\Core\Uuid;

/**
 * Lista de autorizados para ingresar al predio (libro de guardia). Personas por DNI (o por nombre si no tiene DNI) y vehículos
 * por patente; para un predio o para todos; con vigencia (desde/hasta), días de la semana y horario.
 *
 * Al anotar un ingreso el resultado queda en la anotación (fuera de la huella del libro): autorizado | fuera_horario | vencido |
 * no_autorizado. Si el predio no tiene a nadie en la lista, no se controla (null): la lista es optativa. Un ingreso que no está
 * autorizado se anota igual (el guardia no frena la operación) y avisa a SyH y supervisores (`logbook.unauthorized`).
 * El celular hace el mismo control sin señal con la lista que le baja la sincronización.
 */
final class LogbookAuthorized
{
    public const STATUS = [
        'autorizado'    => ['Autorizado', 'success'],
        'fuera_horario' => ['Fuera del día u horario autorizado', 'warning'],
        'vencido'       => ['Autorización vencida o todavía no vigente', 'warning'],
        'no_autorizado' => ['No está en la lista de autorizados', 'danger'],
    ];

    /** La administran quienes pueden editar el libro (administrador y SyH). */
    public static function canManage(): bool
    {
        return UserAuth::can('libro', 'editar');
    }

    public static function doc(?string $v): ?string
    {
        $d = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $v));
        return $d === '' ? null : mb_substr($d, 0, 30);
    }

    public static function plate(?string $v): ?string
    {
        $p = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $v));
        return $p === '' ? null : mb_substr($p, 0, 15);
    }

    private const SELECT = 'SELECT a.*, s.uuid AS site_uuid, s.name AS site_name, u.name AS created_by_name FROM logbook_authorized a
        LEFT JOIN sites s ON s.id = a.site_id LEFT JOIN users u ON u.id = a.created_by';

    public static function find(string $uuid): ?array
    {
        $q = DB::tenant()->prepare(self::SELECT . ' WHERE a.uuid = ? AND a.deleted_at IS NULL');
        $q->execute([strtolower($uuid)]);
        return $q->fetch() ?: null;
    }

    /** @param array{q?:string, site_id?:?int, kind?:string, estado?:string} $f */
    public static function list(array $f = []): array
    {
        $where = ['a.deleted_at IS NULL'];
        $params = [];
        if (!empty($f['site_id'])) { $where[] = '(a.site_id = ? OR a.site_id IS NULL)'; $params[] = (int) $f['site_id']; }
        if (in_array($f['kind'] ?? '', ['persona', 'vehiculo'], true)) { $where[] = 'a.kind = ?'; $params[] = $f['kind']; }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $where[] = '(a.person_name LIKE ? OR a.person_doc LIKE ? OR a.company LIKE ? OR a.plate LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, '%' . (self::doc($q) ?? $q) . '%', $like, '%' . (self::plate($q) ?? $q) . '%');
        }
        $today = ShiftPlanner::localDay(gmdate('Y-m-d H:i:s'));
        if (($f['estado'] ?? '') === 'vigentes') { $where[] = 'a.is_active = 1 AND (a.valid_until IS NULL OR a.valid_until >= ?)'; $params[] = $today; }
        if (($f['estado'] ?? '') === 'vencidas') { $where[] = 'a.valid_until < ?'; $params[] = $today; }
        $q = DB::tenant()->prepare(self::SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY a.is_active DESC, a.company, a.person_name, a.plate LIMIT 1000');
        $q->execute($params);
        return $q->fetchAll();
    }

    /** @return array{0: array, 1: array<string,string>} */
    public static function validate(array $in): array
    {
        $e = [];
        $kind = ($in['kind'] ?? '') === 'vehiculo' ? 'vehiculo' : 'persona';
        $str = fn (string $k, int $max) => ($v = trim((string) ($in[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
        $site = null;
        if (!empty($in['site'])) {
            $site = \App\Models\Sites::findByUuid((string) $in['site']);
            if ($site === null) $e['site'] = 'El predio no existe.';
        }
        $data = ['kind' => $kind, 'site_id' => $site ? (int) $site['id'] : null, 'person_name' => $str('person_name', 150), 'person_doc' => self::doc($in['person_doc'] ?? null),
            'company' => $str('company', 150), 'plate' => $kind === 'vehiculo' ? self::plate($in['plate'] ?? null) : null, 'reason' => $str('reason', 255),
            'authorized_by' => $str('authorized_by', 150)];
        if ($kind === 'persona' && $data['person_doc'] === null && $data['person_name'] === null) $e['person_doc'] = 'Poné el DNI (o al menos el nombre) de la persona.';
        if ($kind === 'vehiculo' && $data['plate'] === null) $e['plate'] = 'Poné la patente.';
        $date = fn ($v) => ($v = trim((string) $v)) !== '' && ($t = strtotime($v)) !== false ? date('Y-m-d', $t) : null;
        $data['valid_from'] = $date($in['valid_from'] ?? '');
        $data['valid_until'] = $date($in['valid_until'] ?? '');
        if ($data['valid_from'] && $data['valid_until'] && $data['valid_until'] < $data['valid_from']) $e['valid_until'] = 'La vigencia termina antes de empezar.';
        $days = array_values(array_unique(array_filter(array_map('intval', is_array($in['days'] ?? null) ? $in['days'] : preg_split('/[^0-9]+/', (string) ($in['days'] ?? ''))),
            fn ($d) => $d >= 1 && $d <= 7)));
        sort($days);
        $data['days'] = $days && count($days) < 7 ? implode(',', $days) : null;
        $hm = fn ($v) => preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim((string) $v), $m) ? sprintf('%02d:%02d', $m[1], $m[2]) : null;
        $data['time_from'] = $hm($in['time_from'] ?? '');
        $data['time_to'] = $hm($in['time_to'] ?? '');
        if (($data['time_from'] === null) !== ($data['time_to'] === null)) $e['time_from'] = 'Completá el horario desde y hasta, o dejá los dos vacíos.';
        return [$data, $e];
    }

    public static function save(?array $row, array $data): string
    {
        if (!self::canManage()) throw new UserError('No tenés permiso para editar la lista de autorizados.');
        $db = DB::tenant();
        if ($row === null) {
            $uuid = Uuid::v4();
            $cols = array_keys($data);
            $db->prepare('INSERT INTO logbook_authorized (uuid, ' . implode(', ', $cols) . ', is_active, created_by, created_at, updated_at) VALUES (?, '
                . implode(', ', array_fill(0, count($cols), '?')) . ', 1, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
                ->execute(array_merge([$uuid], array_values($data), [UserAuth::user()['id'] ?? null]));
            Audit::tenant('logbook_authorized.create', 'logbook_authorized', $uuid, null, $data);
            return $uuid;
        }
        $db->prepare('UPDATE logbook_authorized SET ' . implode(', ', array_map(fn ($c) => "{$c} = ?", array_keys($data))) . ', updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute(array_merge(array_values($data), [(int) $row['id']]));
        Audit::tenant('logbook_authorized.update', 'logbook_authorized', $row['uuid'], array_intersect_key($row, $data), $data);
        return $row['uuid'];
    }

    public static function setActive(array $row, bool $active): void
    {
        DB::tenant()->prepare('UPDATE logbook_authorized SET is_active = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$active ? 1 : 0, (int) $row['id']]);
        Audit::tenant($active ? 'logbook_authorized.activate' : 'logbook_authorized.deactivate', 'logbook_authorized', $row['uuid']);
    }

    public static function delete(array $row): void
    {
        DB::tenant()->prepare('UPDATE logbook_authorized SET is_active = 0, deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([(int) $row['id']]);
        Audit::tenant('logbook_authorized.delete', 'logbook_authorized', $row['uuid'], ['quien' => self::label($row)], null);
    }

    public static function label(array $a): string
    {
        return $a['kind'] === 'vehiculo'
            ? 'Vehículo ' . $a['plate'] . ($a['company'] ? ' · ' . $a['company'] : '')
            : trim(($a['person_name'] ?? '') . ($a['person_doc'] ? ' (DNI ' . $a['person_doc'] . ')' : '') . ($a['company'] ? ' · ' . $a['company'] : ''));
    }

    /** "Lun a Vie · 08:00 a 17:00 · hasta 30/11/2026" */
    public static function window(array $a): string
    {
        $parts = [];
        if ($a['days']) $parts[] = ShiftPlanner::daysText(array_map('intval', explode(',', (string) $a['days'])));
        if ($a['time_from']) $parts[] = "{$a['time_from']} a {$a['time_to']}";
        if ($a['valid_from']) $parts[] = 'desde ' . date('d/m/Y', strtotime((string) $a['valid_from']));
        if ($a['valid_until']) $parts[] = 'hasta ' . date('d/m/Y', strtotime((string) $a['valid_until']));
        return $parts ? implode(' · ', $parts) : 'Siempre';
    }

    // ── control ───────────────────────────────────────────────────

    /**
     * ¿Está autorizado este ingreso? null si el predio no tiene a nadie de ese tipo en la lista (no se controla).
     * @return ?array{status:string, row:?array}
     */
    public static function check(int $siteId, string $kind, ?string $doc, ?string $name, ?string $plate, string $atUtc): ?array
    {
        $db = DB::tenant();
        $kind = $kind === 'vehiculo' ? 'vehiculo' : 'persona';
        $q = $db->prepare('SELECT COUNT(*) FROM logbook_authorized WHERE kind = ? AND is_active = 1 AND deleted_at IS NULL AND (site_id = ? OR site_id IS NULL)');
        $q->execute([$kind, $siteId]);
        if ((int) $q->fetchColumn() === 0) return null;
        $cand = [];
        if ($kind === 'vehiculo' && ($p = self::plate($plate)) !== null) {
            $q = $db->prepare('SELECT * FROM logbook_authorized WHERE kind = ? AND plate = ? AND is_active = 1 AND deleted_at IS NULL AND (site_id = ? OR site_id IS NULL)');
            $q->execute([$kind, $p, $siteId]);
            $cand = $q->fetchAll();
        } elseif ($kind === 'persona') {
            if (($d = self::doc($doc)) !== null) {
                $q = $db->prepare('SELECT * FROM logbook_authorized WHERE kind = ? AND person_doc = ? AND is_active = 1 AND deleted_at IS NULL AND (site_id = ? OR site_id IS NULL)');
                $q->execute([$kind, $d, $siteId]);
                $cand = $q->fetchAll();
            }
            if (!$cand && ($n = trim((string) $name)) !== '') { // sin DNI en la lista: por nombre exacto (sin mayúsculas ni acentos distintos)
                $q = $db->prepare('SELECT * FROM logbook_authorized WHERE kind = ? AND person_doc IS NULL AND person_name = ? AND is_active = 1 AND deleted_at IS NULL AND (site_id = ? OR site_id IS NULL)');
                $q->execute([$kind, $n, $siteId]);
                $cand = $q->fetchAll();
            }
        }
        if (!$cand) return ['status' => 'no_autorizado', 'row' => null];
        $local = (new \DateTimeImmutable($atUtc . ' UTC'))->setTimezone(ShiftPlanner::tz());
        $day = $local->format('Y-m-d');
        $best = null;
        foreach ($cand as $a) {
            $status = self::statusAt($a, $day, (int) $local->format('N'), $local->format('H:i'));
            if ($status === 'autorizado') return ['status' => 'autorizado', 'row' => $a];
            $best ??= ['status' => $status, 'row' => $a];
        }
        return $best;
    }

    private static function statusAt(array $a, string $day, int $weekday, string $hm): string
    {
        if (($a['valid_from'] && $day < $a['valid_from']) || ($a['valid_until'] && $day > $a['valid_until'])) return 'vencido';
        if ($a['days'] && !in_array($weekday, array_map('intval', explode(',', (string) $a['days'])), true)) return 'fuera_horario';
        if ($a['time_from'] && $a['time_to']) {
            $inside = $a['time_from'] <= $a['time_to'] ? ($hm >= $a['time_from'] && $hm <= $a['time_to']) : ($hm >= $a['time_from'] || $hm <= $a['time_to']);
            if (!$inside) return 'fuera_horario';
        }
        return 'autorizado';
    }

    // ── importación ───────────────────────────────────────────────

    /**
     * Excel o CSV con columnas (en cualquier orden, por nombre): tipo, nombre, dni, empresa, patente, predio, desde, hasta,
     * dias, hora_desde, hora_hasta, motivo, autorizo. Agrega o actualiza (misma persona por DNI o mismo vehículo por patente
     * en el mismo predio). @return array{created:int, updated:int, errors:list<string>}
     */
    public static function import(string $path, string $originalName): array
    {
        if (!self::canManage()) throw new UserError('No tenés permiso para editar la lista de autorizados.');
        $rows = Spreadsheet::read($path, $originalName);
        if (count($rows) < 2) throw new UserError('El archivo no tiene filas para importar (la primera fila son los títulos).');
        $norm = fn ($h) => strtolower(trim(strtr((string) $h, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u'])));
        $aliases = ['tipo' => 'kind', 'nombre' => 'person_name', 'apellido y nombre' => 'person_name', 'dni' => 'person_doc', 'documento' => 'person_doc',
            'empresa' => 'company', 'patente' => 'plate', 'dominio' => 'plate', 'predio' => 'site', 'planta' => 'site', 'desde' => 'valid_from', 'hasta' => 'valid_until',
            'dias' => 'days', 'hora desde' => 'time_from', 'hora_desde' => 'time_from', 'hora hasta' => 'time_to', 'hora_hasta' => 'time_to', 'motivo' => 'reason',
            'autorizo' => 'authorized_by', 'autorizado por' => 'authorized_by'];
        $map = [];
        foreach ($rows[0] as $i => $h) if (isset($aliases[$norm($h)])) $map[$i] = $aliases[$norm($h)];
        if (!in_array('person_doc', $map, true) && !in_array('plate', $map, true) && !in_array('person_name', $map, true)) {
            throw new UserError('No encontré las columnas. La primera fila tiene que tener títulos como: tipo, nombre, dni, empresa, patente, desde, hasta, dias, hora_desde, hora_hasta, motivo.');
        }
        $sites = array_column(\App\Models\Sites::list(), null, 'name');
        $dayNames = ['lun' => 1, 'mar' => 2, 'mie' => 3, 'jue' => 4, 'vie' => 5, 'sab' => 6, 'dom' => 7];
        $out = ['created' => 0, 'updated' => 0, 'errors' => []];
        foreach (array_slice($rows, 1) as $n => $r) {
            $in = [];
            foreach ($map as $i => $k) $in[$k] = trim((string) ($r[$i] ?? ''));
            if (implode('', $in) === '') continue;
            $in['kind'] = str_starts_with($norm($in['kind'] ?? ''), 'veh') || (($in['plate'] ?? '') !== '' && ($in['person_doc'] ?? '') === '' && ($in['person_name'] ?? '') === '') ? 'vehiculo' : 'persona';
            if (($in['site'] ?? '') !== '') {
                $s = $sites[$in['site']] ?? null;
                if ($s === null) { $out['errors'][] = 'Fila ' . ($n + 2) . ": no existe el predio «{$in['site']}»."; continue; }
                $in['site'] = $s['uuid'];
            }
            foreach (['valid_from', 'valid_until'] as $k) { // dd/mm/aaaa → aaaa-mm-dd
                if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', $in[$k] ?? '', $m)) $in[$k] = sprintf('%04d-%02d-%02d', strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3], $m[2], $m[1]);
            }
            if (($in['days'] ?? '') !== '' && !preg_match('/\d/', $in['days'])) { // "lun a vie" / "lun, mie, vie"
                $d = $norm($in['days']);
                if (preg_match('/(lun|mar|mie|jue|vie|sab|dom)\w*\s+a\s+(lun|mar|mie|jue|vie|sab|dom)/', $d, $m)) $in['days'] = implode(',', range($dayNames[$m[1]], $dayNames[$m[2]]));
                else { preg_match_all('/lun|mar|mie|jue|vie|sab|dom/', $d, $m); $in['days'] = implode(',', array_map(fn ($x) => $dayNames[$x], $m[0])); }
            }
            [$data, $errors] = self::validate($in);
            if ($errors) { $out['errors'][] = 'Fila ' . ($n + 2) . ': ' . implode(' ', $errors); continue; }
            $q = DB::tenant()->prepare('SELECT * FROM logbook_authorized WHERE deleted_at IS NULL AND kind = ? AND ' . ($data['kind'] === 'vehiculo' ? 'plate = ?' : 'person_doc = ?')
                . ' AND ' . ($data['site_id'] === null ? 'site_id IS NULL' : 'site_id = ' . (int) $data['site_id']) . ' LIMIT 1');
            $q->execute([$data['kind'], $data['kind'] === 'vehiculo' ? $data['plate'] : $data['person_doc']]);
            $existing = ($data['kind'] === 'vehiculo' ? $data['plate'] : $data['person_doc']) !== null ? ($q->fetch() ?: null) : null;
            self::save($existing, $data);
            $out[$existing ? 'updated' : 'created']++;
        }
        Audit::tenant('logbook_authorized.import', 'logbook_authorized', null, null, ['agregados' => $out['created'], 'actualizados' => $out['updated'], 'errores' => count($out['errors'])]);
        return $out;
    }

    /** Para el celular (`sync/pull` entidad `logbook_authorized`). */
    public static function shape(array $a): array
    {
        return ['uuid' => $a['uuid'], 'site_uuid' => $a['site_uuid'] ?? null, 'kind' => $a['kind'], 'person_name' => $a['person_name'], 'person_doc' => $a['person_doc'],
            'company' => $a['company'], 'plate' => $a['plate'], 'reason' => $a['reason'], 'valid_from' => $a['valid_from'], 'valid_until' => $a['valid_until'],
            'days' => $a['days'] ? array_map('intval', explode(',', (string) $a['days'])) : null, 'time_from' => $a['time_from'], 'time_to' => $a['time_to']];
    }
}
