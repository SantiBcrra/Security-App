<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/**
 * Sectores jerárquicos por planta (planta > nave > sector, MAX_DEPTH niveles).
 * path materializado "/3/7/12/": un sector y todos sus descendientes = path LIKE '/3/7/%'.
 */
final class Sectors extends Repository
{
    public const MAX_DEPTH = 3;

    protected const TABLE = 'sectors';
    protected const SEARCH = ['name', 'code'];
    protected const ORDER = 'si.name, t.path';

    protected static function select(): string
    {
        return 'SELECT t.*, si.name AS site_name, pa.name AS parent_name
            FROM sectors t
            JOIN sites si ON si.id = t.site_id
            LEFT JOIN sectors pa ON pa.id = t.parent_id';
    }

    /** Alta: inserta y completa path/depth a partir del padre. @return int id */
    public static function create(array $data): int
    {
        $parent = !empty($data['parent_id']) ? self::findById((int) $data['parent_id']) : null;
        $data += ['uuid' => Uuid::v4()];
        $data['depth'] = $parent ? (int) $parent['depth'] + 1 : 1;
        $id = parent::create($data);
        DB::tenant()->prepare('UPDATE sectors SET path = ? WHERE id = ?')
            ->execute([($parent['path'] ?? '/') . $id . '/', $id]);
        return $id;
    }

    /** Cambia de padre (o de planta) recalculando el path y la profundidad de todo el subárbol. */
    public static function move(int $id, int $siteId, ?int $parentId): void
    {
        $node = self::findById($id);
        $parent = $parentId ? self::findById($parentId) : null;
        $newPath = ($parent['path'] ?? '/') . $id . '/';
        $depthDelta = ($parent ? (int) $parent['depth'] + 1 : 1) - (int) $node['depth'];
        DB::tenant()->prepare('UPDATE sectors SET path = CONCAT(?, SUBSTRING(path, ?)), depth = depth + ?, site_id = ?,
            updated_at = UTC_TIMESTAMP() WHERE path LIKE ?')
            ->execute([$newPath, strlen($node['path']) + 1, $depthDelta, $siteId, $node['path'] . '%']);
        DB::tenant()->prepare('UPDATE sectors SET parent_id = ? WHERE id = ?')->execute([$parentId, $id]);
    }

    /** Profundidad máxima por debajo de un nodo (0 si no tiene hijos). */
    public static function subtreeHeight(array $node): int
    {
        $stmt = DB::tenant()->prepare('SELECT COALESCE(MAX(depth), ?) - ? FROM sectors WHERE path LIKE ?');
        $stmt->execute([$node['depth'], $node['depth'], $node['path'] . '%']);
        return (int) $stmt->fetchColumn();
    }

    public static function activeChildrenCount(int $id): int
    {
        $stmt = DB::tenant()->prepare('SELECT COUNT(*) FROM sectors WHERE parent_id = ? AND is_active = 1');
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }

    /** Ids de los sectores dados y de todos sus descendientes. @param list<int> $ids */
    public static function withDescendants(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $paths = DB::tenant()->prepare("SELECT path FROM sectors WHERE id IN ({$placeholders})");
        $paths->execute(array_values($ids));
        $or = [];
        $params = [];
        foreach ($paths->fetchAll(\PDO::FETCH_COLUMN) as $path) {
            $or[] = 'path LIKE ?';
            $params[] = $path . '%';
        }
        if (!$or) {
            return [];
        }
        $stmt = DB::tenant()->prepare('SELECT id FROM sectors WHERE ' . implode(' OR ', $or));
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Etiquetas completas "Planta › Nave › Sector" de todos los sectores.
     * @return array<int, array{uuid:string, label:string, site_id:int, depth:int, active:bool, path:string}>
     */
    public static function labelMap(bool $includeInactive = true): array
    {
        $rows = DB::tenant()->query('SELECT t.id, t.uuid, t.name, t.path, t.depth, t.site_id, t.is_active, si.name AS site_name
            FROM sectors t JOIN sites si ON si.id = t.site_id ORDER BY si.name, t.path')->fetchAll();
        $names = array_column($rows, 'name', 'id');
        $map = [];
        foreach ($rows as $row) {
            if (!$includeInactive && (int) $row['is_active'] !== 1) {
                continue;
            }
            $parts = [$row['site_name']];
            foreach (array_filter(explode('/', trim($row['path'], '/'))) as $id) {
                $parts[] = $names[(int) $id] ?? '?';
            }
            $map[(int) $row['id']] = [
                'uuid' => $row['uuid'], 'label' => implode(' › ', $parts), 'site_id' => (int) $row['site_id'],
                'depth' => (int) $row['depth'], 'active' => (int) $row['is_active'] === 1, 'path' => $row['path'],
            ];
        }
        return $map;
    }

    /** Opciones uuid => "Planta › Nave › Sector" (activos + el actual). */
    public static function options(?int $includeId = null): array
    {
        $options = [];
        foreach (self::labelMap() as $id => $s) {
            if ($s['active'] || $id === $includeId) {
                $options[$s['uuid']] = $s['label'];
            }
        }
        return $options;
    }

    /**
     * Busca un sector por texto: "Planta > Nave > Sector" (con > o ›), o por nombre si es único.
     * @return array{0: ?int, 1: ?string} [id, error]
     */
    public static function resolve(string $text, ?array $map = null): array
    {
        $text = trim($text);
        if ($text === '') {
            return [null, null];
        }
        $map ??= self::labelMap(false);
        $norm = fn (string $s) => mb_strtolower(preg_replace('/\s*[>›\/]\s*/u', ' › ', trim($s)));
        $wanted = $norm($text);
        $byName = [];
        foreach ($map as $id => $s) {
            if ($norm($s['label']) === $wanted) {
                return [$id, null];
            }
            $parts = explode(' › ', $norm($s['label']));
            // Coincidencia por el final del camino ("Nave 1 › Soldadura" o solo "Soldadura")
            if (str_ends_with(' › ' . implode(' › ', $parts), ' › ' . $wanted)) {
                $byName[] = $id;
            }
        }
        if (count($byName) === 1) {
            return [$byName[0], null];
        }
        return [null, $byName ? "Sector ambiguo \"{$text}\": escribilo como Planta > Nave > Sector." : "No existe el sector \"{$text}\"."];
    }
}
