<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\UserError;
use App\Core\Uuid;
use App\Models\NotificationMarks;
use App\Models\Sequences;
use App\Models\Settings;
use App\Services\Notify\Notifier;

/**
 * Libro de guardia (docs/design/libro-de-guardia.md). Cada anotación es inmutable, numerada por predio (folio) y
 * encadenada con la anterior por su huella SHA-256: cualquier alteración posterior se detecta con verify(). Las
 * correcciones y las salidas son anotaciones nuevas que referencian a la original. Las columnas de caché (exited_at,
 * exit_entry_id, received_entry_id, purged_at) no forman parte de la huella.
 */
final class Logbook
{
    public const MODULE = 'libro';
    public const KINDS = ['novedad' => 'Novedad', 'persona' => 'Ingreso de persona', 'vehiculo' => 'Ingreso de vehículo', 'salida' => 'Salida',
        'correccion' => 'Corrección', 'cierre' => 'Cierre de turno', 'recepcion' => 'Recepción del puesto'];
    public const CATEGORIES = ['rutina' => 'Rutina / sin novedad', 'incidente' => 'Incidente', 'alarma' => 'Alarma', 'mantenimiento' => 'Mantenimiento / falla',
        'llamada' => 'Llamada / aviso', 'entrega' => 'Entrega / paquete', 'otro' => 'Otro'];
    public const VEHICLE_TYPES = ['auto' => 'Auto', 'camioneta' => 'Camioneta', 'camion' => 'Camión', 'utilitario' => 'Utilitario', 'moto' => 'Moto', 'otro' => 'Otro'];
    /** Campos que entran en la huella (en este orden). */
    private const HASHED = ['uuid', 'site_id', 'folio', 'kind', 'category', 'text', 'important', 'person_name', 'person_doc', 'company', 'visiting', 'reason',
        'badge', 'plate', 'vehicle_type', 'driver', 'load_note', 'ref_entry_id', 'data', 'signature_sha256', 'lat', 'lng', 'occurred_at', 'user_id', 'created_at'];

    public static function longHours(): int
    {
        return max(1, min(240, (int) Settings::get('libro.horas_adentro', '12')));
    }

