<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\AppReleases;
use App\Services\AdminAuth;
use App\Services\AppDistribution;
use App\Services\Audit;

/** Super-admin: subir versiones del APK de la app Android y activarlas o desactivarlas. */
final class AppReleasesController
{
    public function index(Request $request): Response
    {
        [$old, $errors] = Flash::pullInput();
        return Response::html(View::render('admin/app_releases', [
            'title'    => 'App Android',
            'releases' => AppReleases::all(),
            'next'     => AppReleases::maxCode() + 1,
            'old'      => $old,
            'errors'   => $errors,
            'download' => absolute_url('/descargas/android'),
            'inbox'    => AppDistribution::inbox(),
            'inboxDir' => 'storage/' . AppDistribution::INBOX,
            'chunk'    => AppDistribution::CHUNK_BYTES,
        ], 'layouts/admin'));
    }

    public function store(Request $request): Response
    {
        $file = $request->files['apk'] ?? null;
        try {
            // Subida por partes (la arma el navegador) o un APK dejado por FTP en la bandeja.
            if (($id = (string) $request->input('upload_id', '')) !== '') $file = AppDistribution::chunkFile($id);
            elseif (($name = (string) $request->input('inbox', '')) !== '') $file = AppDistribution::inboxFile($name);
        } catch (\App\Core\UserError $e) {
            Flash::add('danger', $e->getMessage());
            return Response::redirect('/admin/app-android');
        }
        $r = AppDistribution::upload($request->post, is_array($file) ? $file : null, (int) (AdminAuth::user()['id'] ?? 0) ?: null);
        if ($r['release'] === null) {
            Flash::add('danger', 'Revisá la versión: ' . implode(' ', $r['errors']));
            Flash::withInput($request->post, $r['errors']);
        } else {
            Flash::add('success', 'Versión ' . $r['release']['version_name'] . ' publicada: los celulares la van a ofrecer al abrir la app.');
        }
        return Response::redirect('/admin/app-android');
    }

    /** Subida por partes: iniciar (o retomar) → {id, received, chunk}. */
    public function chunkStart(Request $request): Response
    {
        try {
            return Response::json(AppDistribution::chunkStart((string) $request->input('name', ''), (int) $request->input('size', 0),
                strtolower((string) $request->input('sha256', ''))) + ['chunk' => AppDistribution::CHUNK_BYTES]);
        } catch (\App\Core\UserError $e) {
            return Response::jsonError($e->getMessage(), 422);
        }
    }

    /** Una parte (cuerpo crudo) en `offset` → {received}. */
    public function chunkAppend(Request $request, string $id): Response
    {
        try {
            return Response::json(['received' => AppDistribution::chunkAppend($id, (int) $request->input('offset', -1), $request->rawBody())]);
        } catch (\App\Core\UserError $e) {
            return Response::jsonError($e->getMessage(), 422);
        }
    }

    public function toggle(Request $request, string $uuid): Response
    {
        $r = AppReleases::findByUuid($uuid);
        if ($r !== null) {
            AppReleases::setActive((int) $r['id'], !(int) $r['is_active']);
            Audit::platform('app.release_toggle', 'app_release', $uuid, ['activa' => (bool) $r['is_active']], ['activa' => !(int) $r['is_active']]);
            Flash::add('success', 'Versión ' . $r['version_name'] . ((int) $r['is_active'] ? ' retirada.' : ' publicada de nuevo.'));
        }
        return Response::redirect('/admin/app-android');
    }
}
