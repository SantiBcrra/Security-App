<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** EPP: matriz por puesto, extras por empleado y entregas (con sus ítems). */
final class Ppe
{
    // ── matriz por puesto ──────────────────────────────────────────

    /** Filas activas de la matriz (todas o las de un puesto). */
    public static function matrix(?int $positionId = null): array
    {
        $sql = 'SELECT m.*, p.uuid AS position_uuid, p.name AS position_name, i.uuid AS item_uuid, i.name AS item_name
            FROM ppe_matrix m JOIN positions p ON p.id = m.position_id JOIN ppe_items i ON i.id = m.item_id
            WHERE m.is_active = 1' . ($positionId !== null ? ' AND m.position_id = ?' : '') . ' ORDER BY p.name, i.category, i.name';
        $stmt = DB::tenant()->prepare($sql);
        $stmt->execute($positionId !== null ? [$positionId] : []);
        return $stmt->fetchAll();
    }

    /** Alta, cambio o baja (null) de una celda de la matriz. */
    public static function setMatrix(int $positionId, int $itemId, ?array $data): void
    {
        $db = DB::tenant();
        if ($data === null) {
            $db->prepare('UPDATE ppe_matrix SET is_active = 0, updated_at = UTC_TIMESTAMP() WHERE position_id = ? AND item_id = ?')->execute([$positionId, $itemId]);
            return;
        }
        $db->prepare('INSERT INTO ppe_matrix (uuid, position_id, item_id, quantity, life_days, mandatory, notes, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), life_days = VALUES(life_days), mandatory = VALUES(mandatory), notes = VALUES(notes),
                is_active = 1, updated_at = UTC_TIMESTAMP()')
            ->execute([Uuid::v4(), $positionId, $itemId, $data['quantity'], $data['life_days'], $data['mandatory'] ? 1 : 0, $data['notes']]);
    }

    // ── extras por empleado ────────────────────────────────────────

    public static function extras(int $employeeId): array
    {
        $stmt = DB::tenant()->prepare('SELECT x.*, i.uuid AS item_uuid, i.name AS item_name FROM ppe_employee_extras x JOIN ppe_items i ON i.id = x.item_id
            WHERE x.employee_id = ? AND x.is_active = 1 ORDER BY i.category, i.name');
        $stmt->execute([$employeeId]);
        return $stmt->fetchAll();
    }

    public static function setExtra(int $employeeId, int $itemId, ?array $data): void
    {
        $db = DB::tenant();
        if ($data === null) {
            $db->prepare('UPDATE ppe_employee_extras SET is_active = 0, updated_at = UTC_TIMESTAMP() WHERE employee_id = ? AND item_id = ?')->execute([$employeeId, $itemId]);
            return;
        }
        $db->prepare('INSERT INTO ppe_employee_extras (uuid, employee_id, item_id, quantity, life_days, reason, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE quantity = VALUES(quantity), life_days = VALUES(life_days), reason = VALUES(reason), is_active = 1, updated_at = UTC_TIMESTAMP()')
            ->execute([Uuid::v4(), $employeeId, $itemId, $data['quantity'], $data['life_days'], $data['reason']]);
    }

    // ── entregas ───────────────────────────────────────────────────

    private const DELIVERY = 'SELECT d.*, CONCAT(e.last_name, \', \', e.first_name) AS employee_name, e.uuid AS employee_uuid, e.dni AS employee_dni,
            e.sector_id, u.name AS delivered_by_name, vu.name AS voided_by_name
        FROM ppe_deliveries d JOIN employees e ON e.id = d.employee_id LEFT JOIN users u ON u.id = d.delivered_by LEFT JOIN users vu ON vu.id = d.voided_by';

    public static function createDelivery(array $d): int
    {
        $cols = array_keys($d);
        DB::tenant()->prepare('INSERT INTO ppe_deliveries (`' . implode('`, `', $cols) . '`, created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(array_values($d));
        return (int) DB::tenant()->lastInsertId();
    }

    public static function addDeliveryItem(int $deliveryId, array $i): void
    {
        DB::tenant()->prepare('INSERT INTO ppe_delivery_items (delivery_id, item_id, quantity, size, item_name, model, brand, certified, certification, life_days,
            next_due_on, returned_previous) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$deliveryId, $i['item_id'], $i['quantity'], $i['size'], $i['item_name'], $i['model'], $i['brand'], $i['certified'] ? 1 : 0,
                $i['certification'], $i['life_days'], $i['next_due_on'], $i['returned_previous']]);
    }

    public static function findDelivery(string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare(self::DELIVERY . ' WHERE d.uuid = ?');
        $stmt->execute([$uuid]);
        return $stmt->fetch() ?: null;
    }

    /** Entregas de un empleado, la más reciente primero, con sus ítems en "items". */
    public static function deliveries(int $employeeId, bool $includeVoided = true, ?string $from = null, ?string $to = null): array
    {
        $where = ['d.employee_id = ?'];
        $params = [$employeeId];
        if (!$includeVoided) {
            $where[] = 'd.voided_at IS NULL';
        }
        if ($from !== null) {
            $where[] = 'd.delivered_at >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = 'd.delivered_at < ?';
            $params[] = $to;
        }
        $stmt = DB::tenant()->prepare(self::DELIVERY . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY d.delivered_at DESC, d.id DESC');
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $items = self::itemsFor(array_map('intval', array_column($rows, 'id')));
        foreach ($rows as &$r) {
            $r['items'] = $items[(int) $r['id']] ?? [];
        }
        return $rows;
    }

    /** @return array<int, list<array>> delivery_id => ítems */
    public static function itemsFor(array $deliveryIds): array
    {
        if (!$deliveryIds) {
            return [];
        }
        $rows = DB::tenant()->query('SELECT di.*, i.uuid AS item_uuid, i.category FROM ppe_delivery_items di JOIN ppe_items i ON i.id = di.item_id
            WHERE di.delivery_id IN (' . implode(',', array_map('intval', $deliveryIds)) . ') ORDER BY di.id')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['delivery_id']][] = $r;
        }
        return $out;
    }

    /**
     * Última entrega vigente (no anulada) de cada elemento, por empleado (sin window functions: subconsulta con MAX).
     * @param ?list<int> $employeeIds null = todos
     * @return array<int, array<int, array>> employee_id => item_id => {delivered_at, next_due_on, life_days, size, quantity, delivery_uuid}
     */
    public static function lastDeliveries(?array $employeeIds = null): array
    {
        if ($employeeIds === []) {
            return [];
        }
        $filter = $employeeIds !== null ? ' AND d2.employee_id IN (' . implode(',', array_map('intval', $employeeIds)) . ')' : '';
        $rows = DB::tenant()->query("SELECT d.employee_id, di.item_id, d.delivered_at, d.uuid AS delivery_uuid, di.next_due_on, di.life_days, di.size, di.quantity, di.id
            FROM ppe_delivery_items di JOIN ppe_deliveries d ON d.id = di.delivery_id
            JOIN (SELECT d2.employee_id, di2.item_id, MAX(d2.delivered_at) AS last_at FROM ppe_delivery_items di2
                  JOIN ppe_deliveries d2 ON d2.id = di2.delivery_id WHERE d2.voided_at IS NULL{$filter} GROUP BY d2.employee_id, di2.item_id) x
              ON x.employee_id = d.employee_id AND x.item_id = di.item_id AND x.last_at = d.delivered_at
            WHERE d.voided_at IS NULL ORDER BY di.id")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['employee_id']][(int) $r['item_id']] = $r; // empate en la hora: queda el último cargado
        }
        return $out;
    }

    public static function voidDelivery(int $id, int $by, string $reason): void
    {
        DB::tenant()->prepare('UPDATE ppe_deliveries SET voided_at = UTC_TIMESTAMP(), voided_by = ?, void_reason = ?, updated_at = UTC_TIMESTAMP()
            WHERE id = ? AND voided_at IS NULL')->execute([$by, $reason, $id]);
    }

    public static function format(int $number): string
    {
        return 'EPP-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