    public static function plate(?string $v): ?string
    {
        $p = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $v));
        return $p === '' ? null : mb_substr($p, 0, 15);
    }

    private static function doc(?string $v): ?string
    {
        $d = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $v));
        return $d === '' ? null : mb_substr($d, 0, 30);
    }

    private static function str(array $in, string $k, int $max): ?string
    {
        $v = trim((string) ($in[$k] ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    // ── predios ──────────────────────────────────────────────────

    public static function sites(): array
    {
        return \App\Models\Sites::list();
    }

    public static function site(?string $uuid): ?array
    {
        $s = $uuid ? \App\Models\Sites::findByUuid($uuid) : null;
        if ($s === null) {
            $all = self::sites();
            $s = count($all) === 1 ? $all[0] : null; // una sola planta: no hace falta elegir
        }
        return $s && (int) $s['is_active'] === 1 ? $s : null;
    }

    // ── huella ───────────────────────────────────────────────────

    private static function canonical(array $r): string
    {
        $out = [];
        foreach (self::HASHED as $k) {
            $v = $r[$k] ?? null;
            if ($v !== null && in_array($k, ['site_id', 'folio', 'important', 'ref_entry_id', 'user_id'], true)) $v = (int) $v;
            elseif ($v !== null && in_array($k, ['lat', 'lng'], true)) $v = sprintf('%.7f', (float) $v);
            elseif ($v !== null) $v = (string) $v;
            $out[$k] = $v;
        }
        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function hashOf(?string $prev, array $row): string
    {
        return hash('sha256', ($prev ?? '') . "\n" . self::canonical($row));
    }

    /**
     * Verifica la cadena de un predio: cada anotación apunta a la huella de la anterior y su contenido coincide con su huella
     * (las purgadas solo el encadenamiento). @return array{ok: bool, checked: int, broken_folio: ?int}
     */
    public static function verify(int $siteId, ?int $upToFolio = null): array
    {
        $q = DB::tenant()->prepare('SELECT * FROM logbook_entries WHERE site_id = ?' . ($upToFolio ? ' AND folio <= ' . (int) $upToFolio : '') . ' ORDER BY folio');
        $q->execute([$siteId]);
        $prev = null;
        $n = 0;
        while ($r = $q->fetch()) {
            $n++;
            if ($r['prev_hash'] !== $prev || ($r['purged_at'] === null && !hash_equals($r['hash'], self::hashOf($prev, $r)))) {
                return ['ok' => false, 'checked' => $n, 'broken_folio' => (int) $r['folio']];
            }
            $prev = $r['hash'];
        }
        return ['ok' => true, 'checked' => $n, 'broken_folio' => null];
    }

    // ── alta ─────────────────────────────────────────────────────

    /** Inserta una anotación (en transacción: folio + huella encadenada). @param array $row campos ya validados */
    private static function insert(array $row): array
    {
        $db = DB::tenant();
        $own = !$db->inTransaction();
        if ($own) $db->beginTransaction();
        try {
            $folio = Sequences::next('libro:' . (int) $row['site_id']); // bloquea el predio: un folio a la vez
            $last = $db->prepare('SELECT hash FROM logbook_entries WHERE site_id = ? ORDER BY folio DESC LIMIT 1');
            $last->execute([(int) $row['site_id']]);
            $prev = $last->fetchColumn() ?: null;
            $row += ['category' => null, 'text' => null, 'important' => 0, 'person_name' => null, 'person_doc' => null, 'company' => null, 'visiting' => null,
                'reason' => null, 'badge' => null, 'plate' => null, 'vehicle_type' => null, 'driver' => null, 'load_note' => null, 'ref_entry_id' => null,
                'data' => null, 'signature_path' => null, 'signature_sha256' => null, 'lat' => null, 'lng' => null, 'shift_slot_id' => null, 'source' => 'web'];
            $row['folio'] = $folio;
            $row['created_at'] = gmdate('Y-m-d H:i:s');
            $row['prev_hash'] = $prev;
            $row['hash'] = self::hashOf($prev, $row);
            $cols = array_keys($row);
            $db->prepare('INSERT INTO logbook_entries (' . implode(', ', $cols) . ', updated_at) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', UTC_TIMESTAMP())')
                ->execute(array_values($row));
            if ($own) $db->commit();
        } catch (\Throwable $e) {
            if ($own && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
        return self::find($row['uuid']);
    }

    private static function occurred(array $in): string
    {
        $v = $in['occurred_at'] ?? null;
        $t = is_string($v) && $v !== '' ? strtotime($v) : false;
        if ($t === false) return gmdate('Y-m-d H:i:s');
        if ($t > time() + 300 || $t < time() - 7 * 86400) throw new UserError('La hora de la anotación no es válida (máximo 7 días atrás).');
        return gmdate('Y-m-d H:i:s', $t);
    }

    private static function base(array $in, array $site, string $kind, string $source): array
    {
        $user = UserAuth::user();
        $uuid = strtolower((string) ($in['uuid'] ?? '')) ?: Uuid::v4();
        if (!Uuid::isValid($uuid)) throw new UserError('Identificador inválido.');
        $slot = $user ? ShiftService::current((int) $user['id']) : null;
        return ['uuid' => $uuid, 'site_id' => (int) $site['id'], 'kind' => $kind, 'occurred_at' => self::occurred($in), 'user_id' => $user ? (int) $user['id'] : null,
            'shift_slot_id' => $slot ? (int) $slot['id'] : null, 'source' => $source,
            'lat' => is_numeric($in['lat'] ?? null) ? (float) $in['lat'] : null, 'lng' => is_numeric($in['lng'] ?? null) ? (float) $in['lng'] : null];
    }

    private static function requireCreate(): void
    {
        if (!UserAuth::can(self::MODULE, 'crear')) throw new UserError('Tu rol no puede anotar en el libro de guardia.');
    }

    /**
     * Novedad, ingreso de persona o de vehículo. Idempotente por uuid (la app la manda sin señal).
     * @return array{entry: array, duplicate: bool}
     */
    public static function create(array $in, string $source = 'web'): array
    {
        self::requireCreate();
        if (($u = strtolower((string) ($in['uuid'] ?? ''))) !== '' && ($e = self::find($u)) !== null) return ['entry' => $e, 'duplicate' => true];
        $site = self::site((string) ($in['site_uuid'] ?? $in['site'] ?? '')) ?? throw new UserError('Elegí el predio.');
        $kind = (string) ($in['kind'] ?? '');
        $row = self::base($in, $site, $kind, $source);
        if ($kind === 'novedad') {
            $row['category'] = isset(self::CATEGORIES[$in['category'] ?? '']) ? (string) $in['category'] : 'otro';
            $row['text'] = self::str($in, 'text', 5000) ?? throw new UserError('Escribí la novedad.');
            $row['important'] = !empty($in['important']) ? 1 : 0;
        } elseif ($kind === 'persona') {
            $row += ['person_name' => self::str($in, 'person_name', 150), 'person_doc' => self::doc($in['person_doc'] ?? null), 'company' => self::str($in, 'company', 150),
                'visiting' => self::str($in, 'visiting', 150), 'reason' => self::str($in, 'reason', 255), 'badge' => self::str($in, 'badge', 50),
                'plate' => self::plate($in['plate'] ?? null), 'text' => self::str($in, 'text', 2000)];
            if ($row['person_name'] === null && $row['person_doc'] === null && $row['company'] === null) {
                throw new UserError('Anotá al menos el nombre, el DNI o la empresa de quien ingresa.');
            }
        } elseif ($kind === 'vehiculo') {
            $row += ['plate' => self::plate($in['plate'] ?? null) ?? throw new UserError('Anotá la patente.'),
                'vehicle_type' => isset(self::VEHICLE_TYPES[$in['vehicle_type'] ?? '']) ? (string) $in['vehicle_type'] : null,
                'driver' => self::str($in, 'driver', 150), 'person_doc' => self::doc($in['person_doc'] ?? null), 'company' => self::str($in, 'company', 150),
                'visiting' => self::str($in, 'visiting', 150), 'load_note' => self::str($in, 'load_note', 255), 'text' => self::str($in, 'text', 2000)];
        } else {
            throw new UserError('Tipo de anotación inválido.');
        }
        $entry = self::insert($row);
        self::remember($entry);
        Audit::tenant('logbook.create', 'logbook_entry', $entry['uuid'], null, ['predio' => $site['name'], 'folio' => (int) $entry['folio'], 'tipo' => $kind]);
        if ($kind === 'novedad' && $entry['important']) Notifier::dispatch('logbook.important', self::subject($entry));
        return ['entry' => $entry, 'duplicate' => false];
    }

    /** Salida de una persona o un vehículo que estaba adentro (una sola vez). */
    public static function exit(string $entryUuid, array $in = [], string $source = 'web'): array
    {
        self::requireCreate();
        if (($u = strtolower((string) ($in['uuid'] ?? ''))) !== '' && ($e = self::find($u)) !== null) return ['entry' => $e, 'duplicate' => true];
        $ref = self::find($entryUuid) ?? throw new UserError('No se encontró el ingreso.');
        if (!in_array($ref['kind'], ['persona', 'vehiculo'], true)) throw new UserError('Solo se marca la salida de personas o vehículos.');
        if ($ref['exited_at'] !== null) return ['entry' => self::findById((int) $ref['exit_entry_id']) ?? $ref, 'duplicate' => true];
        $site = \App\Models\Sites::findById((int) $ref['site_id']);
        $db = DB::tenant();
        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT exited_at FROM logbook_entries WHERE id = ? FOR UPDATE');
            $lock->execute([(int) $ref['id']]);
            if ($lock->fetchColumn()) { $db->rollBack(); return ['entry' => self::find($ref['uuid']), 'duplicate' => true]; }
            $row = self::base($in, $site, 'salida', $source) + ['ref_entry_id' => (int) $ref['id'], 'text' => self::str($in, 'text', 1000),
                'load_note' => self::str($in, 'load_note', 255)];
            if ($row['occurred_at'] < $ref['occurred_at']) $row['occurred_at'] = $ref['occurred_at'];
            $entry = self::insert($row);
            $db->prepare('UPDATE logbook_entries SET exited_at = ?, exit_entry_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([$entry['occurred_at'], (int) $entry['id'], (int) $ref['id']]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        Audit::tenant('logbook.exit', 'logbook_entry', $entry['uuid'], null, ['ingreso_folio' => (int) $ref['folio']]);
        return ['entry' => self::find($entry['uuid']), 'duplicate' => false];
    }

    /** Corrección: anotación nueva que referencia a la original (la original no cambia). */
    public static function correct(string $entryUuid, array $in, string $source = 'web'): array
    {
        self::requireCreate();
        if (($u = strtolower((string) ($in['uuid'] ?? ''))) !== '' && ($e = self::find($u)) !== null) return ['entry' => $e, 'duplicate' => true];
        $ref = self::find($entryUuid) ?? throw new UserError('No se encontró la anotación.');
        $site = \App\Models\Sites::findById((int) $ref['site_id']);
        $row = self::base($in, $site, 'correccion', $source) + ['ref_entry_id' => (int) $ref['id'],
            'text' => self::str($in, 'text', 2000) ?? throw new UserError('Escribí la corrección.')];
        $entry = self::insert($row);
        Audit::tenant('logbook.correct', 'logbook_entry', $entry['uuid'], null, ['corrige_folio' => (int) $ref['folio']]);
        return ['entry' => $entry, 'duplicate' => false];
    }

    // ── relevo ───────────────────────────────────────────────────

    /** Resumen del tramo de un guardia: desde su último cierre (o su turno) hasta ahora. */
    public static function summary(int $siteId, int $userId): array
    {
        $db = DB::tenant();
        $q = $db->prepare("SELECT MAX(occurred_at) FROM logbook_entries WHERE site_id = ? AND kind = 'cierre' AND user_id = ?");
        $q->execute([$siteId, $userId]);
        $since = $q->fetchColumn() ?: gmdate('Y-m-d H:i:s', time() - 24 * 3600);
        $slot = ShiftService::current($userId);
        if ($slot && $slot['started_at'] && $slot['started_at'] > $since) $since = $slot['started_at'];
        $c = $db->prepare("SELECT kind, important, COUNT(*) n FROM logbook_entries WHERE site_id = ? AND occurred_at >= ? AND kind IN ('novedad','persona','vehiculo','salida') GROUP BY kind, important");
        $c->execute([$siteId, $since]);
        $counts = ['novedades' => 0, 'importantes' => 0, 'personas' => 0, 'vehiculos' => 0, 'salidas' => 0];
        foreach ($c->fetchAll() as $r) {
            $k = ['novedad' => 'novedades', 'persona' => 'personas', 'vehiculo' => 'vehiculos', 'salida' => 'salidas'][$r['kind']];
            $counts[$k] += (int) $r['n'];
            if ($r['kind'] === 'novedad' && (int) $r['important'] === 1) $counts['importantes'] += (int) $r['n'];
        }
        $inside = array_map(fn ($e) => ['folio' => (int) $e['folio'], 'que' => self::label($e), 'desde' => $e['occurred_at']], self::inside($siteId));
        return ['desde' => $since, 'conteos' => $counts, 'adentro' => $inside];
    }

    /** Cierre de turno: comentario + firma (siempre, aunque esté solo). */
    public static function close(array $in, string $source = 'web'): array
    {
        self::requireCreate();
        if (($u = strtolower((string) ($in['uuid'] ?? ''))) !== '' && ($e = self::find($u)) !== null) return ['entry' => $e, 'duplicate' => true];
        $site = self::site((string) ($in['site_uuid'] ?? $in['site'] ?? '')) ?? throw new UserError('Elegí el predio.');
        $user = UserAuth::user() ?? throw new UserError('Sin usuario.');
        $sig = Signatures::store((string) ($in['signature'] ?? ''), 'logbook-signatures');
        $summary = self::summary((int) $site['id'], (int) $user['id']);
        $row = self::base($in, $site, 'cierre', $source) + ['text' => self::str($in, 'text', 3000) ?? 'Sin novedades.',
            'data' => json_encode($summary, JSON_UNESCAPED_UNICODE), 'signature_path' => $sig['path'], 'signature_sha256' => $sig['sha256']];
        $entry = self::insert($row);
        Audit::tenant('logbook.close', 'logbook_entry', $entry['uuid'], null, ['predio' => $site['name'], 'adentro' => count($summary['adentro'])]);
        if ($summary['adentro']) Notifier::dispatch('logbook.inside_at_close', self::subject($entry) + ['inside' => $summary['adentro']]);
        return ['entry' => $entry, 'duplicate' => false];
    }

    /** Último cierre del predio que nadie recibió (últimas 24 h), para ofrecer la recepción. */
    public static function pendingClose(int $siteId, ?int $exceptUser = null): ?array
    {
        $q = DB::tenant()->prepare("SELECT e.*, u.name AS user_name FROM logbook_entries e LEFT JOIN users u ON u.id = e.user_id
            WHERE e.site_id = ? AND e.kind = 'cierre' AND e.received_entry_id IS NULL AND e.occurred_at >= ? ORDER BY e.folio DESC LIMIT 1");
        $q->execute([$siteId, gmdate('Y-m-d H:i:s', time() - 24 * 3600)]);
        $r = $q->fetch() ?: null;
        return $r && ($exceptUser === null || (int) $r['user_id'] !== $exceptUser) ? $r : null;
    }

    /** Recepción del puesto por el guardia que entra (opcional): firma + observación sobre lo recibido. */
    public static function receive(string $closeUuid, array $in, string $source = 'web'): array
    {
        self::requireCreate();
        if (($u = strtolower((string) ($in['uuid'] ?? ''))) !== '' && ($e = self::find($u)) !== null) return ['entry' => $e, 'duplicate' => true];
        $close = self::find($closeUuid);
        if ($close === null || $close['kind'] !== 'cierre') throw new UserError('No se encontró el cierre de turno.');
        if ($close['received_entry_id'] !== null) throw new UserError('Ese turno ya fue recibido.');
        if ((int) $close['user_id'] === (int) (UserAuth::user()['id'] ?? 0)) throw new UserError('El puesto lo recibe otro guardia.');
        $sig = Signatures::store((string) ($in['signature'] ?? ''), 'logbook-signatures');
        $site = \App\Models\Sites::findById((int) $close['site_id']);
        $row = self::base($in, $site, 'recepcion', $source) + ['ref_entry_id' => (int) $close['id'],
            'text' => self::str($in, 'text', 3000) ?? 'Recibo el puesto de conformidad.', 'signature_path' => $sig['path'], 'signature_sha256' => $sig['sha256']];
        $entry = self::insert($row);
        DB::tenant()->prepare('UPDATE logbook_entries SET received_entry_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND received_entry_id IS NULL')
            ->execute([(int) $entry['id'], (int) $close['id']]);
        Audit::tenant('logbook.receive', 'logbook_entry', $entry['uuid'], null, ['cierre_folio' => (int) $close['folio']]);
        return ['entry' => $entry, 'duplicate' => false];
    }

    // ── consultas ────────────────────────────────────────────────

    private const SELECT = 'SELECT e.*, u.name AS user_name, s.name AS site_name, s.uuid AS site_uuid, r.uuid AS ref_uuid, r.folio AS ref_folio,
            x.uuid AS exit_uuid, (SELECT COUNT(*) FROM logbook_attachments a WHERE a.entry_id = e.id) AS photos
        FROM logbook_entries e JOIN sites s ON s.id = e.site_id LEFT JOIN users u ON u.id = e.user_id
        LEFT JOIN logbook_entries r ON r.id = e.ref_entry_id LEFT JOIN logbook_entries x ON x.id = e.exit_entry_id';

    public static function find(string $uuid): ?array
    {
        $q = DB::tenant()->prepare(self::SELECT . ' WHERE e.uuid = ?');
        $q->execute([strtolower($uuid)]);
        return $q->fetch() ?: null;
    }

    public static function findById(int $id): ?array
    {
        $q = DB::tenant()->prepare(self::SELECT . ' WHERE e.id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    /** Anotaciones de un predio en un día local, por folio. */
    public static function day(int $siteId, string $localDay): array
    {
        [$from, $to] = ShiftPlanner::dayRange($localDay);
        $q = DB::tenant()->prepare(self::SELECT . ' WHERE e.site_id = ? AND e.occurred_at >= ? AND e.occurred_at < ? ORDER BY e.folio');
        $q->execute([$siteId, $from, $to]);
        return $q->fetchAll();
    }

    /** Personas y vehículos con ingreso y sin salida. */
    public static function inside(?int $siteId = null): array
    {
        $q = DB::tenant()->prepare(self::SELECT . " WHERE e.kind IN ('persona', 'vehiculo') AND e.exited_at IS NULL"
            . ($siteId ? ' AND e.site_id = ' . (int) $siteId : '') . ' ORDER BY e.occurred_at');
        $q->execute();
        return $q->fetchAll();
    }

    /** Búsqueda: patente, DNI, nombre, empresa o texto; tipo, predio y fechas (días locales). */
    public static function search(array $f, int $limit = 300): array
    {
        $where = ['1 = 1'];
        $params = [];
        if (($t = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $t) . '%';
            $plate = self::plate($t);
            $where[] = '(e.plate LIKE ? OR e.person_doc LIKE ? OR e.person_name LIKE ? OR e.company LIKE ? OR e.driver LIKE ? OR e.text LIKE ? OR e.visiting LIKE ?)';
            array_push($params, '%' . ($plate ?? $t) . '%', '%' . (self::doc($t) ?? $t) . '%', $like, $like, $like, $like, $like);
        }
        if (isset(self::KINDS[$f['kind'] ?? ''])) { $where[] = 'e.kind = ?'; $params[] = $f['kind']; }
        if (!empty($f['site_id'])) { $where[] = 'e.site_id = ?'; $params[] = (int) $f['site_id']; }
        if (!empty($f['from'])) { $where[] = 'e.occurred_at >= ?'; $params[] = ShiftPlanner::dayRange($f['from'])[0]; }
        if (!empty($f['to'])) { $where[] = 'e.occurred_at < ?'; $params[] = ShiftPlanner::dayRange($f['to'])[1]; }
        $q = DB::tenant()->prepare(self::SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY e.occurred_at DESC, e.id DESC LIMIT ' . $limit);
        $q->execute($params);
        return $q->fetchAll();
    }

    public static function attachments(int $entryId): array
    {
        $q = DB::tenant()->prepare('SELECT * FROM logbook_attachments WHERE entry_id = ? ORDER BY id');
        $q->execute([$entryId]);
        return $q->fetchAll();
    }

    /** Foto (patente, remito, daño): evidencia inmutable con su huella. */
    public static function addAttachment(int $entryId, array $m): string
    {
        $uuid = Uuid::v4();
        DB::tenant()->prepare('INSERT INTO logbook_attachments (uuid, entry_id, path, thumb_path, original_name, mime, size_bytes, sha256, width, height, exif,
            uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$uuid, $entryId, $m['path'], $m['thumb_path'] ?? null, $m['original_name'] ?? null, $m['mime'], $m['size_bytes'], $m['sha256'],
                $m['width'] ?? null, $m['height'] ?? null, $m['exif'] ?? null, $m['uploaded_by'] ?? null]);
        DB::tenant()->prepare('UPDATE logbook_entries SET updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$entryId]);
        return $uuid;
    }

    /** "AB123CD · Camión · Transportes X" o "Juan Pérez (DNI 30111222) · Empresa". */
    public static function label(array $e): string
    {
        if ($e['purged_at'] !== null) return '(datos borrados)';
        if ($e['kind'] === 'vehiculo') {
            return implode(' · ', array_filter([$e['plate'], self::VEHICLE_TYPES[$e['vehicle_type'] ?? ''] ?? null, $e['driver'], $e['company']]));
        }
        $who = trim(($e['person_name'] ?? '') . ($e['person_doc'] ? ' (DNI ' . $e['person_doc'] . ')' : ''));
        return implode(' · ', array_filter([$who ?: null, $e['company']]));
    }

    // ── padrón para autocompletar ────────────────────────────────

    private static function remember(array $e): void
    {
        $data = null;
        if ($e['kind'] === 'persona' && $e['person_doc']) {
            [$type, $key, $data] = ['persona', $e['person_doc'], ['name' => $e['person_name'], 'doc' => $e['person_doc'], 'company' => $e['company'], 'plate' => $e['plate']]];
        } elseif ($e['kind'] === 'vehiculo' && $e['plate']) {
            [$type, $key, $data] = ['vehiculo', $e['plate'], ['plate' => $e['plate'], 'type' => $e['vehicle_type'], 'driver' => $e['driver'], 'doc' => $e['person_doc'], 'company' => $e['company']]];
        }
        if ($data === null) return;
        DB::tenant()->prepare('INSERT INTO logbook_known (uuid, type, lookup_key, data, visits, last_seen_at, is_active, updated_at) VALUES (?, ?, ?, ?, 1, ?, 1, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE data = VALUES(data), visits = visits + 1, last_seen_at = VALUES(last_seen_at), is_active = 1, updated_at = UTC_TIMESTAMP()')
            ->execute([Uuid::v4(), $type, $key, json_encode(array_filter($data, fn ($v) => $v !== null), JSON_UNESCAPED_UNICODE), $e['occurred_at']]);
    }

    /** Sugerencias para autocompletar por DNI o patente (prefijo). */
    public static function lookup(string $type, string $prefix, int $limit = 8): array
    {
        $key = $type === 'vehiculo' ? self::plate($prefix) : self::doc($prefix);
        if ($key === null || strlen($key) < 2) return [];
        $q = DB::tenant()->prepare("SELECT data, visits, last_seen_at FROM logbook_known WHERE type = ? AND is_active = 1 AND lookup_key LIKE ? ORDER BY last_seen_at DESC LIMIT " . $limit);
        $q->execute([$type, $key . '%']);
        return array_map(fn ($r) => (json_decode((string) $r['data'], true) ?: []) + ['visits' => (int) $r['visits'], 'last_seen_at' => $r['last_seen_at']], $q->fetchAll());
    }

    // ── avisos y purga ───────────────────────────────────────────

    private static function subject(array $e): array
    {
        return $e + ['label' => self::label($e)];
    }

    /** Cron: personas o vehículos adentro hace más de N horas → un aviso por predio y día. */
    public static function run(?int $now = null): array
    {
        $now ??= time();
        $limit = gmdate('Y-m-d H:i:s', $now - self::longHours() * 3600);
        $q = DB::tenant()->prepare("SELECT site_id, COUNT(*) n, MIN(occurred_at) oldest FROM logbook_entries WHERE kind IN ('persona','vehiculo') AND exited_at IS NULL
            AND purged_at IS NULL AND occurred_at < ? GROUP BY site_id");
        $q->execute([$limit]);
        $sent = 0;
        $previous = UserAuth::user();
        UserAuth::setCurrent(null);
        try {
            foreach ($q->fetchAll() as $r) {
                $day = ShiftPlanner::localDay(gmdate('Y-m-d H:i:s', $now));
                if (!NotificationMarks::claim("logbook_long:{$r['site_id']}:{$day}")) continue;
                $site = \App\Models\Sites::findById((int) $r['site_id']);
                Notifier::dispatch('logbook.inside_long', ['id' => 0, 'uuid' => '', 'site_id' => (int) $r['site_id'], 'site_name' => $site['name'] ?? '',
                    'site_uuid' => $site['uuid'] ?? '', 'count' => (int) $r['n'], 'oldest' => $r['oldest'], 'hours' => self::longHours()]);
                $sent++;
            }
        } finally {
            UserAuth::setCurrent($previous);
        }
        return ['inside_long' => $sent];
    }

    /**
     * Borra los datos personales (nombre, DNI, patente, conductor, a quién visita) de las anotaciones anteriores a un día.
     * Decisión del usuario: por defecto se guardan para siempre; esto lo hace él, a su riesgo. @return int anotaciones purgadas
     */
    public static function purge(string $beforeDay): int
    {
        if (!UserAuth::can(self::MODULE, 'editar') || UserAuth::scope(self::MODULE) !== 'todo') throw new UserError('No tenés permiso para borrar datos del libro.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $beforeDay)) throw new UserError('Fecha inválida.');
        [$until] = ShiftPlanner::dayRange($beforeDay);
        $u = DB::tenant()->prepare("UPDATE logbook_entries SET person_name = NULL, person_doc = NULL, plate = NULL, driver = NULL, visiting = NULL, badge = NULL,
            purged_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE kind IN ('persona','vehiculo') AND occurred_at < ? AND purged_at IS NULL");
        $u->execute([$until]);
        DB::tenant()->prepare('DELETE FROM logbook_known WHERE last_seen_at < ?')->execute([$until]);
        Audit::tenant('logbook.purge', 'logbook', null, null, ['antes_de' => $beforeDay, 'anotaciones' => $u->rowCount()]);
        return $u->rowCount();
    }

    /** Respuesta para la app. */
    public static function shape(array $e): array
    {
        $iso = fn ($v) => $v ? str_replace(' ', 'T', $v) . 'Z' : null;
        return ['uuid' => $e['uuid'], 'site_uuid' => $e['site_uuid'], 'folio' => (int) $e['folio'], 'kind' => $e['kind'], 'category' => $e['category'],
            'text' => $e['text'], 'important' => (bool) $e['important'], 'person_name' => $e['person_name'], 'person_doc' => $e['person_doc'],
            'company' => $e['company'], 'visiting' => $e['visiting'], 'reason' => $e['reason'], 'badge' => $e['badge'], 'plate' => $e['plate'],
            'vehicle_type' => $e['vehicle_type'], 'driver' => $e['driver'], 'load_note' => $e['load_note'], 'ref_uuid' => $e['ref_uuid'],
            'occurred_at' => $iso($e['occurred_at']), 'user_name' => $e['user_name'], 'exited_at' => $iso($e['exited_at']),
            'received' => $e['received_entry_id'] !== null, 'data' => $e['data'] ? json_decode((string) $e['data'], true) : null,
            'mine' => (int) $e['user_id'] === (int) (UserAuth::user()['id'] ?? 0), 'label' => self::label($e), 'photos' => (int) $e['photos']];
    }
}
