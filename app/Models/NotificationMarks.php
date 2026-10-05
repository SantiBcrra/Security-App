<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/** "Esto ya se hizo": evita repetir tareas que deben correr una sola vez (por día, por semana...). */
final class NotificationMarks
{
    /** Devuelve true si la marca es nueva (y la registra); false si ya existía. Atómico. */
    public static function claim(string $key): bool
    {
        $stmt = DB::tenant()->prepare('INSERT IGNORE INTO notification_marks (mark_key, created_at) VALUES (?, UTC_TIMESTAMP())');
        $stmt->execute([mb_substr($key, 0, 100)]);
        return $stmt->rowCount() === 1;
    }
}
