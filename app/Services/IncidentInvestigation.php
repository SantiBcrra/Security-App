<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Models\Actions;
use App\Models\CatalogItems;
use App\Models\Incidents;
use App\Models\Users;

/**
 * Investigación de un incidente: equipo, 5 porqués, árbol de causas, causas raíz, conclusiones y
 * acciones CAPA derivadas. Estados del incidente: reportado → en_investigacion → investigado.
 */
final class IncidentInvestigation
{
    public const NODE_TYPES = ['hecho' => 'Hecho', 'causa_inmediata' => 'Causa inmediata', 'causa_basica' => 'Causa básica'];
    public const MAX_NODES = 60;
    public const MAX_WHYS = 7;

    public static function canEdit(array $i): bool
    {
        return UserAuth::can(IncidentService::MODULE, 'editar') && IncidentService::canView($i);
    }

    public static function start(array $i): ?string
    {
        if (!self::canEdit($i)) {
            return 'No tenés permiso para investigar.';
        }
        if ($i['status'] !== 'reportado') {
            return 'La investigación ya empezó o el incidente está cerrado.';
        }
        return self::changeStatus($i, 'en_investigacion', null, function () use ($i) {
            Incidents::startInvestigation((int) $i['id'], UserAuth::user()['id'] ?? null);
        });
    }

    /**
     * Guarda lo cargado (se puede guardar a medias). @param array $in team (uuids), problem, whys (textos),
     * cause_tree (JSON o array de nodos), root_causes (uuids del catálogo causa), conclusions, lessons
     */
    public static function save(array $i, array $in): ?string
    {
        if (!self::canEdit($i)) {
            return 'No tenés permiso para investigar.';
        }
        if ($i['status'] !== 'en_investigacion') {
            return 'La investigación no está abierta (empezala o reabrila).';
        }
        [$data, $error] = self::normalize($in);
        if ($error !== null) {
            return $error;
        }
        Incidents::saveInvestigation((int) $i['id'], $data);
        Incidents::update((int) $i['id'], []);
        Incidents::addEvent((int) $i['id'], 'investigation', ['data' => ['nodos' => count($data['cause_tree']), 'porques' => count($data['five_whys']['whys'])]] + self::actor());
        return null;
    }

    /** Terminar: pide análisis (porqués o árbol), al menos una causa raíz y conclusiones. */
    public static function complete(array $i): ?string
    {
        if (!self::canEdit($i)) {
            return 'No tenés permiso para investigar.';
        }
        $v = Incidents::investigation((int) $i['id']);
        if ($i['status'] !== 'en_investigacion' || $v === null) {
            return 'La investigación no está abierta.';
        }
        $missing = [];
        if (!array_filter($v['five_whys']['whys']) && !$v['cause_tree']) {
            $missing[] = 'los 5 porqués o el árbol de causas';
        }
        if (!$v['root_causes']) {
            $missing[] = 'al menos una causa raíz';
        }
        if (mb_strlen(trim((string) $v['conclusions'])) < 10) {
            $missing[] = 'las conclusiones';
        }
        if ($missing) {
            return 'Para terminar la investigación falta: ' . implode(', ', $missing) . '.';
        }
        return self::changeStatus($i, 'investigado', null, function () use ($i) {
            Incidents::saveInvestigation((int) $i['id'], ['completed_at' => gmdate('Y-m-d H:i:s'), 'completed_by' => UserAuth::user()['id'] ?? null]);
        });
    }

    public static function reopen(array $i, string $comment): ?string
    {
        if (!self::canEdit($i)) {
            return 'No tenés permiso para investigar.';
        }
        if ($i['status'] !== 'investigado') {
            return 'Solo se reabre una investigación terminada (si el incidente está cerrado, reabrí el incidente).';
        }
        if (mb_strlen(trim($comment)) < 5) {
            return 'Escribí el motivo (mínimo 5 caracteres).';
        }
        return self::changeStatus($i, 'en_investigacion', trim($comment), function () use ($i) {
            Incidents::saveInvestigation((int) $i['id'], ['completed_at' => null, 'completed_by' => null]);
        });
    }

    /** Acción CAPA derivada (origen incidente). $in: title, description, responsible, due_on, priority, type, cause (texto opcional). */
    public static function createAction(array $i, array $in): array
    {
        if (!self::canEdit($i) || !UserAuth::can('acciones', 'crear')) {
            return [null, ['_' => 'No tenés permiso para crear acciones.']];
        }
        if (!in_array($i['status'], ['en_investigacion', 'investigado'], true)) {
            return [null, ['_' => 'Las acciones se crean durante la investigación.']];
        }
        [$data, $errors] = ActionService::validate($in);
        if ($errors) {
            return [null, $errors];
        }
        $cause = trim((string) ($in['cause'] ?? ''));
        $data['description'] = trim(($data['description'] ?? '') . ($cause !== '' ? "\nCausa: " . $cause : '')) ?: null;
        $data['sector_id'] ??= $i['sector_id'] !== null ? (int) $i['sector_id'] : null;
        $data['site_id'] ??= $i['site_id'] !== null ? (int) $i['site_id'] : null;
        $action = ActionService::create($data, 'incidente', (int) $i['id'])['action'];
        Incidents::addEvent((int) $i['id'], 'action_created', ['data' => ['accion' => Actions::format((int) $action['number']), 'titulo' => $action['title']]] + self::actor());
        return [$action, []];
    }

