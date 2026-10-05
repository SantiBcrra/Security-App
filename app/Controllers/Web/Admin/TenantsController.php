<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Core\Cuit;
use App\Core\Flash;
use App\Core\Logger;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Core\SignedUrl;
use App\Core\Tenant;
use App\Core\TenantFiles;
use App\Core\TenantSuspended;
use App\Core\Validator;
use App\Core\View;
use App\Models\PlatformAudit;
use App\Models\TenantAudit;
use App\Models\Tenants;
use App\Services\Audit;
use App\Services\Impersonation;
use App\Services\TenantProvisioner;
use App\Services\UserInvitation;
use App\Models\Roles;
use App\Models\Users;
use App\Policies\Permissions;

/** Empresas: alta (con su base propia), edición, logo, suspensión e impersonación. */
final class TenantsController
{
    private const LOGO_MAX_BYTES = 1024 * 1024;
    private const LABELS = [
        'name' => 'Nombre', 'slug' => 'Identificador', 'legal_name' => 'Razón social', 'cuit' => 'CUIT',
        'timezone' => 'Zona horaria', 'status' => 'Estado', 'plan' => 'Plan', 'reason' => 'Motivo',
        'db_host' => 'Servidor', 'db_port' => 'Puerto', 'db_name' => 'Base de datos', 'db_user' => 'Usuario',
    ];

    public function index(Request $request): Response
    {
        $pending = [];
        foreach (Migrator::statusAll() as $s) {
            $pending[$s['label']] = $s['error'] !== null ? 'error' : count($s['pending']);
        }
        return Response::html(View::render('admin/tenants/index', [
            'title'   => 'Empresas',
            'tenants' => Tenants::all(),
            'pending' => $pending,
        ], 'layouts/admin'));
    }

    public function create(Request $request): Response
    {
        [$old, $errors] = Flash::pullInput();
        return Response::html(View::render('admin/tenants/create', [
            'title'  => 'Nueva empresa',
            'errors' => $errors,
            'old'    => $old + [
                'name' => '', 'slug' => '', 'legal_name' => '', 'cuit' => '', 'plan' => '',
                'timezone' => 'America/Argentina/Buenos_Aires', 'status' => 'trial',
                'db_mode' => 'auto', 'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '',
            ],
        ], 'layouts/admin'));
    }

    public function store(Request $request): Response
    {
        $data = $request->post;
        $data['slug'] = self::slugify((string) (($data['slug'] ?? '') !== '' ? $data['slug'] : ($data['name'] ?? '')));
        $existing = ($data['db_mode'] ?? 'auto') === 'existing';

        $errors = self::validateCompany($data, true);
        if ($existing) {
            $errors += Validator::validate($data, [
                'db_host' => 'required|max:191', 'db_port' => 'required|max:5',
                'db_name' => 'required|max:64', 'db_user' => 'required|max:64',
            ], self::LABELS);
        }
        if ($errors) {
            return self::back('/admin/empresas/nueva', $data, $errors);
        }

        try {
            $uuid = TenantProvisioner::create(self::companyFields($data, true), $existing ? self::dbFields($data) : null);
        } catch (\DomainException $e) {
            Flash::add('danger', $e->getMessage());
            return self::back('/admin/empresas/nueva', $data, []);
        } catch (\Throwable $e) {
            Logger::error('Alta de empresa fallida', ['error' => $e->getMessage()]);
            Flash::add('danger', 'No se pudo crear la empresa: ' . $e->getMessage());
            return self::back('/admin/empresas/nueva', $data, []);
        }

        Flash::add('success', 'Empresa creada con su base de datos propia.');
        return Response::redirect('/admin/empresas/' . $uuid);
    }

    /** AJAX: probar una base existente antes de dar de alta. */
    public function testDb(Request $request): Response
    {
        try {
            return Response::json(TenantProvisioner::probe(self::dbFields($request->post)));
        } catch (\Throwable $e) {
            return Response::jsonError($e instanceof \PDOException ? 'No se pudo conectar: ' . $e->getMessage() : $e->getMessage(), 422);
        }
    }

