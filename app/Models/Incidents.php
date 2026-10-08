<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Incidentes y accidentes: el hecho, las personas, la línea de tiempo y los adjuntos. */
final class Incidents
{
    private const SELECT = 'SELECT i.*, si.name AS site_name, se.name AS sector_name, eq.code AS equipment_code, eq.name AS equipment_name,
            sv.name AS severity_name, sv.color AS severity_color, sv.level AS severity_level, ru.name AS reporter_name,
            (SELECT COUNT(*) FROM incident_people p WHERE p.incident_id = i.id AND p.role = \'lesionado\') AS injured_count,
            (SELECT COUNT(*) FROM incident_people p WHERE p.incident_id = i.id AND p.lost_time = 1 AND p.discharge_date IS NULL) AS open_leaves
        FROM incidents i
        LEFT JOIN sites si ON si.id = i.site_id
        LEFT JOIN sectors se ON se.id = i.sector_id
        LEFT JOIN equipment eq ON eq.id = i.equipment_id
        LEFT JOIN catalog_items sv ON sv.id = i.potential_severity_id
        LEFT JOIN users ru ON ru.id = i.reported_by';

    /**
     * @param array $f type (string|list), status (string|list), sector_ids, from/to (UTC), open_leave (bool), q
     * @param ?array $scope null = todo; ['own' => user_id]; ['sector_ids' => list<int>, 'user_id' => int]
     */
    public static function search(array $f, ?array $scope, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::where($f, $scope);
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY i.occurred_at DESC, i.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset));
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function count(array $f, ?array $scope): int
    {
        [$where, $params] = self::where($f, $scope);
        $stmt = DB::tenant()->prepare('SELECT COUNT(*) FROM incidents i WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function countByType(array $f, ?array $scope): array
    {
        unset($f['type']);
        [$where, $params] = self::where($f, $scope);
        $stmt = DB::tenant()->prepare('SELECT i.type, COUNT(*) AS n FROM incidents i WHERE ' . implode(' AND ', $where) . ' GROUP BY i.type');
        $stmt->execute($params);
        return array_map('intval', array_column($stmt->fetchAll(), 'n', 'type'));
    }

    private static function where(array $f, ?array $scope): array
    {
        $where = ['i.deleted_at IS NULL'];
        $params = [];
        if ($scope !== null) {
            if (isset($scope['own'])) {
                $where[] = 'i.reported_by = ?';
                $params[] = $scope['own'];
            } else {
                $ids = $scope['sector_ids'] ?: [0];
                $where[] = '(i.sector_id IN (' . implode(',', array_map('intval', $ids)) . ') OR i.reported_by = ?)';
                $params[] = $scope['user_id'];
            }
        }
        foreach (['type', 'status'] as $col) {
            if (isset($f[$col]) && $f[$col] !== '' && $f[$col] !== []) {
                $values = (array) $f[$col];
                $where[] = "i.{$col} IN (" . implode(',', array_fill(0, count($values), '?')) . ')';
                array_push($params, ...$values);
            }
        }
        if (!empty($f['sector_ids'])) {
            $where[] = 'i.sector_id IN (' . implode(',', array_map('intval', $f['sector_ids'])) . ')';
        }
        foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
            if (!empty($f[$k])) {
                $where[] = "i.occurred_at {$op} ?";
                $params[] = $f[$k];
            }
        }
        if (!empty($f['open_leave'])) {
            $where[] = 'EXISTS (SELECT 1 FROM incident_people p WHERE p.incident_id = i.id AND p.lost_time = 1 AND p.discharge_date IS NULL)';
        }
        if (($f['q'] ?? '') !== '') {
            $q = trim((string) $f['q']);
            if (preg_match('/^(?:INC-?)?0*(\d+)$/i', $q, $m)) {
                $where[] = 'i.number = ?';
                $params[] = (int) $m[1];
            } else {
                $where[] = '(i.description LIKE ? OR EXISTS (SELECT 1 FROM incident_people p LEFT JOIN employees em ON em.id = p.employee_id
                    WHERE p.incident_id = i.id AND (em.last_name LIKE ? OR em.first_name LIKE ? OR p.external_name LIKE ?)))';
                array_push($params, "%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%");
            }
        }
        return [$where, $params];
    }

    public static function findByUuid(string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE i.uuid = ? AND i.deleted_at IS NULL');
        $stmt->execute([$uuid]);
        return $stmt->fetch() ?: null;
    }

    public static function findById(int $id, bool $lock = false): ?array
    {
        $stmt = DB::tenant()->prepare(($lock ? 'SELECT * FROM incidents i' : self::SELECT) . ' WHERE i.id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data): int
    {
        $data += ['uuid' => Uuid::v4()];
        $cols = array_keys($data);
        DB::tenant()->prepare('INSERT INTO incidents (`' . implode('`, `', $cols) . '`, received_at, created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(array_values($data));
        return (int) DB::tenant()->lastInsertId();
    }

    /** Solo valores vigentes (estado, clasificación). El reporte original y su hash NUNCA. */
    public static function update(int $id, array $data): void
    {
        unset($data['original_data'], $data['original_hash'], $data['description'], $data['number'], $data['uuid'], $data['reported_by']);
        $sets = implode(', ', array_map(fn ($c) => "`{$c}` = ?", array_keys($data)));
        DB::tenant()->prepare('UPDATE incidents SET ' . ($sets ? $sets . ', ' : '') . 'updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([...array_values($data), $id]);
    }

    /**
     * Días desde el último accidente con baja (o in itinere) que no se anuló. null = nunca hubo.
     * @return array{days: ?int, last: ?string} last = fecha UTC del hecho
     */
    public static function daysWithoutLostTime(string $todayLocal, string $tz, ?int $siteId = null): array
    {
        $sql = "SELECT MAX(occurred_at) FROM incidents WHERE type IN ('accidente_con_baja', 'in_itinere') AND status <> 'anulado' AND deleted_at IS NULL"
            . ($siteId !== null ? ' AND site_id = ?' : '');
        $stmt = DB::tenant()->prepare($sql);
        $stmt->execute($siteId !== null ? [$siteId] : []);
        $last = $stmt->fetchColumn() ?: null;
        if ($last === null) {
            return ['days' => null, 'last' => null];
        }
        $lastLocal = (new \DateTimeImmutable($last, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($tz))->format('Y-m-d');
        return ['days' => (int) (new \DateTimeImmutable($lastLocal))->diff(new \DateTimeImmutable($todayLocal))->format('%a'), 'last' => $last];
    }

    // ── personas ───────────────────────────────────────────────────

    public static function people(int $incidentId): array
    {
        $stmt = DB::tenant()->prepare("SELECT p.*, em.uuid AS employee_uuid, em.last_name, em.first_name, em.dni AS employee_dni, em.cuil, em.birth_date,
                em.gender, em.address, em.hire_date, em.file_number, po.name AS position_name, co.name AS contractor_name,
                lt.name AS injury_type_name, bp.name AS body_part_name, fa.name AS accident_form_name
            FROM incident_people p
            LEFT JOIN employees em ON em.id = p.employee_id
            LEFT JOIN positions po ON po.id = em.position_id
            LEFT JOIN contractors co ON co.id = em.contractor_id
            LEFT JOIN catalog_items lt ON lt.id = p.injury_type_id
            LEFT JOIN catalog_items bp ON bp.id = p.body_part_id
            LEFT JOIN catalog_items fa ON fa.id = p.accident_form_id
            WHERE p.incident_id = ? ORDER BY FIELD(p.role, 'lesionado', 'involucrado', 'testigo'), p.id");
        $stmt->execute([$incidentId]);
        return $stmt->fetchAll();
    }

    public static function person(int $incidentId, string $uuid): ?array
    {
        foreach (self::people($incidentId) as $p) {
            if ($p['uuid'] === $uuid) {
                return $p;
            }
        }
        return null;
    }

    public static function addPerson(int $incidentId, array $data): int
    {
        $data += ['uuid' => Uuid::v4(), 'incident_id' => $incidentId];
        $cols = array_keys($data);
        DB::tenant()->prepare('INSERT INTO incident_people (`' . implode('`, `', $cols) . '`, created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(array_values($data));
        return (int) DB::tenant()->lastInsertId();
    }

    public static function updatePerson(int $personId, array $data): void
    {
        unset($data['uuid'], $data['incident_id']);
        $sets = implode(', ', array_map(fn ($c) => "`{$c}` = ?", array_keys($data)));
        DB::tenant()->prepare('UPDATE incident_people SET ' . ($sets ? $sets . ', ' : '') . 'updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([...array_values($data), $personId]);
    }

    /** Lesionados con baja abierta (sin alta), de todos los incidentes no anulados. */
    public static function openLeaves(): array
    {
        return DB::tenant()->query("SELECT p.*, i.uuid AS incident_uuid, i.number, i.occurred_at, em.last_name, em.first_name
            FROM incident_people p JOIN incidents i ON i.id = p.incident_id LEFT JOIN employees em ON em.id = p.employee_id
            WHERE p.lost_time = 1 AND p.discharge_date IS NULL AND i.status <> 'anulado' AND i.deleted_at IS NULL ORDER BY p.leave_start")->fetchAll();
    }

    // ── investigación ──────────────────────────────────────────────

    /** La investigación con sus partes ya decodificadas (null = todavía no empezó). */
    public static function investigation(int $incidentId): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT v.*, su.name AS started_by_name, cu.name AS completed_by_name FROM incident_investigations v
            LEFT JOIN users su ON su.id = v.started_by LEFT JOIN users cu ON cu.id = v.completed_by WHERE v.incident_id = ?');
        $stmt->execute([$incidentId]);
        $row = $stmt->fetch() ?: null;
        if ($row) {
            foreach (['team' => [], 'five_whys' => ['problem' => '', 'whys' => []], 'cause_tree' => [], 'root_causes' => []] as $k => $default) {
                $row[$k] = json_decode((string) $row[$k], true) ?: $default;
            }
        }
        return $row;
    }

    public static function startInvestigation(int $incidentId, ?int $userId): void
    {
        DB::tenant()->prepare('INSERT IGNORE INTO incident_investigations (incident_id, team, started_at, started_by, created_at, updated_at)
            VALUES (?, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute([$incidentId, json_encode($userId ? [$userId] : []), $userId]);
    }

    public static function saveInvestigation(int $incidentId, array $data): void
    {
        foreach (['team', 'five_whys', 'cause_tree', 'root_causes'] as $k) {
            if (array_key_exists($k, $data)) {
                $data[$k] = json_encode($data[$k], JSON_UNESCAPED_UNICODE);
            }
        }
        $sets = implode(', ', array_map(fn ($c) => "`{$c}` = ?", array_keys($data)));
        DB::tenant()->prepare("UPDATE incident_investigations SET {$sets}, updated_at = UTC_TIMESTAMP() WHERE incident_id = ?")
            ->execute([...array_values($data), $incidentId]);
    }

    /** Incidentes con investigación obligatoria todavía sin empezar, ocurridos antes de $before (UTC). */
    public static function pendingInvestigation(array $types, string $before): array
    {
        $stmt = DB::tenant()->prepare(self::SELECT . " WHERE i.status = 'reportado' AND i.deleted_at IS NULL AND i.occurred_at < ?
            AND i.type IN (" . implode(',', array_fill(0, count($types), '?')) . ')');
        $stmt->execute([$before, ...$types]);
        return $stmt->fetchAll();
    }

    /** Incidentes (no anulados, ocurridos antes de $before) con algún lesionado sin N° de siniestro de la ART. */
    public static function missingArtCase(string $before): array
    {
        $stmt = DB::tenant()->prepare(self::SELECT . " WHERE i.status <> 'anulado' AND i.deleted_at IS NULL AND i.occurred_at < ?
            AND EXISTS (SELECT 1 FROM incident_people p WHERE p.incident_id = i.id AND p.role = 'lesionado' AND p.art_case_number IS NULL)");
        $stmt->execute([$before]);
        return $stmt->fetchAll();
    }

    /** Accidentes y lesionados de un rango (para los índices). */
    public static function forIndicators(string $fromUtc, string $toUtc): array
    {
        $stmt = DB::tenant()->prepare("SELECT i.id, i.type, i.occurred_at, i.site_id, p.lost_time, p.leave_start, p.discharge_date
            FROM incidents i LEFT JOIN incident_people p ON p.incident_id = i.id AND p.role = 'lesionado'
            WHERE i.status <> 'anulado' AND i.deleted_at IS NULL AND i.occurred_at >= ? AND i.occurred_at < ?");
        $stmt->execute([$fromUtc, $toUtc]);
        return $stmt->fetchAll();
    }

    // ── eventos y adjuntos ─────────────────────────────────────────

    public static function addEvent(int $incidentId, string $type, array $f = []): void
    {
        DB::tenant()->prepare('INSERT INTO incident_events (uuid, incident_id, type, from_status, to_status, comment, data, health, user_id, actor_name, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')->execute([
            Uuid::v4(), $incidentId, $type, $f['from'] ?? null, $f['to'] ?? null, $f['comment'] ?? null,
            isset($f['data']) ? json_encode($f['data'], JSON_UNESCAPED_UNICODE) : null, !empty($f['health']) ? 1 : 0,
            $f['user_id'] ?? null, $f['actor_name'] ?? null,
        ]);
    }

    public static function events(int $incidentId, bool $withHealth): array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM incident_events WHERE incident_id = ?' . ($withHealth ? '' : ' AND health = 0') . ' ORDER BY id');
        $stmt->execute([$incidentId]);
        return $stmt->fetchAll();
    }

    public static function addAttachment(int $incidentId, bool $health, array $m): string
    {
        $uuid = Uuid::v4();
        DB::tenant()->prepare('INSERT INTO incident_attachments (uuid, incident_id, health, path, thumb_path, original_name, mime, size_bytes, sha256,
            width, height, exif, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$uuid, $incidentId, $health ? 1 : 0, $m['path'], $m['thumb_path'] ?? null, $m['original_name'] ?? null, $m['mime'], $m['size_bytes'],
                $m['sha256'], $m['width'] ?? null, $m['height'] ?? null, $m['exif'] ?? null, $m['uploaded_by'] ?? null]);
        return $uuid;
    }

    public static function attachments(int $incidentId, bool $withHealth): array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM incident_attachments WHERE incident_id = ?' . ($withHealth ? '' : ' AND health = 0') . ' ORDER BY id');
        $stmt->execute([$incidentId]);
        return $stmt->fetchAll();
    }

    public static function attachment(int $incidentId, string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM incident_attachments WHERE incident_id = ? AND uuid = ?');
        $stmt->execute([$incidentId, $uuid]);
        return $stmt->fetch() ?: null;
    }

    public static function format(int $number): string
    {
        return 'INC-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
