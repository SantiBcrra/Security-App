<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Reglas de notificación de la empresa. */
final class NotificationRules
{
    public static function all(): array
    {
        return array_map([self::class, 'decode'], DB::tenant()->query('SELECT r.*, s.name AS sector_name FROM notification_rules r
            LEFT JOIN sectors s ON s.id = r.sector_id ORDER BY r.event, r.name')->fetchAll());
    }

    public static function activeFor(string $event): array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM notification_rules WHERE event = ? AND is_active = 1');
        $stmt->execute([$event]);
        return array_map([self::class, 'decode'], $stmt->fetchAll());
    }

    public static function findByUuid(string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM notification_rules WHERE uuid = ?');
        $stmt->execute([$uuid]);
        $row = $stmt->fetch();
        return $row ? self::decode($row) : null;
    }

    public static function save(?int $id, array $data): string
    {
        $data['recipients'] = json_encode($data['recipients']);
        $data['channels'] = json_encode($data['channels']);
        if ($id === null) {
            $data['uuid'] = Uuid::v4();
            $cols = array_keys($data);
            DB::tenant()->prepare('INSERT INTO notification_rules (`' . implode('`, `', $cols) . '`, created_at, updated_at) VALUES ('
                . implode(', ', array_fill(0, count($cols), '?')) . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(array_values($data));
            return $data['uuid'];
        }
        $sets = implode(', ', array_map(fn ($c) => "`{$c}` = ?", array_keys($data)));
        DB::tenant()->prepare("UPDATE notification_rules SET {$sets}, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([...array_values($data), $id]);
        return '';
    }

    private static function decode(array $row): array
    {
        $row['recipients'] = json_decode((string) $row['recipients'], true) ?: [];
        $row['channels'] = json_decode((string) $row['channels'], true) ?: [];
        return $row;
    }
}
