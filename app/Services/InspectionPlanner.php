<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Models\CatalogItems;
use App\Models\Equipment;
use App\Models\InspectionPrograms as Programs;
use App\Models\InspectionSchedules;
use App\Models\InspectionTemplates;
use App\Models\Roles;
use App\Models\Sectors;
use App\Models\Users;

/**
 * Programas de inspección y su calendario: validación, objetivos (equipos / sectores), fechas por
 * período, generación por el cron (idempotente) y quién tiene asignada cada una.
 */
final class InspectionPlanner
{
    public const FREQUENCIES = ['diaria' => 'Diaria', 'semanal' => 'Semanal', 'mensual' => 'Mensual', 'manual' => 'Manual (sin programar)'];
    public const TARGETS = ['equipo' => 'Un equipo', 'tipo' => 'Todos los equipos de un tipo', 'sector' => 'Un sector'];
    public const ASSIGNEES = ['sector_supervisors' => 'Supervisores del sector', 'user' => 'Un usuario', 'role' => 'Un rol'];
    public const WEEKDAYS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
    private const AHEAD_DAYS = 6;
    private const BEHIND_DAYS = 2; // si el cron no corrió un par de días, igual quedan registradas

    /** @return array{0: ?array, 1: list<string>} */
    public static function save(?array $program, array $in): array
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        $template = InspectionTemplates::findByUuid((string) ($in['template'] ?? ''));
        if ($template === null) {
            $errors[] = 'Elegí el checklist.';
        }
        if (mb_strlen($name) < 3) {
            $name = $template['name'] ?? '';
        }
        $frequency = isset(self::FREQUENCIES[$in['frequency'] ?? '']) ? $in['frequency'] : 'diaria';
        $weekday = $frequency === 'semanal' ? (int) ($in['weekday'] ?? 1) : null;
        $monthday = $frequency === 'mensual' ? (int) ($in['monthday'] ?? 1) : null;
        if ($weekday !== null && ($weekday < 1 || $weekday > 7)) {
            $errors[] = 'Día de la semana inválido.';
        }
        if ($monthday !== null && ($monthday < 1 || $monthday > 31)) {
            $errors[] = 'Día del mes inválido.';
        }
        $target = isset(self::TARGETS[$in['target_type'] ?? '']) ? $in['target_type'] : null;
        $data = ['equipment_id' => null, 'equipment_type_id' => null, 'sector_id' => null];
        $sector = ($in['sector'] ?? '') !== '' ? Sectors::findByUuid((string) $in['sector']) : null;
        if ($template !== null && $template['scope'] === 'permiso') {
            $errors[] = 'Los checklists de permisos de trabajo no se programan.';
        } elseif ($template !== null) {
            if ($template['scope'] === 'equipo' && !in_array($target, ['equipo', 'tipo'], true)) {
                $errors[] = 'Este checklist es de equipos: elegí un equipo o un tipo de equipo.';
            } elseif ($template['scope'] !== 'equipo' && $target !== 'sector') {
                $errors[] = 'Este checklist se hace por sector: elegí el sector.';
            }
        }
        if ($target === 'equipo') {
            $eq = Equipment::findByUuid((string) ($in['equipment'] ?? ''));
            if ($eq === null || ($template && $template['equipment_type_id'] && (int) $eq['type_id'] !== (int) $template['equipment_type_id'])) {
                $errors[] = 'Elegí un equipo del tipo del checklist.';
            } else {
                $data['equipment_id'] = (int) $eq['id'];
            }
        } elseif ($target === 'tipo') {
            $data['equipment_type_id'] = $template['equipment_type_id'] ?? null;
            if (!$data['equipment_type_id']) {
                $errors[] = 'El checklist no tiene tipo de equipo.';
            }
            $data['sector_id'] = $sector ? (int) $sector['id'] : null; // opcional: solo los de ese sector (y subsectores)
        } elseif ($target === 'sector') {
            if ($sector === null) {
                $errors[] = 'Elegí el sector.';
            } else {
                $data['sector_id'] = (int) $sector['id'];
            }
        }
        $assignee = isset(self::ASSIGNEES[$in['assignee_type'] ?? '']) ? $in['assignee_type'] : 'sector_supervisors';
        $data += ['assignee_user_id' => null, 'assignee_role' => null];
        if ($assignee === 'user') {
            $u = Users::findByUuid((string) ($in['assignee_user'] ?? ''));
            $u ? $data['assignee_user_id'] = (int) $u['id'] : $errors[] = 'Elegí el usuario a cargo.';
        } elseif ($assignee === 'role') {
            $r = Roles::findBySlug((string) ($in['assignee_role'] ?? ''));
            $r ? $data['assignee_role'] = $r['slug'] : $errors[] = 'Elegí el rol a cargo.';
        }
        $resp = ($in['action_responsible'] ?? '') !== '' ? Users::findByUuid((string) $in['action_responsible']) : null;
        if ($errors) {
            return [null, $errors];
        }
        $data += [
            'name' => mb_substr($name, 0, 160), 'template_id' => (int) $template['id'], 'frequency' => $frequency, 'weekday' => $weekday,
            'monthday' => $monthday, 'target_type' => $target, 'assignee_type' => $assignee,
            'action_responsible_user_id' => $resp ? (int) $resp['id'] : null,
        ];
        if ($program === null) {
            $id = Programs::create($data + ['created_by' => UserAuth::user()['id'] ?? null]);
            $action = 'inspection_program.create';
        } else {
            $id = (int) $program['id'];
            Programs::update($id, $data);
            $action = 'inspection_program.update';
        }
        $fresh = Programs::findById($id);
        Audit::tenant($action, 'inspection_program', $fresh['uuid'], null, ['nombre' => $fresh['name'], 'frecuencia' => $frequency, 'objetivo' => $target]);
        if ($fresh['is_active']) {
            self::generate(ActionService::today(), $fresh); // que aparezcan ya las de hoy
        }
        return [$fresh, []];
    }

    /** Equipos o sectores sobre los que corre el programa hoy. @return list<array{0:?int, 1:?int}> [equipment_id, sector_id] */
    public static function targets(array $p): array
    {
        if ($p['target_type'] === 'sector') {
            return $p['sector_id'] ? [[null, (int) $p['sector_id']]] : [];
        }
        if ($p['target_type'] === 'equipo') {
            $eq = $p['equipment_id'] ? Equipment::findById((int) $p['equipment_id']) : null;
            return $eq && (int) $eq['is_active'] === 1 ? [[(int) $eq['id'], $eq['sector_id'] ? (int) $eq['sector_id'] : null]] : [];
        }
        $out = [];
        $sectors = $p['sector_id'] ? Sectors::withDescendants([(int) $p['sector_id']]) : null;
        foreach (Equipment::list(null, false, ['type_id' => (int) $p['equipment_type_id']], 5000) as $eq) {
            if (($eq['status'] ?? 'operativo') === 'baja') {
                continue;
            }
            if ($sectors !== null && !in_array((int) $eq['sector_id'], $sectors, true)) {
                continue;
            }
            $out[] = [(int) $eq['id'], $eq['sector_id'] ? (int) $eq['sector_id'] : null];
        }
        return $out;
    }

    /** Períodos cuyo vencimiento cae entre $from y $to. @return list<array{0:string, 1:string, 2:string}> [clave, desde, vence] */
    public static function periods(array $p, string $from, string $to): array
    {
        $out = [];
        $d = new \DateTimeImmutable($from);
        $end = new \DateTimeImmutable($to);
        for (; $d <= $end; $d = $d->modify('+1 day')) {
            $day = $d->format('Y-m-d');
            switch ($p['frequency']) {
                case 'diaria':
                    $out[] = [$day, $day, $day];
                    break;
                case 'semanal':
                    if ((int) $d->format('N') === (int) ($p['weekday'] ?: 1)) {
                        $out[] = [$d->format('o-\WW'), $d->modify('monday this week')->format('Y-m-d'), $day];
                    }
                    break;
                case 'mensual':
                    if ((int) $d->format('j') === min((int) ($p['monthday'] ?: 1), (int) $d->format('t'))) {
                        $out[] = [$d->format('Y-m'), $d->format('Y-m-01'), $day];
                    }
                    break;
            }
        }
        return $out;
    }

    /** El cron: crea las programadas de los últimos días y la próxima semana. @return int cuántas creó */
    public static function generate(string $today, ?array $only = null): int
    {
        $created = 0;
        $programs = $only !== null ? [$only] : Programs::all(true);
        foreach ($programs as $p) {
            if ($p['frequency'] === 'manual' || !(int) $p['is_active'] || !(int) $p['template_active']) {
                continue;
            }
            $since = max((new \DateTimeImmutable($today))->modify('-' . self::BEHIND_DAYS . ' days')->format('Y-m-d'), fecha($p['created_at'], 'Y-m-d'));
            $until = (new \DateTimeImmutable($today))->modify('+' . self::AHEAD_DAYS . ' days')->format('Y-m-d');
            $periods = self::periods($p, $since, $until);
            if (!$periods) {
                continue;
            }
            foreach (self::targets($p) as [$equipmentId, $sectorId]) {
                foreach ($periods as [$key, $dueFrom, $dueOn]) {
                    $created += InspectionSchedules::ensure((int) $p['id'], $equipmentId, $sectorId, $key, $dueFrom, $dueOn) ? 1 : 0;
                }
            }
        }
        return $created;
    }

    /** Usuarios a cargo de una programada (para avisos). @return list<int> */
    public static function assignees(array $schedule): array
    {
        $db = DB::tenant();
        switch ($schedule['assignee_type']) {
            case 'user':
                return $schedule['assignee_user_id'] ? [(int) $schedule['assignee_user_id']] : [];
            case 'role':
                $stmt = $db->prepare('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = ? AND u.is_active = 1');
                $stmt->execute([(string) $schedule['assignee_role']]);
                return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
            default:
                if (!$schedule['sector_id']) {
                    return [];
                }
                $sector = Sectors::findById((int) $schedule['sector_id']);
                $stmt = $db->prepare("SELECT DISTINCT us.user_id FROM user_sectors us JOIN sectors s ON s.id = us.sector_id WHERE ? LIKE CONCAT(s.path, '%')");
                $stmt->execute([$sector['path']]);
                return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        }
    }

    /** Lo que el usuario actual tiene a cargo (filtro "mías" de las programadas). */
    public static function mine(): array
    {
        $user = UserAuth::user();
        $sectorIds = $user ? \App\Models\UserSectors::forUser((int) $user['id']) : [];
        return ['user_id' => (int) ($user['id'] ?? 0), 'role' => (string) ($user['role_slug'] ?? ''),
            'sector_ids' => $sectorIds ? Sectors::withDescendants($sectorIds) : []];
    }

    /** Alcance para ver programadas: todo; sus sectores + lo suyo; solo lo suyo. */
    public static function scope(): ?array
    {
        return match (UserAuth::scope(InspectionService::MODULE)) {
            'todo'     => null,
            'sectores' => ['sector_ids' => SectorScope::sectorIds(InspectionService::MODULE) ?? [], 'mine' => self::mine()],
            default    => ['mine' => self::mine()],
        };
    }

    public static function setActive(array $p, bool $active): void
    {
        Programs::update((int) $p['id'], ['is_active' => $active ? 1 : 0]);
        Audit::tenant($active ? 'inspection_program.activate' : 'inspection_program.deactivate', 'inspection_program', $p['uuid']);
    }

    /** Omitir una programada (equipo en reparación, sector cerrado…): con motivo; cuenta aparte en el cumplimiento. */
    public static function skip(array $schedule, string $reason): ?string
    {
        if (!UserAuth::can(InspectionService::MODULE, 'cerrar')) {
            return 'No tenés permiso para omitir inspecciones.';
        }
        if ($schedule['status'] !== 'pendiente') {
            return 'Esa inspección ya no está pendiente.';
        }
        if (mb_strlen(trim($reason)) < 5) {
            return 'Escribí el motivo (mínimo 5 caracteres).';
        }
        InspectionSchedules::skip((int) $schedule['id'], (int) UserAuth::user()['id'], trim($reason));
        Audit::tenant('inspection_schedule.skip', 'inspection_schedule', $schedule['uuid'], null, ['motivo' => trim($reason)]);
        return null;
    }
}
