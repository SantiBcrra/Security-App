<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Permisos de trabajo: el permiso, ejecutores, checklists, firmas y línea de tiempo. */
final class WorkPermits
{
    public const ACTIVE = ['aprobado', 'en_ejecucion', 'suspendido'];

    private const SELECT = 'SELECT w.*, si.name AS site_name, si.uuid AS site_uuid, se.name AS sector_name, eq.code AS equipment_code, eq.name AS equipment_name,
            co.name AS contractor_name, ru.name AS requested_by_name, au.name AS approved_by_name, cu.name AS closed_by_name,
            COALESCE(w.extended_until, w.valid_until) AS ends_at
        FROM work_permits w
        LEFT JOIN sites si ON si.id = w.site_id
        LEFT JOIN sectors se ON se.id = w.sector_id
        LEFT JOIN equipment eq ON eq.id = w.equipment_id
        LEFT JOIN contractors co ON co.id = w.contractor_id
        LEFT JOIN users ru ON ru.id = w.requested_by
        LEFT JOIN users au ON au.id = w.approved_by
        LEFT JOIN users cu ON cu.id = w.closed_by';

    /**
     * @param array $f status (string|list), type, site_id, sector_ids, from/to (valid_from UTC), q
     * @param ?array $scope null = todo; ['own' => user_id]; ['sector_ids' => list<int>, 'user_id' => int]
     */
    public static function search(array $f, ?array $scope, int $limit = 100): array
    {
        [$where, $params] = self::where($f, $scope);
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY w.valid_from DESC, w.id DESC LIMIT ' . max(1, $limit));
        $stmt->execute($params);
        return array_map([self::class, 'decode'], $stmt->fetchAll());
    }