    public function show(Request $request, string $uuid): Response
    {
        $tenant = Tenants::findByUuid($uuid);
        if ($tenant === null) {
            return Response::html(View::render('errors/404'), 404);
        }
        [$old, $errors] = Flash::pullInput();

        $tenantEvents = [];
        $admins = [];
        $dbError = null;
        try {
            $pdo = Tenant::connect($tenant);
            $tenantEvents = TenantAudit::latest($pdo, 15);
            try {
                $admins = $pdo->query("SELECT u.name, u.email, u.is_active, u.password_hash IS NOT NULL AS activated, u.last_login_at
                    FROM users u JOIN roles r ON r.id = u.role_id WHERE r.slug = 'admin_empresa' ORDER BY u.name")->fetchAll();
            } catch (\PDOException) {
                $admins = null; // base sin migrar todavía (falta "Actualizar base de datos")
            }
        } catch (\Throwable $e) {
            $dbError = $e->getMessage();
        }

        return Response::html(View::render('admin/tenants/show', [
            'title'          => $tenant['name'],
            'tenant'         => $tenant,
            'old'            => $old + $tenant,
            'errors'         => $errors,
            'logoUrl'        => $tenant['logo_path'] ? SignedUrl::make('/archivos/logo/' . $tenant['uuid'], 600) : null,
            'platformEvents' => PlatformAudit::forEntity('tenant', $uuid, 15),
            'tenantEvents'   => $tenantEvents,
            'dbError'        => $dbError,
            'admins'         => $admins,
            'invitation'     => UserInvitation::pullFlash(),
        ], 'layouts/admin'));
    }

    public function update(Request $request, string $uuid): Response
    {
        $tenant = Tenants::findByUuid($uuid);
        if ($tenant === null) {
            return Response::redirect('/admin/empresas');
        }
        $data = $request->post;
        if ($errors = self::validateCompany($data, false)) {
            return self::back('/admin/empresas/' . $uuid, $data, $errors);
        }
        $changes = self::companyFields($data, false);
        $before = array_intersect_key($tenant, $changes);
        $diff = array_diff_assoc(array_map('strval', $changes), array_map('strval', $before));
        if ($diff) {
            Tenants::update($uuid, $changes);
            self::audit('tenant.update', $tenant, array_intersect_key($before, $diff), $diff);
            Flash::add('success', 'Datos actualizados.');
        } else {
            Flash::add('info', 'No había cambios para guardar.');
        }
        return Response::redirect('/admin/empresas/' . $uuid);
    }

    public function uploadLogo(Request $request, string $uuid): Response
    {
        $tenant = Tenants::findByUuid($uuid);
        if ($tenant === null) {
            return Response::redirect('/admin/empresas');
        }
        try {
            $path = TenantFiles::storeUpload($uuid, $request->files['logo'] ?? [], 'logo', TenantFiles::IMAGE_TYPES, self::LOGO_MAX_BYTES);
        } catch (\DomainException $e) {
            Flash::add('danger', 'Logo: ' . $e->getMessage());
            return Response::redirect('/admin/empresas/' . $uuid);
        }
        Tenants::update($uuid, ['logo_path' => $path]);
        self::audit('tenant.logo', $tenant, ['logo_path' => $tenant['logo_path']], ['logo_path' => $path]);
        Flash::add('success', 'Logo actualizado.');
        return Response::redirect('/admin/empresas/' . $uuid);
    }

    public function suspend(Request $request, string $uuid): Response
    {
        return $this->changeStatus($request, $uuid, 'suspended');
    }

    public function activate(Request $request, string $uuid): Response
    {
        return $this->changeStatus($request, $uuid, 'active');
    }

    private function changeStatus(Request $request, string $uuid, string $status): Response
    {
        $tenant = Tenants::findByUuid($uuid);
        if ($tenant === null) {
            return Response::redirect('/admin/empresas');
        }
        $reason = trim((string) $request->input('reason', ''));
        if ($status === 'suspended' && $reason === '') {
            Flash::add('danger', 'Indicá el motivo de la suspensión.');
            return Response::redirect('/admin/empresas/' . $uuid);
        }
        $changes = [
            'status'        => $status,
            'status_reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
            'suspended_at'  => $status === 'suspended' ? gmdate('Y-m-d H:i:s') : null,
        ];
        Tenants::update($uuid, $changes);
        self::audit($status === 'suspended' ? 'tenant.suspend' : 'tenant.activate', $tenant,
            ['status' => $tenant['status'], 'status_reason' => $tenant['status_reason']],
            ['status' => $status, 'status_reason' => $changes['status_reason']]);
        Flash::add('success', $status === 'suspended' ? 'Empresa suspendida: nadie puede entrar hasta reactivarla.' : 'Empresa activa.');
        return Response::redirect('/admin/empresas/' . $uuid);
    }

    public function impersonate(Request $request, string $uuid): Response
    {
        $tenant = Tenants::findByUuid($uuid);
        if ($tenant === null) {
            return Response::redirect('/admin/empresas');
        }
        try {
            Impersonation::start($tenant);
        } catch (TenantSuspended $e) {
            Flash::add('danger', $e->getMessage() . ' Reactivala para entrar.');
            return Response::redirect('/admin/empresas/' . $uuid);
        } catch (\Throwable $e) {
            Logger::error('Impersonación fallida', ['tenant' => $uuid, 'error' => $e->getMessage()]);
            Flash::add('danger', 'No se pudo conectar a la base de la empresa.');
            return Response::redirect('/admin/empresas/' . $uuid);
        }
        return Response::redirect('/panel');
    }

    /** Primer administrador de la empresa: se crea en SU base con un link de activación. */
    public function createAdmin(Request $request, string $uuid): Response
    {
        $tenant = Tenants::findByUuid($uuid);
        if ($tenant === null) {
            return Response::redirect('/admin/empresas');
        }
        $name = trim((string) $request->input('name', ''));
        $email = mb_strtolower(trim((string) $request->input('email', '')));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Flash::add('danger', 'Para crear el administrador cargá nombre y un email válido.');
            return Response::redirect('/admin/empresas/' . $uuid);
        }
        try {
            Tenant::activate($tenant);
        } catch (TenantSuspended $e) {
            Flash::add('danger', $e->getMessage() . ' Reactivala primero.');
            return Response::redirect('/admin/empresas/' . $uuid);
        }
        if (Users::emailTaken($email)) {
            Flash::add('danger', 'Ya existe un usuario con ese email en la empresa.');
            return Response::redirect('/admin/empresas/' . $uuid);
        }
        $role = Roles::findBySlug(Permissions::ADMIN_ROLE);
        $user = Users::findById(Users::create(['name' => $name, 'email' => $email, 'role_id' => (int) $role['id']]));
        $link = UserInvitation::issue($user);
        UserInvitation::flash($user, $link);
        Audit::platform('tenant.admin.create', 'tenant', $uuid, null, ['name' => $name, 'email' => $email]);
        Audit::tenant('user.create', 'user', $user['uuid'], null, ['name' => $name, 'email' => $email, 'rol' => $role['name']]);
        Tenant::deactivate();
        Flash::add('success', "Administrador {$name} creado. Compartile el link para que active su cuenta (vence en " . UserInvitation::TTL_HOURS . ' h).');
        return Response::redirect('/admin/empresas/' . $uuid);
    }

