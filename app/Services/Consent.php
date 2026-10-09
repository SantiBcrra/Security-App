<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Consentimiento de la app Android (los empleados usan su celular personal; Ley 25.326). El texto vive en el servidor:
 * si cambia, se sube VERSION y la app lo vuelve a pedir. La aceptación queda guardada con el hash del texto.
 */
final class Consent
{
    public const VERSION = '2026-10';

    public static function text(): string
    {
        return "Esta app es la herramienta de trabajo de Seguridad e Higiene de tu empresa. Al usarla en tu celular:\n\n"
            . "• Tu ubicación (GPS) se registra SOLO mientras hacés una ronda y al escanear un punto o reportar algo. Fuera de eso la app no te sigue.\n"
            . "• Se guardan los reportes, fotos, firmas y escaneos que cargues, con la hora y el dispositivo.\n"
            . "• El botón de pánico envía tu ubicación por datos y, si no hay señal, por SMS a los números de emergencia de la empresa.\n"
            . "• Los datos los usa tu empleador solo para la gestión de seguridad e higiene y se guardan en sus servidores.\n"
            . "• Podés pedir acceso o corrección de tus datos a tu empleador (Ley 25.326 de Protección de Datos Personales).\n\n"
            . "Al tocar \"Acepto\" confirmás que leíste esta información.";
    }

    public static function hash(): string
    {
        return hash('sha256', self::VERSION . "\n" . self::text());
    }

    public static function accepted(int $userId): bool
    {
        $stmt = DB::tenant()->prepare('SELECT 1 FROM user_consents WHERE user_id = ? AND version = ?');
        $stmt->execute([$userId, self::VERSION]);
        return (bool) $stmt->fetchColumn();
    }

    public static function accept(int $userId, string $version, ?string $deviceUuid, ?string $ip): bool
    {
        if ($version !== self::VERSION) {
            return false; // aceptó un texto viejo: tiene que ver el vigente
        }
        DB::tenant()->prepare('INSERT IGNORE INTO user_consents (user_id, version, text_sha256, device_uuid, ip, accepted_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$userId, $version, self::hash(), $deviceUuid, $ip]);
        Audit::tenant('user.consent', 'user', UserAuth::user()['uuid'] ?? null, null, ['version' => $version, 'dispositivo' => $deviceUuid]);
        return true;
    }
}
