<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\UserError;
use App\Core\Uuid;
use App\Models\WorkPermits;

/**
 * Permisos de trabajo en la app de campo (Etapa 13, entrega 3): la forma en que viajan al celular (pull y detalle)
 * y las operaciones que llegan por la sincronización (iniciar con firmas, medir, bloquear, suspender, cerrar…).
 * Las operaciones son idempotentes: si lo pedido ya está hecho (reenvío), se responde ok con el permiso actual.
 */
final class WorkPermitApi
{
    public const OPS = ['permit.start', 'permit.measure', 'permit.isolate', 'permit.release', 'permit.suspend', 'permit.resume', 'permit.close'];
    /** Mediciones que viajan al celular (las últimas). */
    private const MEASUREMENTS = 10;

    /** Lo que guarda el celular de cada permiso (sirve offline: ejecutores, mediciones, bloqueos y qué puede hacer). */
    public static function shape(array $p): array
    {
        $iso = fn (?string $utc) => $utc ? str_replace(' ', 'T', $utc) . 'Z' : null;
        $active = in_array($p['status'], WorkPermits::ACTIVE, true);
        $canWork = WorkPermitControls::canWork($p);
        return [
            'uuid' => $p['uuid'], 'code' => WorkPermits::format((int) $p['number']), 'types' => $p['type_list'],
            'type_labels' => array_map(fn ($t) => WorkPermitService::TYPES[$t]['short'] ?? $t, $p['type_list']),
            'status' => $p['status'], 'status_label' => WorkPermitService::STATES[$p['status']]['label'] ?? $p['status'],
            'status_reason' => $p['status_reason'], 'sector_uuid' => $p['sector_uuid'] ?? null, 'sector' => $p['sector_name'],
            'site' => $p['site_name'], 'equipment' => $p['equipment_code'] ? $p['equipment_code'] . ' · ' . $p['equipment_name'] : null,
            'location_text' => $p['location_text'], 'task' => $p['task'], 'contractor' => $p['contractor_name'],
            'valid_from' => $iso($p['valid_from']), 'ends_at' => $iso($p['ends_at']), 'extended' => $p['extended_until'] !== null,
            'suspended_at' => $iso($p['suspended_at']), 'fire_watch_until' => $iso($p['fire_watch_until']),
            'critical_fails' => (int) $p['critical_fails'],
            'requested_by' => $p['requested_by_name'], 'approved_by' => $p['approved_by_name'], 'mine' => WorkPermitService::isRequester($p),
            'workers' => array_map(fn ($w) => ['uuid' => $w['uuid'], 'name' => $w['employee_id'] ? $w['last_name'] . ', ' . $w['first_name'] : (string) $w['external_name'],
                'dni' => $w['employee_dni'] ?? $w['external_dni'], 'role' => $w['role'], 'signed' => $w['signature_uuid'] !== null], WorkPermits::workers((int) $p['id'])),
            'measurements' => array_map(fn ($m) => ['uuid' => $m['uuid'], 'measured_at' => $iso($m['measured_at']), 'o2' => self::num($m['o2']), 'lel' => self::num($m['lel']),
                'co' => self::num($m['co']), 'h2s' => self::num($m['h2s']), 'ok' => (bool) $m['ok'], 'out_of_range' => $m['out_of_range'], 'measured_by' => $m['measured_by']],
                array_slice(WorkPermits::measurements((int) $p['id']), 0, self::MEASUREMENTS)),
            'isolations' => array_map(fn ($i) => ['uuid' => $i['uuid'], 'point' => $i['point'], 'energy' => $i['energy'],
                'energy_label' => WorkPermitControls::ENERGIES[$i['energy']] ?? $i['energy'], 'device' => $i['device'], 'lock_number' => $i['lock_number'],
                'placed_by' => $i['placed_by'], 'placed_at' => $iso($i['placed_at']), 'zero_verified' => (bool) $i['zero_verified'],
                'removed_by' => $i['removed_by'], 'removed_at' => $iso($i['removed_at'])], WorkPermits::isolations((int) $p['id'])),
            'can' => [
                'approve' => $p['status'] === 'solicitado' && WorkPermitService::canApprove($p),
                'start'   => $p['status'] === 'aprobado' && WorkPermitService::canOperate($p),
                'close'   => in_array($p['status'], ['en_ejecucion', 'suspendido'], true) && WorkPermitService::canOperate($p),
                'work'    => $active && $canWork,
                'stop'    => $active && ($canWork || UserAuth::can(WorkPermitService::MODULE, 'aprobar')),
            ],
            'updated_at' => $iso($p['updated_at']),
        ];
    }