    public static function count(array $f, ?array $scope): int
    {
        [$where, $params] = self::where($f, $scope);
        $stmt = DB::tenant()->prepare('SELECT COUNT(*) FROM work_permits w WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private static function where(array $f, ?array $scope): array
    {
        $where = ['w.deleted_at IS NULL'];
        $params = [];
        if ($scope !== null) {
            if (isset($scope['own'])) {
                $where[] = '(w.requested_by = ? OR w.approved_by = ?)';
                array_push($params, $scope['own'], $scope['own']);
            } else {
                $ids = $scope['sector_ids'] ?: [0];
                $where[] = '(w.sector_id IN (' . implode(',', array_map('intval', $ids)) . ') OR w.requested_by = ? OR w.approved_by = ?)';
                array_push($params, $scope['user_id'], $scope['user_id']);
            }
        }
        if (isset($f['status']) && $f['status'] !== '' && $f['status'] !== []) {
            $values = (array) $f['status'];
            $where[] = 'w.status IN (' . implode(',', array_fill(0, count($values), '?')) . ')';
            array_push($params, ...$values);
        }
        if (!empty($f['type'])) {
            $where[] = 'w.types LIKE ?';
            $params[] = '%"' . $f['type'] . '"%';
        }
        if (!empty($f['site_id'])) {
            $where[] = 'w.site_id = ?';
            $params[] = (int) $f['site_id'];
        }
        if (!empty($f['sector_ids'])) {
            $where[] = 'w.sector_id IN (' . implode(',', array_map('intval', $f['sector_ids'])) . ')';
        }
        if (!empty($f['equipment_id'])) {
            $where[] = 'w.equipment_id = ?';
            $params[] = (int) $f['equipment_id'];
        }
        if (!empty($f['ends_before'])) {
            $where[] = 'COALESCE(w.extended_until, w.valid_until) < ?';
            $params[] = $f['ends_before'];
        }
        if (!empty($f['ends_after'])) {
            $where[] = 'COALESCE(w.extended_until, w.valid_until) >= ?';
            $params[] = $f['ends_after'];
        }
        if (($f['q'] ?? '') !== '') {
            $q = trim((string) $f['q']);
            if (preg_match('/^(?:PT-?)?0*(\d+)$/i', $q, $m)) {
                $where[] = 'w.number = ?';
                $params[] = (int) $m[1];
            } else {
                $where[] = '(w.task LIKE ? OR w.location_text LIKE ?)';
                array_push($params, "%{$q}%", "%{$q}%");
            }
        }
        return [$where, $params];
    }

    public static function findByUuid(string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE w.uuid = ? AND w.deleted_at IS NULL');
        $stmt->execute([$uuid]);
        return self::decode($stmt->fetch() ?: null);
    }

    public static function findById(int $id, bool $lock = false): ?array
    {
        $stmt = DB::tenant()->prepare(($lock ? 'SELECT w.*, COALESCE(w.extended_until, w.valid_until) AS ends_at FROM work_permits w' : self::SELECT)
            . ' WHERE w.id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        return self::decode($stmt->fetch() ?: null);
    }

    private static function decode(?array $row): ?array
    {
        if ($row !== null) {
            $row['type_list'] = json_decode((string) $row['types'], true) ?: [];
        }
        return $row;
    }

    public static function create(array $data): int
    {
        $data += ['uuid' => Uuid::v4()];
        $cols = array_keys($data);
        DB::tenant()->prepare('INSERT INTO work_permits (`' . implode('`, `', $cols) . '`, created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(array_values($data));
        return (int) DB::tenant()->lastInsertId();
    }

    /** Lo congelado (tipos, tarea, lugar, original) no cambia: solo estado, fechas de ejecución y quién. */
    public static function update(int $id, array $data): void
    {
        unset($data['uuid'], $data['number'], $data['original_data'], $data['original_hash'], $data['types'], $data['task'], $data['requested_by']);
        $sets = implode(', ', array_map(fn ($c) => "`{$c}` = ?", array_keys($data)));
        DB::tenant()->prepare('UPDATE work_permits SET ' . ($sets ? $sets . ', ' : '') . 'updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([...array_values($data), $id]);
    }

    // ── ejecutores, checklists, firmas, eventos ────────────────────

    public static function addWorker(int $permitId, array $w): int
    {
        DB::tenant()->prepare('INSERT INTO work_permit_workers (uuid, permit_id, role, employee_id, external_name, external_dni, created_at)
            VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([Uuid::v4(), $permitId, $w['role'] ?? 'ejecutor', $w['employee_id'] ?? null, $w['external_name'] ?? null, $w['external_dni'] ?? null]);
        return (int) DB::tenant()->lastInsertId();
    }

    /** Ejecutores con su firma de inicio (si ya firmaron). */
    public static function workers(int $permitId): array
    {
        $stmt = DB::tenant()->prepare("SELECT k.*, em.last_name, em.first_name, em.dni AS employee_dni, co.name AS contractor_name,
                s.uuid AS signature_uuid, s.signed_at
            FROM work_permit_workers k
            LEFT JOIN employees em ON em.id = k.employee_id
            LEFT JOIN contractors co ON co.id = em.contractor_id
            LEFT JOIN work_permit_signatures s ON s.worker_id = k.id AND s.role IN ('ejecutor', 'vigia')
            WHERE k.permit_id = ? ORDER BY k.role, k.id");
        $stmt->execute([$permitId]);
        return $stmt->fetchAll();
    }

    public static function addChecklist(int $permitId, string $type, int $versionId, array $rows, int $fails, int $critical): void
    {
        DB::tenant()->prepare('INSERT INTO work_permit_checklists (permit_id, permit_type, template_version_id, answers, fails, critical_fails, created_at)
            VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')->execute([$permitId, $type, $versionId, json_encode($rows, JSON_UNESCAPED_UNICODE), $fails, $critical]);
    }

    public static function checklists(int $permitId): array
    {
        $stmt = DB::tenant()->prepare('SELECT c.*, v.version, t.name AS template_name FROM work_permit_checklists c
            JOIN inspection_template_versions v ON v.id = c.template_version_id JOIN inspection_templates t ON t.id = v.template_id
            WHERE c.permit_id = ? ORDER BY c.id');
        $stmt->execute([$permitId]);
        return array_map(fn ($r) => ['answers' => json_decode((string) $r['answers'], true) ?: []] + $r, $stmt->fetchAll());
    }

    public static function addSignature(int $permitId, array $s): string
    {
        $uuid = Uuid::v4();
        DB::tenant()->prepare('INSERT INTO work_permit_signatures (uuid, permit_id, role, worker_id, user_id, signer_name, signer_dni, path, sha256, ip, user_agent,
            lat, lng, signed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$uuid, $permitId, $s['role'], $s['worker_id'] ?? null, $s['user_id'] ?? null, mb_substr($s['signer_name'], 0, 160), $s['signer_dni'] ?? null,
                $s['path'], $s['sha256'], $s['ip'] ?? null, isset($s['user_agent']) ? mb_substr((string) $s['user_agent'], 0, 191) : null, $s['lat'] ?? null, $s['lng'] ?? null]);
        return $uuid;
    }

    public static function signatures(int $permitId): array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM work_permit_signatures WHERE permit_id = ? ORDER BY id');
        $stmt->execute([$permitId]);
        return $stmt->fetchAll();
    }

    public static function signature(int $permitId, string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM work_permit_signatures WHERE permit_id = ? AND uuid = ?');
        $stmt->execute([$permitId, $uuid]);
        return $stmt->fetch() ?: null;
    }

    public static function addEvent(int $permitId, string $type, array $f = []): void
    {
        DB::tenant()->prepare('INSERT INTO work_permit_events (uuid, permit_id, type, from_status, to_status, comment, data, user_id, actor_name, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')->execute([Uuid::v4(), $permitId, $type, $f['from'] ?? null, $f['to'] ?? null,
            $f['comment'] ?? null, isset($f['data']) ? json_encode($f['data'], JSON_UNESCAPED_UNICODE) : null, $f['user_id'] ?? null, $f['actor_name'] ?? null]);
    }

    public static function events(int $permitId): array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM work_permit_events WHERE permit_id = ? ORDER BY id');
        $stmt->execute([$permitId]);
        return $stmt->fetchAll();
    }

    public static function format(int $number): string
    {
        return 'PT-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
