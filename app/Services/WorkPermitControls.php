<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\UserError;
use App\Core\Uuid;
use App\Models\Settings;
use App\Models\WorkPermits;
use App\Services\Notify\Notifier;

/**
 * Controles específicos de los permisos de trabajo: mediciones de gases (fuera de rango → suspensión automática +
 * aviso crítico), bloqueos LOTO, vigía de fuego, suspender/reanudar y una extensión firmada por el autorizante.
 */
final class WorkPermitControls
{
    public const ENERGIES = ['electrica' => 'Eléctrica', 'neumatica' => 'Neumática', 'hidraulica' => 'Hidráulica', 'mecanica' => 'Mecánica',
        'termica' => 'Térmica', 'quimica' => 'Química', 'gravitatoria' => 'Gravitatoria'];
    /** Una medición vale para iniciar o reanudar si tiene a lo sumo esta antigüedad. */
    public const MEASUREMENT_VALID_MINUTES = 60;

    /** Límites de gases (configurables: la empresa los ajusta a su normativa y su instrumento). */
    public static function gasLimits(): array
    {
        $f = fn (string $k, string $d) => (float) str_replace(',', '.', (string) Settings::get('permisos.gas_' . $k, $d));
        return ['o2_min' => $f('o2_min', '19.5'), 'o2_max' => $f('o2_max', '23.5'), 'lel_max' => $f('lel_max', '10'),
            'co_max' => $f('co_max', '25'), 'h2s_max' => $f('h2s_max', '10')];
    }

    /** @return list<string> lo que quedó fuera de rango (vacío = ok) */
    public static function evaluateGas(?float $o2, ?float $lel, ?float $co, ?float $h2s): array
    {
        $l = self::gasLimits();
        $out = [];
        if ($o2 !== null && ($o2 < $l['o2_min'] || $o2 > $l['o2_max'])) {
            $out[] = "O₂ {$o2} % (rango {$l['o2_min']}–{$l['o2_max']})";
        }
        if ($lel !== null && $lel > $l['lel_max']) {
            $out[] = "explosividad {$lel} % LIE (máx. {$l['lel_max']})";
        }
        if ($co !== null && $co > $l['co_max']) {
            $out[] = "CO {$co} ppm (máx. {$l['co_max']})";
        }
        if ($h2s !== null && $h2s > $l['h2s_max']) {
            $out[] = "H₂S {$h2s} ppm (máx. {$l['h2s_max']})";
        }
        return $out;
    }

