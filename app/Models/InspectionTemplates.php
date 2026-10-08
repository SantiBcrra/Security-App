<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Plantillas de checklist y sus versiones (la estructura de una versión usada no cambia nunca). */
final class InspectionTemplates
{
    private const SELECT = 'SELECT t.*, ty.name AS equipment_type_name, ty.uuid AS equipment_type_uuid,
            v.version AS current_version, v.item_count, v.uuid AS current_version_uuid,
            (SELECT COUNT(*) FROM inspections i WHERE i.template_id = t.id) AS inspections_count
        FROM inspection_templates t
        LEFT JOIN catalog_items ty ON ty.id = t.equipment_type_id
        LEFT JOIN inspection_template_versions v ON v.id = t.current_version_id';

    public static function all(bool $onlyActive = false): array
    {
        return DB::tenant()->query(self::SELECT . ' WHERE t.deleted_at IS NULL' . ($onlyActive ? ' AND t.is_active = 1' : '')
            . ' ORDER BY t.is_active DESC, t.name')->fetchAll();
    }

    public static function findByUuid(string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE t.uuid = ? AND t.deleted_at IS NULL');
        $stmt->execute([$uuid]);
        return $stmt->fetch() ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE t.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Plantillas activas que aplican a un tipo de equipo. */
    public static function forEquipmentType(?int $typeId): array
    {
        if ($typeId === null) {
            return [];
        }
        $stmt = DB::tenant()->prepare(self::SELECT . " WHERE t.deleted_at IS NULL AND t.is_active = 1 AND t.scope = 'equipo' AND t.equipment_type_id = ? ORDER BY t.name");
        $stmt->execute([$typeId]);
        return $stmt->fetchAll();
    }

    public static function findByPreset(string $key): ?array
    {
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE t.preset_key = ? AND t.deleted_at IS NULL');
        $stmt->execute([$key]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data): int
    {
        $data += ['uuid' => Uuid::v4()];
        $cols = array_keys($data);
        DB::tenant()->prepare('INSERT INTO inspection_templates (`' . implode('`, `', $cols) . '`, created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(array_values($data));
        return (int) DB::tenant()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        unset($data['uuid'], $data['preset_key']);
        $sets = implode(', ', array_map(fn ($c) => "`{$c}` = ?", array_keys($data)));
        DB::tenant()->prepare('UPDATE inspection_templates SET ' . ($sets ? $sets . ', ' : '') . 'updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([...array_values($data), $id]);
    }

    public static function version(int $versionId): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM inspection_template_versions WHERE id = ?');
        $stmt->execute([$versionId]);
        $row = $stmt->fetch() ?: null;
        if ($row) {
            $row['structure'] = json_decode((string) $row['structure'], true) ?: ['sections' => []];
        }
        return $row;
    }

    public static function versions(int $templateId): array
    {
        $stmt = DB::tenant()->prepare('SELECT v.id, v.uuid, v.version, v.item_count, v.created_at, u.name AS created_by_name,
                (SELECT COUNT(*) FROM inspections i WHERE i.template_version_id = v.id) AS used
            FROM inspection_template_versions v LEFT JOIN users u ON u.id = v.created_by WHERE v.template_id = ? ORDER BY v.version DESC');
        $stmt->execute([$templateId]);
        return $stmt->fetchAll();
    }

    public static function versionUsed(int $versionId): bool
    {
        $stmt = DB::tenant()->prepare('SELECT 1 FROM inspections WHERE template_version_id = ? LIMIT 1');
        $stmt->execute([$versionId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function addVersion(int $templateId, array $structure, int $itemCount, ?int $userId): int
    {
        $next = (int) DB::tenant()->query('SELECT COALESCE(MAX(version), 0) + 1 FROM inspection_template_versions WHERE template_id = ' . $templateId)->fetchColumn();
        DB::tenant()->prepare('INSERT INTO inspection_template_versions (uuid, template_id, version, structure, item_count, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([Uuid::v4(), $templateId, $next, json_encode($structure, JSON_UNESCAPED_UNICODE), $itemCount, $userId]);
        return (int) DB::tenant()->lastInsertId();
    }

    /** Solo para una versión que todavía no se usó en ninguna inspección. */
    public static function replaceVersionStructure(int $versionId, array $structure, int $itemCount): void
    {
        DB::tenant()->prepare('UPDATE inspection_template_versions SET structure = ?, item_count = ? WHERE id = ?')
            ->execute([json_encode($structure, JSON_UNESCAPED_UNICODE), $itemCount, $versionId]);
    }
}
