<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Services\ApiAuth;
use App\Services\AppDistribution;
use App\Services\Consent;
use App\Services\UserAuth;

/** App Android: última versión publicada (sin sesión, la consulta también la pantalla de ingreso) y consentimiento. */
final class AppController
{
    public function android(Request $request): Response
    {
        $m = AppDistribution::manifest();
        return $m === null ? Response::jsonError('Todavía no hay una versión publicada.', 404, 'no_release') : Response::json($m);
    }

    /** El APK de la última versión, para la actualización desde la app (con su token: sin cuenta no se baja). */
    public function apk(Request $request): Response
    {
        return \App\Controllers\Web\DownloadsController::send('latest');
    }

    /** "Mis turnos": historial de los últimos 30 días con horas reales y salidas del predio (el guardia ve lo suyo). */
    public function myShifts(Request $request): Response
    {
        $user = \App\Services\UserAuth::user();
        $days = max(7, min(90, (int) $request->input('dias', 30)));
        $today = \App\Services\ShiftPlanner::localDay(gmdate('Y-m-d H:i:s'));
        $from = date('Y-m-d', strtotime($today . ' -' . ($days - 1) . ' days'));
        $report = \App\Services\ShiftReport::summary($from, $today, (int) $user['id']);
        $iso = fn ($v) => $v ? str_replace(' ', 'T', $v) . 'Z' : null;
        $shifts = array_map(function ($s) use ($iso) {
            $m = \App\Services\ShiftReport::measure($s);
            return \App\Services\ShiftService::shape($s) + ['status_label' => \App\Services\ShiftService::STATUS[$s['status']] ?? $s['status'],
                'end_mode' => $s['end_mode'], 'worked_minutes' => $m['worked'], 'late_minutes' => $m['late'], 'out_minutes' => $m['out'],
                'exits' => array_map(fn ($e) => ['left_at' => $iso($e['left_at']), 'returned_at' => $iso($e['returned_at']),
                    'reason' => \App\Services\ShiftService::REASONS[$e['reason']] ?? null, 'note' => $e['note']], \App\Services\ShiftService::exits((int) $s['id']))];
        }, array_reverse($report['shifts']));
        $t = $report['total'];
        return Response::json(['desde' => $from, 'hasta' => $today, 'turnos' => $shifts,
            'totales' => ['turnos' => $t['shifts'], 'horas' => \App\Services\ShiftReport::hours($t['worked']), 'llegadas_tarde' => $t['late_count'],
                'minutos_fuera' => $t['out'], 'ausencias' => $t['absent'], 'justificadas' => $t['justified']]]);
    }

    public function cancelAbsence(Request $request, string $uuid): Response
    {
        $a = \App\Services\ShiftAbsences::find($uuid);
        if ($a === null || (int) $a['user_id'] !== (int) \App\Services\UserAuth::user()['id']) return Response::jsonError('No se encontró el pedido.', 404);
        try {
            \App\Services\ShiftAbsences::cancel($a);
        } catch (\App\Core\UserError $e) {
            return Response::jsonError($e->getMessage(), 422);
        }
        return Response::json(['uuid' => $uuid, 'status' => 'cancelada']);
    }

    /** Pánico directo (sin esperar la cola): la app lo manda apenas se activa; si falla, va por SMS y queda en la cola. */
    public function panic(Request $request): Response
    {
        try {
            // Celular compartido del puesto: el pánico va a nombre del guardia de turno.
            $guard = \App\Services\ActingGuard::resolve(isset($request->post['as_user']) ? (string) $request->post['as_user'] : null);
            $r = \App\Services\ActingGuard::run($guard, fn () => \App\Services\GuardSafety::panic($request->post, 'datos'));
        } catch (\App\Core\UserError $e) {
            return Response::jsonError($e->getMessage(), 422);
        }
        return Response::json(['uuid' => $r['panic']['uuid'], 'duplicate' => $r['duplicate'], 'recibido' => true]);
    }

    /** "Atendido" de un pánico desde el celular (supervisores / SyH con alcance más amplio que "propios"). */
    public function ackPanic(Request $request, string $uuid): Response
    {
        $p = \App\Services\GuardSafety::findPanic($uuid);
        if ($p === null || !\App\Services\UserAuth::can('rondas', 'ver') || \App\Services\UserAuth::scope('rondas') === 'propios') {
            return Response::jsonError('Alerta inexistente o sin permiso.', 404);
        }
        $error = \App\Services\GuardSafety::ack($p, (string) $request->input('comment', ''));
        return $error ? Response::jsonError($error, 422) : Response::json(['acked' => true]);
    }

    public function consent(Request $request): Response
    {
        return Response::json(['version' => Consent::VERSION, 'texto' => Consent::text(), 'aceptado' => Consent::accepted((int) UserAuth::user()['id'])]);
    }

    public function accept(Request $request): Response
    {
        $ok = Consent::accept((int) UserAuth::user()['id'], (string) $request->input('version', ''), ApiAuth::device()['uuid'] ?? null, $request->ip());
        return $ok ? Response::json(['version' => Consent::VERSION, 'aceptado' => true])
            : Response::jsonError('El texto cambió: volvé a leerlo y aceptalo.', 409, 'consent_outdated');
    }
}