    /**
     * Registrar una medición. Fuera de rango con el trabajo en ejecución → se suspende solo y aviso CRÍTICO.
     * @param array $in o2, lel (obligatorios), co, h2s, instrument, measured_by, measured_at (ISO del celular), uuid
     * @return array{ok: bool, out: list<string>, suspended: bool, error?: string}
     */
    public static function measure(array $p, array $in): array
    {
        if (!self::canWork($p)) {
            return ['ok' => false, 'out' => [], 'suspended' => false, 'error' => 'No tenés permiso para registrar mediciones en este permiso.'];
        }
        if (!in_array('espacio_confinado', $p['type_list'], true)) {
            return ['ok' => false, 'out' => [], 'suspended' => false, 'error' => 'Las mediciones de gases son para espacio confinado.'];
        }
        if (!in_array($p['status'], ['aprobado', 'en_ejecucion', 'suspendido'], true)) {
            return ['ok' => false, 'out' => [], 'suspended' => false, 'error' => 'El permiso no está activo.'];
        }
        $num = fn (string $k) => ($in[$k] ?? '') === '' || !is_numeric(str_replace(',', '.', (string) $in[$k])) ? null : (float) str_replace(',', '.', (string) $in[$k]);
        $o2 = $num('o2');
        $lel = $num('lel');
        if ($o2 === null || $lel === null) {
            return ['ok' => false, 'out' => [], 'suspended' => false, 'error' => 'Cargá al menos O₂ y explosividad (LIE).'];
        }
        $uuid = strtolower((string) ($in['uuid'] ?? ''));
        if ($uuid !== '' && !Uuid::isValid($uuid)) {
            return ['ok' => false, 'out' => [], 'suspended' => false, 'error' => 'Identificador inválido.'];
        }
        if ($uuid !== '' && array_filter(WorkPermits::measurements((int) $p['id']), fn ($m) => $m['uuid'] === $uuid)) {
            return ['ok' => true, 'out' => [], 'suspended' => false, 'duplicate' => true]; // reenvío del celular
        }
        $at = time();
        if (($in['measured_at'] ?? '') !== '') {
            $t = strtotime((string) $in['measured_at']);
            if ($t === false || $t > time() + 600 || $t < time() - 12 * 3600) {
                return ['ok' => false, 'out' => [], 'suspended' => false, 'error' => 'Hora de la medición inválida.'];
            }
            $at = $t;
        }
        $co = $num('co');
        $h2s = $num('h2s');
        $out = self::evaluateGas($o2, $lel, $co, $h2s);
        $user = UserAuth::user();
        WorkPermits::addMeasurement((int) $p['id'], ['uuid' => $uuid ?: null, 'measured_at' => gmdate('Y-m-d H:i:s', $at), 'o2' => $o2, 'lel' => $lel, 'co' => $co,
            'h2s' => $h2s, 'instrument' => mb_substr(trim((string) ($in['instrument'] ?? '')), 0, 120) ?: null,
            'measured_by' => mb_substr(trim((string) ($in['measured_by'] ?? '')), 0, 160) ?: ($user['name'] ?? null), 'ok' => !$out,
            'out_of_range' => $out ? mb_substr(implode('; ', $out), 0, 191) : null, 'user_id' => $user['id'] ?? null]);
        WorkPermits::addEvent((int) $p['id'], 'measurement', ['comment' => $out ? 'Fuera de rango: ' . implode('; ', $out) : 'Medición en rango.',
            'data' => compact('o2', 'lel', 'co', 'h2s')] + self::actor());
        WorkPermits::update((int) $p['id'], []);
        $suspended = false;
        if ($out) {
            if ($p['status'] === 'en_ejecucion') {
                $suspended = self::suspend($p, 'Medición de gases fuera de rango: ' . implode('; ', $out), true) === null;
            }
            Notifier::dispatch('permit.gas_alarm', WorkPermits::findById((int) $p['id']), ['out' => $out]);
        }
        return ['ok' => !$out, 'out' => $out, 'suspended' => $suspended];
    }

    /** Requisitos extra para iniciar o reanudar según los tipos. @return ?string error */
    public static function readyToWork(array $p, ?string $since = null): ?string
    {
        if (in_array('espacio_confinado', $p['type_list'], true)) {
            $last = WorkPermits::measurements((int) $p['id'])[0] ?? null;
            $limit = time() - self::MEASUREMENT_VALID_MINUTES * 60;
            if ($last === null || !(int) $last['ok'] || strtotime($last['measured_at']) < $limit || ($since !== null && $last['measured_at'] < $since)) {
                return 'Espacio confinado: hace falta una medición de gases en rango de los últimos ' . self::MEASUREMENT_VALID_MINUTES . ' minutos'
                    . ($since !== null ? ' (posterior a la suspensión)' : '') . '.';
            }
        }
        if (in_array('loto', $p['type_list'], true)) {
            $placed = array_filter(WorkPermits::isolations((int) $p['id']), fn ($i) => $i['removed_at'] === null);
            if (!$placed) {
                return 'LOTO: registrá los puntos de bloqueo antes de empezar.';
            }
            if (array_filter($placed, fn ($i) => !(int) $i['zero_verified'])) {
                return 'LOTO: falta verificar energía cero en algún punto de bloqueo.';
            }
        }
        return null;
    }

    /** Requisitos para cerrar: sin candados puestos. */
    public static function readyToClose(array $p): ?string
    {
        $placed = array_filter(WorkPermits::isolations((int) $p['id']), fn ($i) => $i['removed_at'] === null);
        return $placed ? 'Quedan ' . count($placed) . ' punto(s) de bloqueo sin retirar: retiralos antes de cerrar.' : null;
    }

