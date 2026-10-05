<?php
declare(strict_types=1);

namespace App\Services\Notify;

use App\Core\MailMessage;
use App\Core\Smtp;
use App\Core\Storage;
use App\Models\PlatformSettings;

/**
 * Envío de emails. Transporte "archivo" (por defecto, ideal en local: guarda .eml en storage/mail)
 * o "smtp" con la configuración de la plataforma.
 */
final class MailTransport
{
    /** Reemplazable en tests. @var ?callable(MailMessage): void */
    public static $fake = null;

    public static function send(string $to, string $subject, string $text, ?string $html): void
    {
        $message = new MailMessage(
            (string) (PlatformSettings::get('mail.from_email') ?: 'no-responder@localhost'),
            (string) (PlatformSettings::get('mail.from_name') ?: config('app.name')),
            $to, $subject, $text, $html
        );
        if (self::$fake !== null) {
            (self::$fake)($message);
            return;
        }
        if (self::transport() === 'smtp') {
            (new Smtp())->send(self::smtpConfig(), $message);
            return;
        }
        $dir = Storage::path('mail');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
        if (file_put_contents($file, $message->toMime()) === false) {
            throw new \RuntimeException('No se pudo guardar el email en storage/mail.');
        }
    }

    public static function transport(): string
    {
        return PlatformSettings::get('mail.transport') === 'smtp' && PlatformSettings::get('mail.host') ? 'smtp' : 'archivo';
    }

    public static function smtpConfig(): array
    {
        return [
            'host'     => (string) PlatformSettings::get('mail.host'),
            'port'     => (int) (PlatformSettings::get('mail.port') ?: 465),
            'security' => (string) (PlatformSettings::get('mail.security') ?: 'ssl'),
            'username' => (string) PlatformSettings::get('mail.username'),
            'password' => (string) PlatformSettings::secret('mail.password'),
            'timeout'  => 8,
        ];
    }
}
