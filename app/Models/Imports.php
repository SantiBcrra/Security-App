<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Importaciones CSV/Excel (estado para procesar por lotes). */
final class Imports
{
    public static function create(array $data): array
    {
        $data += ['uuid' => Uuid::v4()];
        $cols = array_keys($data);
        DB::tenant()->prepare('INSERT INTO imports (`' . implode('`, `', $cols) . '`, created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute(array_values($data));
        return self::findByUuid($data['uuid']);
    }

    public static function findByUuid(string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM imports WHERE uuid = ? LIMIT 1');
        $stmt->execute([$uuid]);
        return $stmt->fetch() ?: null;
    }

    public static function update(int $id, array $data): void
    {
        $sets = implode(', ', array_map(fn ($c) => "`{$c}` = ?", array_keys($data)));
        DB::tenant()->prepare("UPDATE imports SET {$sets}, updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([...array_values($data), $id]);
    }

    public static function recent(int $limit = 10): array
    {
        return DB::tenant()->query('SELECT i.*, u.name AS user_name FROM imports i LEFT JOIN users u ON u.id = i.user_id
            ORDER BY i.id DESC LIMIT ' . max(1, $limit))->fetchAll();
    }
}
