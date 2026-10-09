<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Storage;
use App\Core\View;
use App\Models\PlatformSettings;
use App\Services\AdminAuth;
use App\Services\Audit;
use App\Services\Notify\CronRunner;
use App\Services\Notify\MailTransport;

/** Plataforma: canales de envío (SMTP, WhatsApp, Expo) y tareas programadas (cron, emails locales). */
final class PlatformSettingsController
{
    public function show(Request $request): Response
    {
        $s = fn (string $k, ?string $d = null) => PlatformSettings::get($k, $d);
        return Response::html(View::render('admin/settings', [
            'title' => 'Configuración de la plataforma',
            'mail' => [
                'transport' => $s('mail.transport', 'archivo'), 'host' => $s('mail.host', ''), 'port' => $s('mail.port', '465'),
                'security' => $s('mail.security', 'ssl'), 'username' => $s('mail.username', ''), 'has_password' => (bool) $s('mail.password'),
                'from_email' => $s('mail.from_email', ''), 'from_name' => $s('mail.from_name', config('app.name')),
            ],
            'wa' => ['enabled' => $s('whatsapp.enabled') === '1', 'phone_id' => $s('whatsapp.phone_id', ''), 'template' => $s('whatsapp.template', 'alerta_seguridad'),
                'language' => $s('whatsapp.language', 'es_AR'), 'has_token' => (bool) $s('whatsapp.token')],
            'push' => ['enabled' => $s('push.enabled', '1') === '1', 'has_token' => (bool) $s('push.expo_token'),
                'fcm' => \App\Services\Notify\Fcm::configured(), 'fcm_project' => json_decode((string) $s('push.fcm_google_services'), true)['project_info']['project_id'] ?? null,
                'fcm_debug' => \App\Services\Notify\Fcm::clientConfig('ar.com.securityapp.campo.debug') !== null],
        ], 'layouts/admin'));
    }

    public function save(Request $request): Response
    {
        $in = $request->post;
        $section = (string) ($in['section'] ?? '');
        if ($section === 'mail') {
            foreach (['transport', 'host', 'port', 'security', 'username', 'from_email', 'from_name'] as $k) {
                PlatformSettings::set('mail.' . $k, trim((string) ($in[$k] ?? '')));
            }
            if (($in['password'] ?? '') !== '') {
                PlatformSettings::setSecret('mail.password', (string) $in['password']);
            }
        } elseif ($section === 'whatsapp') {
            PlatformSettings::set('whatsapp.enabled', !empty($in['enabled']) ? '1' : '0');
            foreach (['phone_id', 'template', 'language'] as $k) {
                PlatformSettings::set('whatsapp.' . $k, trim((string) ($in[$k] ?? '')));
            }
            if (($in['token'] ?? '') !== '') {
                PlatformSettings::setSecret('whatsapp.token', (string) $in['token']);
            }
        } elseif ($section === 'fcm') {
            $gs = trim((string) ($in['google_services'] ?? ''));
            $sa = trim((string) ($in['service_account'] ?? ''));
            if ($error = \App\Services\Notify\Fcm::validateConfig($gs, $sa)) {
                Flash::add('danger', $error);
                return Response::redirect('/admin/configuracion#fcm');
            }
            if ($gs !== '') {
                PlatformSettings::set('push.fcm_google_services', json_encode(json_decode($gs, true), JSON_UNESCAPED_SLASHES));
            }
            if ($sa !== '') {
                PlatformSettings::setSecret('push.fcm_service_account', $sa);
                PlatformSettings::setSecret('push.fcm_token_cache', null);
            }
        } elseif ($section === 'push') {
            PlatformSettings::set('push.enabled', !empty($in['enabled']) ? '1' : '0');
            if (($in['token'] ?? '') !== '') {
                PlatformSettings::setSecret('push.expo_token', (string) $in['token']);
            }
        }
        Audit::platform('platform.settings', 'settings', null, null, ['seccion' => $section]);
        Flash::add('success', 'Configuración guardada.');
        return Response::redirect('/admin/configuracion');
    }

    public function testMail(Request $request): Response
    {
        $to = trim((string) $request->input('to', ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            Flash::add('danger', 'Email de destino inválido.');
            return Response::redirect('/admin/configuracion');
        }
        try {
            MailTransport::send($to, 'Prueba de envío · ' . config('app.name'), "Si recibiste este email, el envío funciona.\nEnviado por " . AdminAuth::user()['name'] . '.', null);
            Flash::add('success', MailTransport::transport() === 'smtp'
                ? "Email enviado a {$to} por SMTP. Revisá la bandeja (y spam)."
                : 'Transporte "archivo": el email se guardó en storage/mail (ver Tareas → Emails guardados).');
        } catch (\Throwable $e) {
            Flash::add('danger', 'No se pudo enviar: ' . $e->getMessage());
        }
        return Response::redirect('/admin/configuracion');
    }

    public function tasks(Request $request): Response
    {
        $files = glob(Storage::path('mail') . '/*.eml') ?: [];
        rsort($files);
        $view = (string) $request->input('mail', '');
        $mail = null;
        if ($view !== '' && preg_match('/^[\w\-.]+\.eml$/', $view) && is_file(Storage::path('mail/' . $view))) {
            $mail = self::parseEml((string) file_get_contents(Storage::path('mail/' . $view)));
        }
        return Response::html(View::render('admin/tasks', [
            'title'   => 'Tareas programadas',
            'cronUrl' => absolute_url('/cron/run') . '?key=' . CronRunner::key(),
            'lastRun' => PlatformSettings::get('cron.last_run'),
            'summary' => json_decode((string) PlatformSettings::get('cron.last_summary'), true),
            'mails'   => array_map('basename', array_slice($files, 0, 40)),
            'mail'    => $mail,
            'transport' => MailTransport::transport(),
        ], 'layouts/admin'));
    }

    public function runNow(Request $request): Response
    {
        $summary = CronRunner::run(25);
        Flash::add('success', 'Tareas ejecutadas: ' . json_encode($summary, JSON_UNESCAPED_UNICODE));
        return Response::redirect('/admin/tareas');
    }

    /** Lectura simple de un .eml propio (encabezados + partes base64) para verlo en el panel. */
    private static function parseEml(string $raw): array
    {
        [$head, $body] = array_pad(explode("\r\n\r\n", $raw, 2), 2, '');
        $headers = [];
        foreach (explode("\r\n", $head) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[trim($k)] = iconv_mime_decode(trim($v), 0, 'UTF-8') ?: trim($v);
            }
        }
        $text = '';
        $html = '';
        foreach (preg_split('/--b[0-9a-f]+(?:--)?\r\n/', $body) as $part) {
            [$ph, $pb] = array_pad(explode("\r\n\r\n", $part, 2), 2, '');
            $decoded = base64_decode(str_replace("\r\n", '', trim($pb)), true);
            if ($decoded === false) {
                continue;
            }
            if (str_contains($ph, 'text/html')) {
                $html = $decoded;
            } elseif (str_contains($ph, 'text/plain') || str_contains($head, 'text/plain')) {
                $text = $decoded;
            }
        }
        if ($text === '' && $html === '' && str_contains($head, 'base64')) {
            $text = (string) base64_decode(str_replace("\r\n", '', $body));
        }
        return ['headers' => $headers, 'text' => $text, 'html' => $html];
    }
}
