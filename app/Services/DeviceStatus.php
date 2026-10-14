<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\UserError;
use App\Models\Settings;

/**
 * Estado del celular de cada guardia (automatizaciones, fase 5): la app lo manda con cada sincronización (`device.status`)
 * y queda en `user_devices.status` (el último). El panel lo muestra y `RoundAutomation` avisa a SyH y supervisores
 * durante una ronda si la batería está por terminarse o el GPS sigue apagado.
 */
final class DeviceStatus
{
    /** El GPS apagado avisa a supervisores si sigue así este tiempo (al guardia le avisa la app enseguida). */
    public const GPS_OFF_MINUTES = 5;

    public static function batteryThreshold(): int
    {
        return max(5, min(50, (int) Settings::get('rondas.bateria_baja_porcentaje', '20')));
    }

    public static function syncMinutes(): int
    {
        return max(5, min(1440, (int) Settings::get('rondas.sin_sync_minutos', '15')));
    }

    /** Guarda lo que manda la app (normalizado: nunca se confía en tipos ni rangos). */
    public static function store(array $in): array
    {
        $device = ApiAuth::device() ?? throw new UserError('Sin dispositivo identificado.');
        $time = fn ($v) => is_string($v) && ($t = strtotime($v)) !== false && $t <= time() + 300 ? gmdate('Y-m-d H:i:s', $t) : null;
        $status = [
            'battery' => isset($in['battery']) && is_numeric($in['battery']) ? max(0, min(100, (int) $in['battery'])) : null,
            'charging' => !empty($in['charging']),
            'gps' => !array_key_exists('gps', $in) || !empty($in['gps']),
            'permission' => !array_key_exists('permission', $in) || !empty($in['permission']),
            'gps_off_since' => $time($in['gps_off_since'] ?? null),
            'pending' => max(0, (int) ($in['pending'] ?? 0)),
            'oldest_pending_at' => $time($in['oldest_pending_at'] ?? null),
            'app_version' => mb_substr((string) ($in['app_version'] ?? ''), 0, 20) ?: null,
            'at' => $time($in['at'] ?? null) ?? gmdate('Y-m-d H:i:s'),
        ];
        DB::tenant()->prepare('UPDATE user_devices SET status = ?, status_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([json_encode($status), (int) $device['id']]);
        return $status;
    }

    /** Último estado informado por un usuario (de cualquiera de sus celulares). */
    public static function latestForUser(int $userId): ?array
    {
        $s = DB::tenant()->prepare('SELECT status, status_at FROM user_devices WHERE user_id = ? AND status IS NOT NULL ORDER BY status_at DESC LIMIT 1');
        $s->execute([$userId]);
        $row = $s->fetch();
        return $row ? (json_decode((string) $row['status'], true) ?: []) + ['received_at' => $row['status_at']] : null;
    }

    /** Celulares que informaron estado en las últimas $hours horas, con sus problemas (para el panel de rondas). */
    public static function recent(int $hours = 24, ?int $onlyUser = null): array
    {
        $s = DB::tenant()->prepare('SELECT d.status, d.status_at, d.device_name, u.id AS user_id, u.name AS user_name FROM user_devices d JOIN users u ON u.id = d.user_id
            WHERE d.status IS NOT NULL AND d.status_at >= ?' . ($onlyUser !== null ? ' AND d.user_id = ' . (int) $onlyUser : '') . ' ORDER BY d.status_at DESC');
        $s->execute([gmdate('Y-m-d H:i:s', time() - $hours * 3600)]);
        return array_map(function ($r) {
            $st = (json_decode((string) $r['status'], true) ?: []) + ['received_at' => $r['status_at']];
            return ['user_name' => $r['user_name'], 'device_name' => $r['device_name'], 'status' => $st, 'problems' => self::problems($st)];
        }, $s->fetchAll());
    }

    /** @return list<string> problemas legibles ("Batería 12 %", "GPS apagado", …) */
    public static function problems(array $st, ?int $now = null): array
    {
        $now ??= time();
        $out = [];
        if ($st['battery'] !== null && $st['battery'] <= self::batteryThreshold() && empty($st['charging'])) $out[] = 'Batería ' . $st['battery'] . ' %';
        if (empty($st['permission'])) $out[] = 'Sin permiso de ubicación';
        elseif (empty($st['gps'])) $out[] = 'GPS apagado';
        if (!empty($st['oldest_pending_at']) && $now - strtotime($st['oldest_pending_at'] . ' UTC') >= self::syncMinutes() * 60) {
            $out[] = $st['pending'] . ' registro(s) sin enviar';
        }
        if (!empty($st['received_at']) && $now - strtotime($st['received_at'] . ' UTC') >= self::syncMinutes() * 60) {
            $out[] = 'Sin contacto hace ' . intdiv($now - strtotime($st['received_at'] . ' UTC'), 60) . ' min';
        }
        return $out;
    }
}
