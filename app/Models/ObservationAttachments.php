<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Fotos de las observaciones (evidencia inmutable). */
final class ObservationAttachments
{
    public static function create(int $observationId, array $data): string
    {
        $uuid = Uuid::v4();
        DB::tenant()->prepare('INSERT INTO observation_attachments (uuid, observation_id, path, thumb_path, original_name, mime,
            size_bytes, sha256, width, height, exif, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$uuid, $observationId, $data['path'], $data['thumb_path'], $data['original_name'], $data['mime'],
                $data['size_bytes'], $data['sha256'], $data['width'], $data['height'], $data['exif'], $data['uploaded_by']]);
        return $uuid;
    }

    public static function forObservation(int $observationId): array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM observation_attachments WHERE observation_id = ? ORDER BY id');
        $stmt->execute([$observationId]);
        return $stmt->fetchAll();
    }

    public static function find(int $observationId, string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM observation_attachments WHERE observation_id = ? AND uuid = ?');
        $stmt->execute([$observationId, $uuid]);
        return $stmt->fetch() ?: null;
    }
}