    /** Registrar un punto de bloqueo (LOTO). */
    public static function isolate(array $p, array $in): ?string
    {
        if (!self::canWork($p)) {
            return 'No tenés permiso para registrar bloqueos en este permiso.';
        }
        if (!in_array('loto', $p['type_list'], true)) {
            return 'Este permiso no es de bloqueo y etiquetado (LOTO).';
        }
        if (!in_array($p['status'], ['aprobado', 'en_ejecucion', 'suspendido'], true)) {
            return 'El permiso no está activo.';
        }
        $point = trim((string) ($in['point'] ?? ''));
        if (mb_strlen($point) < 3) {
            return 'Indicá el punto de bloqueo (ej. seccionador del tablero TG-2).';
        }
        $energy = isset(self::ENERGIES[$in['energy'] ?? '']) ? $in['energy'] : null;
        if ($energy === null) {
            return 'Elegí el tipo de energía.';
        }
        WorkPermits::addIsolation((int) $p['id'], ['uuid' => Uuid::isValid((string) ($in['uuid'] ?? '')) ? $in['uuid'] : null, 'point' => mb_substr($point, 0, 160), 'energy' => $energy,
            'device' => mb_substr(trim((string) ($in['device'] ?? '')), 0, 120) ?: null, 'lock_number' => mb_substr(trim((string) ($in['lock_number'] ?? '')), 0, 40) ?: null,
            'placed_by' => mb_substr(trim((string) ($in['placed_by'] ?? '')), 0, 160) ?: (UserAuth::user()['name'] ?? '—'),
            'zero_verified' => !empty($in['zero_verified']), 'user_id' => UserAuth::user()['id'] ?? null]);
        WorkPermits::addEvent((int) $p['id'], 'isolation', ['comment' => 'Bloqueo colocado: ' . $point . ' (' . self::ENERGIES[$energy] . ')'] + self::actor());
        WorkPermits::update((int) $p['id'], []);
        return null;
    }

    public static function release(array $p, string $isolationUuid, string $by): ?string
    {
        if (!self::canWork($p)) {
            return 'No tenés permiso para retirar bloqueos en este permiso.';
        }
        $iso = array_values(array_filter(WorkPermits::isolations((int) $p['id']), fn ($i) => $i['uuid'] === $isolationUuid))[0] ?? null;
        if ($iso === null || $iso['removed_at'] !== null) {
            return 'Ese bloqueo no existe o ya se retiró.';
        }
        $by = trim($by) !== '' ? mb_substr(trim($by), 0, 160) : (UserAuth::user()['name'] ?? '—');
        WorkPermits::removeIsolation((int) $iso['id'], $by);
        WorkPermits::addEvent((int) $p['id'], 'isolation', ['comment' => 'Bloqueo retirado: ' . $iso['point'] . ' (por ' . $by . ')'] + self::actor());
        WorkPermits::update((int) $p['id'], []);
        return null;
    }

    /** Frenar el trabajo (alarma, cambio de condiciones). Lo puede hacer el equipo, el autorizante o SyH. */
    public static function suspend(array $p, string $reason, bool $system = false): ?string
    {
        if (!$system && !(self::canWork($p) || UserAuth::can(WorkPermitService::MODULE, 'aprobar'))) {
            return 'No tenés permiso para suspender este permiso.';
        }
        if ($p['status'] !== 'en_ejecucion') {
            return 'Solo se suspende un trabajo en ejecución.';
        }
        if (mb_strlen(trim($reason)) < 5) {
            return 'Escribí el motivo (mínimo 5 caracteres).';
        }
        $error = self::changeStatus($p, 'suspendido', ['suspended_at' => gmdate('Y-m-d H:i:s'), 'status_reason' => trim($reason)], trim($reason), $system);
        if ($error === null && !$system) {
            Notifier::dispatch('permit.suspended', WorkPermits::findById((int) $p['id']), ['comment' => trim($reason)]);
        }
        return $error;
    }

