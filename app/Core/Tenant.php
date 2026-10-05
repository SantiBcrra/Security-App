<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Empresa activa del request. Cada empresa tiene su propia base: "activar" una empresa es
 * conectar DB::tenant() a SU base. No hay tenant_id ni filtros por empresa en las consultas.
 *
 * Web: la empresa sale de la sesión (la setea el login de usuarios — Etapa 3 — o la
 * impersonación del super-admin). API (Etapa 5): saldrá del JWT.
 */
final class Tenant
{
    public const STATUSES = ['trial' => 'Prueba', 'active' => 'Activa', 'suspended' => 'Suspendida'];
    public const TIMEZONES = [
        'America/Argentina/Buenos_Aires', 'America/Montevideo', 'America/Santiago',
        'America/Asuncion', 'America/La_Paz', 'America/Lima', 'America/Bogota', 'America/Mexico_City', 'UTC',
    ];

    private static ?array $current = null;

    /** Conecta DB::tenant() a la base de la empresa. Rechaza empresas suspendidas. */
    public static function activate(array $tenant): void
    {
        if (($tenant['status'] ?? '') === 'suspended') {
            throw new TenantSuspended("La empresa {$tenant['name']} está suspendida.");
        }
        DB::useTenant(self::credentials($tenant));
        self::$current = $tenant;
    }

    public static function deactivate(): void
    {
        DB::clearTenant();
        self::$current = null;
    }

    /** Conexión a la base de una empresa sin activarla (migraciones, alta, soporte). */
    public static function connect(array $tenant): PDO
    {
        return DB::connect(self::credentials($tenant));
    }

    public static function credentials(array $tenant): array
    {
        return [
            'host'     => $tenant['db_host'],
            'port'     => (int) $tenant['db_port'],
            'database' => $tenant['db_name'],
            'username' => $tenant['db_user'],
            'password' => empty($tenant['db_pass_enc']) ? '' : Crypto::decrypt($tenant['db_pass_enc']),
        ];
    }

    public static function current(): ?array
    {
        return self::$current;
    }

    public static function timezone(): ?string
    {
        return self::$current['timezone'] ?? null;
    }
}