    /** Detalle con conexión: checklists, firmas y línea de tiempo (para autorizar desde el celular). */
    public static function detail(array $p): array
    {
        return self::shape($p) + [
            'checklists' => array_map(fn ($c) => ['type' => WorkPermitService::TYPES[$c['permit_type']]['label'] ?? $c['permit_type'], 'template' => $c['template_name'],
                'fails' => (int) $c['fails'], 'critical_fails' => (int) $c['critical_fails'],
                'answers' => array_map(fn ($a) => ['text' => $a['text'], 'critical' => (bool) $a['critical'], 'ok' => $a['ok'],
                    'value' => InspectionStructure::valueLabel($a['value'] === null ? null : (string) $a['value']), 'comment' => $a['comment'] ?? null], $c['answers'])], WorkPermits::checklists((int) $p['id'])),
            'signatures' => array_map(fn ($s) => ['role' => WorkPermitService::SIGNATURE_ROLES[$s['role']] ?? $s['role'], 'name' => $s['signer_name'],
                'at' => str_replace(' ', 'T', $s['signed_at']) . 'Z'], WorkPermits::signatures((int) $p['id'])),
            'events' => array_map(fn ($e) => ['type' => $e['type'], 'to' => $e['to_status'], 'comment' => $e['comment'], 'actor' => $e['actor_name'],
                'at' => str_replace(' ', 'T', $e['created_at']) . 'Z'], array_slice(array_reverse(WorkPermits::events((int) $p['id'])), 0, 30)),
        ];
    }

    /** Lo que muestra el QR colgado en el lugar a cualquier usuario de la empresa. */
    public static function verification(array $p): array
    {
        return ['uuid' => $p['uuid'], 'code' => WorkPermits::format((int) $p['number']),
            'type_labels' => array_map(fn ($t) => WorkPermitService::TYPES[$t]['short'] ?? $t, $p['type_list']),
            'status' => $p['status'], 'status_label' => WorkPermitService::STATES[$p['status']]['label'] ?? $p['status'],
            'valid' => $p['status'] === 'en_ejecucion' && strtotime($p['ends_at']) > time(),
            'sector' => $p['sector_name'], 'location_text' => $p['location_text'], 'task' => $p['task'], 'contractor' => $p['contractor_name'],
            'ends_at' => str_replace(' ', 'T', $p['ends_at']) . 'Z',
            'workers' => array_map(fn ($w) => ['name' => $w['employee_id'] ? $w['last_name'] . ', ' . $w['first_name'] : (string) $w['external_name'],
                'role' => $w['role'], 'signed' => $w['signature_uuid'] !== null], WorkPermits::workers((int) $p['id']))];
    }

    /**
     * Operación de la sincronización. @return array datos de la respuesta (permiso actual + extras)
     * @throws UserError con el motivo para mostrar en el celular
     */
    public static function apply(string $type, array $data, array $meta = []): array
    {
        $p = WorkPermits::findByUuid(strtolower((string) ($data['uuid'] ?? '')));
        if ($p === null || !WorkPermitService::canView($p)) {
            throw new UserError('El permiso no existe o ya no tenés acceso.');
        }
        $extra = [];
        $done = false;
        switch ($type) {
            case 'permit.start':
                $done = in_array($p['status'], ['en_ejecucion', 'suspendido', 'cerrado'], true) && $p['started_at'] !== null;
                $error = $done ? null : WorkPermitService::start($p, array_filter((array) ($data['worker_signatures'] ?? []), 'is_string'), $meta);
                break;
            case 'permit.measure':
                $r = WorkPermitControls::measure($p, ['uuid' => $data['measurement_uuid'] ?? ''] + array_intersect_key($data,
                    array_flip(['o2', 'lel', 'co', 'h2s', 'instrument', 'measured_by', 'measured_at'])));
                $error = $r['error'] ?? null;
                $done = !empty($r['duplicate']);
                $extra = ['measurement' => ['ok' => $r['ok'], 'out' => $r['out'], 'suspended' => $r['suspended']]];
                break;
            case 'permit.isolate':
                $uuid = strtolower((string) ($data['isolation_uuid'] ?? ''));
                if (!Uuid::isValid($uuid)) {
                    throw new UserError('Falta el identificador del bloqueo.');
                }
                $done = (bool) array_filter(WorkPermits::isolations((int) $p['id']), fn ($i) => $i['uuid'] === $uuid);
                $error = $done ? null : WorkPermitControls::isolate($p, ['uuid' => $uuid] + $data);
                break;
            case 'permit.release':
                $uuid = strtolower((string) ($data['isolation_uuid'] ?? ''));
                $iso = array_values(array_filter(WorkPermits::isolations((int) $p['id']), fn ($i) => $i['uuid'] === $uuid))[0] ?? null;
                $done = $iso !== null && $iso['removed_at'] !== null;
                $error = $done ? null : WorkPermitControls::release($p, $uuid, (string) ($data['removed_by'] ?? ''));
                break;
            case 'permit.suspend':
                $done = $p['status'] === 'suspendido';
                $error = $done ? null : WorkPermitControls::suspend($p, (string) ($data['comment'] ?? ''));
                break;
            case 'permit.resume':
                $done = $p['status'] === 'en_ejecucion';
                $error = $done ? null : WorkPermitControls::resume($p, (string) ($data['comment'] ?? ''));
                break;
            case 'permit.close':
                $done = $p['status'] === 'cerrado';
                $error = $done ? null : WorkPermitService::close($p, (string) ($data['signature'] ?? ''), (string) ($data['comment'] ?? ''), $meta);
                break;
            default:
                throw new UserError('Operación desconocida.');
        }
        if ($error !== null) {
            throw new UserError($error);
        }
        return ['permit' => self::shape(WorkPermits::findById((int) $p['id'])), 'duplicate' => $done] + $extra;
    }

    private static function num(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }
}
