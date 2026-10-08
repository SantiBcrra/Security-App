<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Programas de inspección: qué checklist, sobre qué, cada cuánto y quién. */
final class InspectionPrograms
{
    private const SELECT = 'SELECT p.*, t.name AS template_name, t.uuid AS template_uuid, t.is_active AS template_active,
            eq.code AS equipment_code, eq.name AS equipment_name, eq.uuid AS equipment_uuid,
            ty.name AS equipment_type_name, ty.uuid AS equipment_type_uuid, se.name AS sector_name, se.uuid AS sector_uuid,
            au.name AS assignee_name, au.uuid AS assignee_uuid, ru.name AS action_responsible_name, ru.uuid AS action_responsible_uuid
        FROM inspection_programs p
        JOIN inspection_templates t ON t.id = p.template_id
        LEFT JOIN equipment eq ON eq.id = p.equipment_id
        LEFT JOIN catalog_items ty ON ty.id = p.equipment_type_id
        LEFT JOIN sectors se ON se.id = p.sector_id
        LEFT JOIN users au ON au.id = p.assignee_user_id
        LEFT JOIN users ru ON ru.id = p.action_responsible_user_id';

    public static function all(bool $onlyActive = false): array
    {
        return DB::tenant()->query(self::SELECT . ' WHERE p.deleted_at IS NULL' . ($onlyActive ? ' AND p.is_active = 1 AND t.is_active = 1' : '')
            . ' ORDER BY p.is_active DESC, p.name')->fetchAll();
    }

    public static function findByUuid(string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE p.uuid = ? AND p.deleted_at IS NULL');
        $stmt->execute([$uuid]);
        return $stmt->fetch() ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE p.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Programas activos de una plantilla (para asociar una inspección hecha sin programar). */
    public static function forTemplate(int $templateId): array
    {
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE p.template_id = ? AND p.is_active = 1 AND p.deleted_at IS NULL ORDER BY p.id');
        $stmt->execute([$templateId]);
        return $stmt->fetchAll();
    }

    public static function create(array $data): int
    {
        $data += ['uuid' => Uuid::v4()];
        $cols = array_keys($data);
        DB::tenant()->prepare('INSERT INTO inspection_programs (`' . implode('`, `', $cols) . '`, created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(array_values($data));
        return (int) DB::tenant()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        unset($data['uuid'], $data['created_by']);
        $sets = implode(', ', array_map(fn ($c) => "`{$c}` = ?", array_keys($data)));
        DB::tenant()->prepare('UPDATE inspection_programs SET ' . ($sets ? $sets . ', ' : '') . 'updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([...array_values($data), $id]);
    }
}
