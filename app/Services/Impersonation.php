<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Session;
use App\Core\Tenant;

/** "Entrar como empresa" del super-admin: solo lectura y auditado al entrar y al salir. */
final class Impersonation
{
    public const TENANT_KEY = 'tenant_uuid';
    private const FLAG_KEY = 'impersonating';

    public static function start(array $tenant): void
    {
        Tenant::activate($tenant); // valida que no esté suspendida y que la base responda
        Session::regenerate();
        Session::put(self::TENANT_KEY, $tenant['uuid']);
        Session::put(self::FLAG_KEY, true);
        Audit::platform('tenant.impersonate.start', 'tenant', $tenant['uuid']);
        Audit::tenant('support.session.start', 'tenant', $tenant['uuid']);
    }

    public static function stop(): ?string
    {
        $uuid = Session::get(self::TENANT_KEY);
        if (!self::active() || !is_string($uuid)) {
            self::clear();
            return null;
        }
        Audit::platform('tenant.impersonate.stop', 'tenant', $uuid);
        if (Tenant::current() !== null) {
            Audit::tenant('support.session.stop', 'tenant', $uuid);
        }
        self::clear();
        return $uuid;
    }

    public static function active(): bool
    {
        return Session::get(self::FLAG_KEY) === true;
    }

    public static function clear(): void
    {
        Session::forget(self::TENANT_KEY);
        Session::forget(self::FLAG_KEY);
        Tenant::deactivate();
    }
}
