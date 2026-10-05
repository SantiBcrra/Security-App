<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/**
 * Base de los modelos de datos maestros de la empresa (base DB::tenant()).
 * Cada subclase define TABLE, SEARCH (columnas para buscar) y ORDER.
 * Nunca se borra físicamente: se activa/desactiva (is_active). updated_at alimenta la sync offline.
 */
abstract class Repository
{
    protected const TABLE = '';
    protected const SEARCH = ['name'];
    protected const ORDER = 'name';

    /** SELECT base (las subclases lo amplían con JOINs para mostrar nombres relacionados). */
    protected static function select(): string
    {
        return 'SELECT t.* FROM ' . static::TABLE . ' t';
    }

    /**
     * @param array<string,mixed> $filters columna => valor (igualdad; null = IS NULL)
     */
    public static function list(?string $search = null, bool $includeInactive = false, array $filters = [], int $limit = 500): array
    {
        [$where, $params] = static::where($search, $includeInactive, $filters);
        $sql = static::select() . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY t.is_active DESC, ' . static::ORDER . ' LIMIT ' . max(1, $limit);
        $stmt = DB::tenant()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function count(?string $search = null, bool $includeInactive = false, array $filters = []): int
    {
        [$where, $params] = static::where($search, $includeInactive, $filters);
        $stmt = DB::tenant()->prepare('SELECT COUNT(*) FROM ' . static::TABLE . ' t' . ($where ? ' WHERE ' . implode(' AND ', $where) : ''));
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    protected static function where(?string $search, bool $includeInactive, array $filters): array
    {
        $where = [];
        $params = [];
        if (!$includeInactive) {
            $where[] = 't.is_active = 1';
        }
        foreach ($filters as $column => $value) {
            if ($value === null) {
                $where[] = "t.`{$column}` IS NULL";
            } else {
                $where[] = "t.`{$column}` = ?";
                $params[] = $value;
            }
        }
        $search = trim((string) $search);
        if ($search !== '') {
            $or = [];
            foreach (static::SEARCH as $column) {
                $or[] = "t.`{$column}` LIKE ?";
                $params[] = '%' . $search . '%';
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        return [$where, $params];
    }

    public static function findByUuid(string $uuid): ?array
    {
        return static::one('t.uuid = ?', [$uuid]);
    }

    public static function findById(int $id): ?array
    {
        return static::one('t.id = ?', [$id]);
    }

    public static function findBy(string $column, mixed $value): ?array
    {
        return static::one("t.`{$column}` = ?", [$value]);
    }

    /** ¿Existe otro registro con ese valor? (para validar únicos) */
    public static function exists(string $column, mixed $value, ?int $exceptId = null): bool
    {
        $stmt = DB::tenant()->prepare('SELECT 1 FROM ' . static::TABLE . " WHERE `{$column}` = ? AND id <> ? LIMIT 1");
        $stmt->execute([$value, $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return int id */
    public static function create(array $data): int
    {
        $data += ['uuid' => Uuid::v4()];
        $cols = array_keys($data);
        DB::tenant()->prepare('INSERT INTO ' . static::TABLE . ' (`' . implode('`, `', $cols) . '`, created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute(array_values($data));
        return (int) DB::tenant()->lastInsertId();
    }

    public static function update(int $id, array $data): void
    {
        if (!$data) {
            return;
        }
        $sets = implode(', ', array_map(fn ($c) => "`{$c}` = ?", array_keys($data)));
        DB::tenant()->prepare('UPDATE ' . static::TABLE . " SET {$sets}, updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([...array_values($data), $id]);
    }

    public static function setActive(int $id, bool $active): void
    {
        static::update($id, ['is_active' => $active ? 1 : 0]);
    }

    /** Opciones para un <select>: uuid => etiqueta (solo activos, más el valor actual si está inactivo). */
    public static function options(?int $includeId = null): array
    {
        $stmt = DB::tenant()->prepare('SELECT t.id, t.uuid, t.' . static::labelColumn() . ' AS label FROM ' . static::TABLE
            . ' t WHERE t.is_active = 1 OR t.id = ? ORDER BY ' . static::ORDER);
        $stmt->execute([$includeId ?? 0]);
        $options = [];
        foreach ($stmt->fetchAll() as $row) {
            $options[$row['uuid']] = $row['label'];
        }
        return $options;
    }

    protected static function labelColumn(): string
    {
        return 'name';
    }

    protected static function one(string $where, array $params): ?array
    {
        $stmt = DB::tenant()->prepare(static::select() . ' WHERE ' . $where . ' LIMIT 1');
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }
}