    public function stopImpersonation(Request $request): Response
    {
        $uuid = Impersonation::stop();
        return Response::redirect($uuid ? '/admin/empresas/' . $uuid : '/admin/empresas');
    }

    /** Logo por link firmado (sirve en <img> sin exponer storage). */
    public function logo(Request $request, string $uuid): Response
    {
        $tenant = SignedUrl::verify($request) ? Tenants::findByUuid($uuid) : null;
        $file = $tenant && $tenant['logo_path'] ? TenantFiles::path($uuid, $tenant['logo_path']) : null;
        return $file ? TenantFiles::response($file) : new Response('', 404);
    }

    // ── helpers ───────────────────────────────────────────────────────

    private static function validateCompany(array $data, bool $isNew): array
    {
        $rules = [
            'name'       => 'required|max:120',
            'legal_name' => 'max:191',
            'timezone'   => 'required|in:' . implode(',', Tenant::TIMEZONES),
            'plan'       => 'max:40',
        ];
        if ($isNew) {
            $rules['status'] = 'required|in:trial,active';
        }
        $errors = Validator::validate($data, $rules, self::LABELS);

        $cuit = trim((string) ($data['cuit'] ?? ''));
        if ($cuit !== '' && !Cuit::isValid($cuit)) {
            $errors['cuit'] = 'CUIT: el número no es válido (revisá el dígito verificador).';
        }
        if ($isNew) {
            $slug = (string) ($data['slug'] ?? '');
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/', $slug)) {
                $errors['slug'] = 'Identificador: solo minúsculas, números y guiones (máx. 40).';
            } elseif (Tenants::slugExists($slug)) {
                $errors['slug'] = 'Identificador: ya existe una empresa con ese identificador.';
            }
        }
        return $errors;
    }

    private static function companyFields(array $data, bool $isNew): array
    {
        $cuit = Cuit::normalize($data['cuit'] ?? '');
        $fields = [
            'name'       => trim((string) $data['name']),
            'legal_name' => trim((string) ($data['legal_name'] ?? '')) ?: null,
            'cuit'       => $cuit !== '' ? $cuit : null,
            'timezone'   => (string) $data['timezone'],
            'plan'       => trim((string) ($data['plan'] ?? '')) ?: null,
        ];
        if ($isNew) {
            $fields['slug'] = (string) $data['slug'];
            $fields['status'] = (string) $data['status'];
        }
        return $fields;
    }

    private static function dbFields(array $data): array
    {
        return [
            'host'     => trim((string) ($data['db_host'] ?? 'localhost')),
            'port'     => (int) ($data['db_port'] ?? 3306),
            'database' => trim((string) ($data['db_name'] ?? '')),
            'username' => trim((string) ($data['db_user'] ?? '')),
            'password' => (string) ($data['db_pass'] ?? ''),
        ];
    }

    public static function slugify(string $text): string
    {
        $text = strtolower(trim((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text)));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return substr(trim($text, '-'), 0, 40);
    }

    /** Audita en la plataforma y, si la base responde, también en la base de la empresa. */
    private static function audit(string $action, array $tenant, ?array $before, ?array $after): void
    {
        Audit::platform($action, 'tenant', $tenant['uuid'], $before, $after);
        try {
            Audit::tenant($action, 'tenant', $tenant['uuid'], $before, $after, Tenant::connect($tenant));
        } catch (\Throwable $e) {
            Logger::warning('No se pudo auditar en la base de la empresa', ['tenant' => $tenant['uuid'], 'error' => $e->getMessage()]);
        }
    }

    private static function back(string $path, array $data, array $errors): Response
    {
        unset($data['db_pass'], $data['csrf_token']);
        Flash::withInput($data, $errors);
        return Response::redirect($path);
    }
}