    /**
     * Valida y normaliza. Árbol: nodos con id, parent (otro nodo o null), text y type; sin padres inexistentes ni ciclos.
     * @return array{0: array, 1: ?string}
     */
    public static function normalize(array $in): array
    {
        $team = [];
        foreach ((array) ($in['team'] ?? []) as $uuid) {
            if (($u = Users::findByUuid((string) $uuid)) !== null) {
                $team[] = (int) $u['id'];
            }
        }
        $whys = array_values(array_filter(array_map(fn ($w) => mb_substr(trim((string) $w), 0, 500), (array) ($in['whys'] ?? [])), fn ($w) => $w !== ''));
        if (count($whys) > self::MAX_WHYS) {
            return [[], 'Máximo ' . self::MAX_WHYS . ' porqués.'];
        }
        $tree = $in['cause_tree'] ?? [];
        if (is_string($tree)) {
            $tree = json_decode($tree, true) ?: [];
        }
        $nodes = [];
        foreach ((array) $tree as $n) {
            $text = mb_substr(trim((string) ($n['text'] ?? '')), 0, 300);
            $id = preg_replace('/[^a-z0-9\-]/i', '', (string) ($n['id'] ?? ''));
            if ($text === '' || $id === '') {
                continue;
            }
            $nodes[$id] = ['id' => $id, 'parent' => ($n['parent'] ?? null) ? (string) $n['parent'] : null, 'text' => $text,
                'type' => isset(self::NODE_TYPES[$n['type'] ?? '']) ? $n['type'] : 'causa_inmediata'];
        }
        if (count($nodes) > self::MAX_NODES) {
            return [[], 'El árbol de causas admite hasta ' . self::MAX_NODES . ' elementos.'];
        }
        foreach ($nodes as $id => $n) {
            if ($n['parent'] !== null && !isset($nodes[$n['parent']])) {
                $nodes[$id]['parent'] = null; // su padre se borró: queda como raíz
            }
        }
        foreach ($nodes as $id => $n) {
            $seen = [$id => true];
            for ($p = $n['parent']; $p !== null; $p = $nodes[$p]['parent']) {
                if (isset($seen[$p])) {
                    return [[], 'El árbol de causas tiene un ciclo: revisá de qué depende cada causa.'];
                }
                $seen[$p] = true;
            }
        }
        $causes = [];
        foreach ((array) ($in['root_causes'] ?? []) as $uuid) {
            $c = CatalogItems::findByUuid((string) $uuid);
            if ($c !== null && $c['catalog'] === 'causa') {
                $causes[] = (int) $c['id'];
            }
        }
        return [[
            'team'        => array_values(array_unique($team)),
            'five_whys'   => ['problem' => mb_substr(trim((string) ($in['problem'] ?? '')), 0, 500), 'whys' => $whys],
            'cause_tree'  => array_values($nodes),
            'root_causes' => array_values(array_unique($causes)),
            'conclusions' => trim((string) ($in['conclusions'] ?? '')) ?: null,
            'lessons'     => trim((string) ($in['lessons'] ?? '')) ?: null,
        ], null];
    }

    /** Árbol en orden para mostrar/imprimir: [nodo + depth]. */
    public static function flatten(array $nodes): array
    {
        $children = [];
        foreach ($nodes as $n) {
            $children[$n['parent'] ?? ''][] = $n;
        }
        $out = [];
        $walk = function (string $parent, int $depth) use (&$walk, &$out, $children) {
            foreach ($children[$parent] ?? [] as $n) {
                $out[] = $n + ['depth' => $depth];
                $walk($n['id'], $depth + 1);
            }
        };
        $walk('', 0);
        return $out;
    }

    private static function changeStatus(array $i, string $to, ?string $comment, callable $also): ?string
    {
        $db = DB::tenant();
        $db->beginTransaction();
        try {
            $locked = Incidents::findById((int) $i['id'], true);
            if ($locked['status'] !== $i['status']) {
                $db->rollBack();
                return 'El incidente cambió mientras tanto. Recargá la página.';
            }
            $also();
            Incidents::update((int) $i['id'], ['status' => $to]);
            Incidents::addEvent((int) $i['id'], 'status', ['from' => $i['status'], 'to' => $to, 'comment' => $comment] + self::actor());
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        Audit::tenant('incident.status', 'incident', $i['uuid'], ['estado' => $i['status']], ['estado' => $to]);
        return null;
    }

    private static function actor(): array
    {
        $user = UserAuth::user();
        return $user ? ['user_id' => (int) $user['id'], 'actor_name' => $user['name']] : ['user_id' => null, 'actor_name' => 'Sistema'];
    }
}
