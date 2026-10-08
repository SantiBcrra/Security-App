<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Evidencia de las acciones (fotos o PDF, inmutables). */
final class ActionAttachments
{
    public static function create(int $actionId, string $kind, int $cycle, array $data): string
    {
        $uuid = Uuid::v4();
        DB::tenant()->prepare('INSERT INTO action_attachments (uuid, action_id, kind, cycle, path, thumb_path, original_name, mime,
            size_bytes, sha256, width, height, exif, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$uuid, $actionId, $kind, $cycle, $data['path'], $data['thumb_path'] ?? null, $data['original_name'] ?? null, $data['mime'],
                $data['size_bytes'], $data['sha256'], $data['width'] ?? null, $data['height'] ?? null, $data['exif'] ?? null, $data['uploaded_by'] ?? null]);
        return $uuid;
    }

    public static function forAction(int $actionId): array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM action_attachments WHERE action_id = ? ORDER BY id');
        $stmt->execute([$actionId]);
        return $stmt->fetchAll();
    }

    public static function countEvidence(int $actionId, int $cycle): int
    {
        $stmt = DB::tenant()->prepare("SELECT COUNT(*) FROM action_attachments WHERE action_id = ? AND kind = 'evidencia' AND cycle = ?");
        $stmt->execute([$actionId, $cycle]);
        return (int) $stmt->fetchColumn();
    }

    public static function find(int $actionId, string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM action_attachments WHERE action_id = ? AND uuid = ?');
        $stmt->execute([$actionId, $uuid]);
        return $stmt->fetch() ?: null;
    }
}