    public static function resume(array $p, string $reason): ?string
    {
        if (!(self::canWork($p) || UserAuth::can(WorkPermitService::MODULE, 'aprobar'))) {
            return 'No tenés permiso para reanudar este permiso.';
        }
        if ($p['status'] !== 'suspendido') {
            return 'El permiso no está suspendido.';
        }
        if (time() > strtotime($p['ends_at'])) {
            return 'El permiso ya venció: hace falta uno nuevo.';
        }
        if (mb_strlen(trim($reason)) < 5) {
            return 'Contá qué se verificó para seguir (mínimo 5 caracteres).';
        }
        if (($ready = self::readyToWork($p, $p['suspended_at'])) !== null) {
            return $ready;
        }
        return self::changeStatus($p, 'en_ejecucion', ['status_reason' => null], trim($reason), false);
    }

    /** Una sola extensión, firmada por el autorizante (no el solicitante), antes de que venza. */
    public static function extend(array $p, string $until, string $signature, array $meta = []): ?string
    {
        if (!WorkPermitService::canApprove($p)) {
            return 'La extensión la firma quien autoriza permisos (y no el solicitante).';
        }
        if (!in_array($p['status'], ['aprobado', 'en_ejecucion', 'suspendido'], true)) {
            return 'Solo se extiende un permiso activo.';
        }
        if ($p['extended_until'] !== null) {
            return 'El permiso ya se extendió una vez: para seguir hace falta uno nuevo.';
        }
        if (time() > strtotime($p['ends_at'])) {
            return 'El permiso ya venció.';
        }
        $new = WorkPermitService::parseTime($until);
        if ($new === null) {
            return 'Fecha y hora inválidas.';
        }
        if ($new <= $p['ends_at']) {
            return 'La nueva hora tiene que ser posterior al vencimiento actual.';
        }
        if (strtotime($new) - strtotime($p['valid_until']) > WorkPermitService::maxHours() * 3600) {
            return 'La extensión es de hasta ' . WorkPermitService::maxHours() . ' horas más.';
        }
        try {
            $sig = Signatures::store($signature, 'permits');
        } catch (UserError $e) {
            return $e->getMessage();
        }
        $user = UserAuth::user();
        WorkPermits::update((int) $p['id'], ['extended_until' => $new, 'extended_by' => (int) $user['id'], 'extended_at' => gmdate('Y-m-d H:i:s')]);
        WorkPermits::addSignature((int) $p['id'], $sig + ['role' => 'extension', 'user_id' => (int) $user['id'], 'signer_name' => $user['name'],
            'ip' => $meta['ip'] ?? null, 'user_agent' => $meta['user_agent'] ?? null]);
        WorkPermits::addEvent((int) $p['id'], 'extended', ['comment' => 'Extendido hasta ' . fecha($new, 'd/m H:i')] + self::actor());
        Audit::tenant('permit.extend', 'work_permit', $p['uuid'], ['hasta' => $p['ends_at']], ['hasta' => $new]);
        return null;
    }

    /** Equipo del permiso: solicitante o quien tiene "editar"/"cerrar" en su alcance. */
    public static function canWork(array $p): bool
    {
        return WorkPermitService::canView($p) && (WorkPermitService::isRequester($p) || UserAuth::can(WorkPermitService::MODULE, 'editar')
            || UserAuth::can(WorkPermitService::MODULE, 'cerrar'));
    }

    private static function changeStatus(array $p, string $to, array $data, string $comment, bool $system): ?string
    {
        $db = DB::tenant();
        $db->beginTransaction();
        try {
            $locked = WorkPermits::findById((int) $p['id'], true);
            if ($locked['status'] !== $p['status']) {
                $db->rollBack();
                return 'El permiso cambió mientras tanto. Recargá la página.';
            }
            WorkPermits::update((int) $p['id'], ['status' => $to] + $data);
            WorkPermits::addEvent((int) $p['id'], 'status', ['from' => $p['status'], 'to' => $to, 'comment' => $comment] + ($system ? ['actor_name' => 'Sistema'] : self::actor()));
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        Audit::tenant('permit.' . $to, 'work_permit', $p['uuid'], ['estado' => $p['status']], ['estado' => $to, 'motivo' => $comment]);
        return null;
    }

    private static function actor(): array
    {
        $user = UserAuth::user();
        return $user ? ['user_id' => (int) $user['id'], 'actor_name' => $user['name']] : ['user_id' => null, 'actor_name' => 'Sistema'];
    }
}
