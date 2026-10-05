<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/** Contadores correlativos de la empresa. Llamar DENTRO de una transacción. */
final class Sequences
{
    public static function next(string $name): int
    {
        $db = DB::tenant();
        if (!$db->inTransaction()) {
            throw new \LogicException('Sequences::next() necesita una transacción abierta.');
        }
        $db->prepare('INSERT IGNORE INTO sequences (name, value) VALUES (?, 0)')->execute([$name]);
        $stmt = $db->prepare('SELECT value FROM sequences WHERE name = ? FOR UPDATE');
        $stmt->execute([$name]);
        $next = (int) $stmt->fetchColumn() + 1;
        $db->prepare('UPDATE sequences SET value = ? WHERE name = ?')->execute([$next, $name]);
        return $next;
    }
}
