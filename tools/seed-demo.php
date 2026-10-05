<?php
declare(strict_types=1);

/**
 * Datos demo para probar el sistema en LOCAL (no se sube al hosting).
 * Uso: /Applications/XAMPP/xamppfiles/bin/php tools/seed-demo.php {empresa} [url-base]
 *   ej: php tools/seed-demo.php indumor http://localhost/securityapp
 *
 * Solo AGREGA: no modifica ni borra nada que ya exista (se puede correr varias veces).
 * Aplica antes las migraciones pendientes de la base de la empresa.
 * Las contraseñas no se eligen acá: se generan links de activación en storage/demo-links.txt.
 */

if (PHP_SAPI !== 'cli') {
    exit("Solo por consola.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';
restore_exception_handler();

use App\Core\DB;
use App\Core\Migrator;
use App\Core\Storage;
use App\Core\Tenant;
use App\Models\CatalogItems;
use App\Models\Contractors;
use App\Models\Employees;
use App\Models\Equipment;
use App\Models\Positions;
use App\Models\Roles;
use App\Models\Sectors;
use App\Models\Settings;
use App\Models\Sites;
use App\Models\Tenants;
use App\Models\Users;
use App\Models\UserSectors;
use App\Services\IndustryTemplates;
use App\Services\ObservationService;
use App\Services\UserAuth;
use App\Services\UserInvitation;

$slug = $argv[1] ?? '';
$baseUrl = rtrim($argv[2] ?? 'http://localhost/securityapp', '/');
$tenant = Tenants::findBySlug($slug) ?? exit("No existe la empresa \"{$slug}\".\n");
Tenant::activate($tenant);
echo "Empresa: {$tenant['name']} (base {$tenant['db_name']})\n";

// 0) Migraciones pendientes de la base de la empresa (lo mismo que el botón "Actualizar base de datos")
$result = Migrator::run(DB::tenant(), BASE_PATH . Migrator::TENANT_DIR);
if ($result['error'] !== null) {
    exit("Falló la migración {$result['error']['migration']}: {$result['error']['message']}\n");
}
echo 'Migraciones aplicadas: ' . (count($result['applied']) ?: 'ninguna pendiente') . "\n";

// 1) Catálogos (plantilla metalúrgica: solo agrega lo que falta)
$tpl = IndustryTemplates::apply('metalurgica');
echo "Plantilla: {$tpl['created']} ítems nuevos\n";

// 2) Planta y sectores
$siteId = (int) (Sites::findBy('name', 'Planta Indumor')['id'] ?? Sites::create([
    'name' => 'Planta Indumor', 'code' => 'IND', 'address' => 'Parque Industrial', 'city' => 'Pilar', 'province' => 'Buenos Aires',
    'lat' => '-34.4587000', 'lng' => '-58.9140000', 'geofence_radius_m' => 400,
]));
$sector = function (string $name, ?int $parent) use ($siteId): int {
    foreach (Sectors::list(null, true, ['site_id' => $siteId, 'parent_id' => $parent]) as $s) {
        if ($s['name'] === $name) {
            return (int) $s['id'];
        }
    }
    return Sectors::create(['site_id' => $siteId, 'parent_id' => $parent, 'name' => $name]);
};
$nave1 = $sector('Nave 1', null);
$nave2 = $sector('Nave 2', null);
$S = [
    'corte' => $sector('Corte', $nave1), 'plegado' => $sector('Plegado', $nave1), 'soldadura' => $sector('Soldadura', $nave1),
    'pintura' => $sector('Pintura', $nave2), 'armado' => $sector('Armado', $nave2),
    'deposito' => $sector('Depósito', null), 'mantenimiento' => $sector('Mantenimiento', null),
];
echo "Planta y sectores listos\n";

// 3) Contratista
$contractorId = (int) (Contractors::findBy('name', 'Montajes del Sur SRL')['id'] ?? Contractors::create([
    'name' => 'Montajes del Sur SRL', 'contact_name' => 'Ricardo Paz', 'phone' => '11 4444-5555',
    'art_insurer' => 'Prevención ART', 'art_expires_on' => gmdate('Y-m-d', strtotime('+20 days')),
]));

// 4) Empleados (DNI de la serie 9xxxxxxx: no chocan con DNIs reales de la planta)
$position = fn (string $name) => (int) (Positions::findBy('name', $name)['id'] ?? Positions::create(['name' => $name]));
$people = [
    ['Medina', 'Carlos', 'Supervisor de producción', 'corte', null],
    ['Herrera', 'Lucía', 'Responsable de calidad', 'mantenimiento', null],
    ['Ramírez', 'Jorge', 'Soldador', 'soldadura', null],
    ['Sosa', 'Marta', 'Operario de plegadora', 'plegado', null],
    ['Gómez', 'Pablo', 'Soldador', 'soldadura', null],
    ['Fernández', 'Diego', 'Operario de guillotina', 'corte', null],
    ['López', 'Sergio', 'Operador de autoelevador', 'deposito', null],
    ['Díaz', 'Mariana', 'Pintor', 'pintura', null],
    ['Romero', 'Hernán', 'Armador', 'armado', null],
    ['Torres', 'Gabriel', 'Operario de prensa', 'corte', null],
    ['Acosta', 'Natalia', 'Pañolero', 'deposito', null],
    ['Benítez', 'Martín', 'Operario de mantenimiento', 'mantenimiento', null],
    ['Ruiz', 'Julián', 'Electricista', 'mantenimiento', null],
    ['Molina', 'Leandro', 'Operador de puente grúa', 'armado', null],
    ['Castro', 'Rocío', 'Pintor', 'pintura', null],
    ['Ortiz', 'Emiliano', 'Tornero', 'corte', null],
    ['Silva', 'Agustín', 'Operario de plegadora', 'plegado', null],
    ['Núñez', 'Valeria', 'Encargado de depósito', 'deposito', null],
    ['Morales', 'Facundo', 'Armador', 'armado', null],
    ['Vega', 'Tomás', 'Soldador', 'soldadura', null],
    ['Paz', 'Ricardo', 'Armador', 'armado', 'contratista'],
    ['Godoy', 'Ezequiel', 'Soldador', 'armado', 'contratista'],
    ['Ríos', 'Brian', 'Armador', 'armado', 'contratista'],
];
$employeeIds = [];
foreach ($people as $i => [$last, $first, $pos, $sec, $contr]) {
    $dni = (string) (90000101 + $i);
    $existing = Employees::findBy('dni', $dni);
    $employeeIds["{$first} {$last}"] = $existing ? (int) $existing['id'] : Employees::create([
        'dni' => $dni, 'file_number' => $contr ? null : 'D-' . (101 + $i), 'last_name' => $last, 'first_name' => $first,
        'position_id' => $position($pos), 'sector_id' => $S[$sec], 'contractor_id' => $contr ? $contractorId : null,
        'hire_date' => gmdate('Y-m-d', strtotime('-' . (200 + $i * 97) . ' days')),
    ]);
}
echo count($employeeIds) . " empleados listos\n";

// 5) Usuarios vinculados a su empleado (sin contraseña: se activan con el link)
$links = [];
$makeUser = function (string $name, ?string $email, ?string $dni, string $role, ?string $employee, array $extra = []) use (&$links, $employeeIds): array {
    $user = $email ? Users::findByLogin($email) : Users::findByLogin((string) $dni);
    if ($user === null) {
        $id = Users::create(['name' => $name, 'email' => $email, 'dni' => $dni, 'role_id' => (int) Roles::findBySlug($role)['id']] + $extra);
        $user = Users::findById($id);
        if ($employee !== null) {
            Employees::update($employeeIds[$employee], ['user_id' => $id]);
        }
    }
    if (!$user['password_hash']) {
        $links[] = [$user['name'], $user['role_name'], $email ?? 'DNI ' . $dni, UserInvitation::issue($user)];
    }
    return $user;
};
$supervisor = $makeUser('Carlos Medina', 'carlos.medina@demo.test', null, 'supervisor', 'Carlos Medina');
UserSectors::replace((int) $supervisor['id'], array_unique(array_merge(UserSectors::forUser((int) $supervisor['id']), [$nave1])));
$hys = $makeUser('Lucía Herrera', 'lucia.herrera@demo.test', null, 'responsable_hys', 'Lucía Herrera');
$jorge = $makeUser('Jorge Ramírez', null, '90000103', 'reportante', 'Jorge Ramírez');
$marta = $makeUser('Marta Sosa', 'marta.sosa@demo.test', null, 'reportante', 'Marta Sosa');
$makeUser('Auditora ART (demo)', 'auditoria.art@demo.test', null, 'auditor', null,
    ['access_expires_at' => gmdate('Y-m-d 23:59:59', strtotime('+90 days'))]);
echo count($links) . " usuarios pendientes de activar\n";

// 6) Equipos
$type = fn (string $name) => (int) (CatalogItems::findByName('tipo_equipo', $name)['id'] ?? 0) ?: null;
foreach ([
    ['AE-01', 'Autoelevador Toyota 2,5 t', 'Autoelevador', 'deposito', 'Toyota', '8FGU25', 2019],
    ['PG-01', 'Puente grúa 5 t Nave 2', 'Puente grúa', 'armado', 'Demag', 'EKDR 5', 2014],
    ['PL-02', 'Plegadora hidráulica 3 m', 'Plegadora', 'plegado', 'Amada', 'HFE 100', 2017],
    ['SD-04', 'Soldadora MIG 350 A', 'Soldadora', 'soldadura', 'Lincoln', 'Power MIG 350', 2020],
] as [$code, $name, $t, $sec, $brand, $model, $year]) {
    if (!Equipment::findBy('code', $code)) {
        Equipment::create(['code' => $code, 'name' => $name, 'type_id' => $type($t), 'site_id' => $siteId, 'sector_id' => $S[$sec],
            'brand' => $brand, 'model' => $model, 'year' => $year, 'status' => 'operativo']);
    }
}
echo "Equipos listos\n";

// 7) Observaciones de ejemplo (una sola vez)
Settings::set('observaciones.anonimo_habilitado', Settings::get('observaciones.anonimo_habilitado') ?? '1');
if (Settings::get('demo.observaciones') !== '1') {
    $cat = fn (string $c, string $n) => (int) CatalogItems::findByName($c, $n)['id'];
    $eq = fn (string $code) => (int) Equipment::findBy('code', $code)['id'];
    $samples = [
        // [reporta, categoría, riesgo, severidad, sector, días atrás, inminente, equipo, descripción, flujo]
        [$jorge, 'Acto inseguro', 'Proyección de partículas', 'Alta', 'corte', 1, false, null, 'Operario amolando sin protección facial en la mesa de corte 2.', 'abierta'],
        [$marta, 'Condición insegura', 'Caída de personas al mismo nivel', 'Media', 'plegado', 2, false, 'PL-02', 'Mancha de aceite hidráulico en el piso al lado de la plegadora.', 'asignada'],
        [$supervisor, 'Condición insegura', 'Atrapamiento por partes móviles de máquinas', 'Crítica', 'plegado', 0, true, 'PL-02', 'La barrera fotoeléctrica de la plegadora no corta: se puede meter la mano con la máquina bajando.', 'analisis'],
        [$jorge, 'Condición insegura', 'Humos y gases de soldadura', 'Alta', 'soldadura', 5, false, 'SD-04', 'El extractor de humos del puesto 3 no funciona desde hace dos días.', 'asignada'],
        [$marta, 'Acto inseguro', 'Atropello o choque con vehículos (autoelevadores)', 'Alta', 'deposito', 7, false, 'AE-01', 'Autoelevador circulando con la carga en alto y sin visibilidad hacia adelante.', 'cerrada'],
        [$hys, 'Condición insegura', 'Incendio o explosión', 'Media', 'pintura', 10, false, null, 'Extintor del sector pintura con la carga vencida.', 'cerrada'],
        [$jorge, 'Buena práctica', null, 'Baja', 'soldadura', 3, false, null, 'Pablo acomodó las mangueras del oxicorte para que no queden cruzando el pasillo.', 'cerrada'],
        [$supervisor, 'Acto inseguro', 'Movimiento de cargas suspendidas (puente grúa, aparejos)', 'Crítica', 'armado', 12, true, 'PG-01', 'Personal de la contratista pasando por debajo de una carga suspendida.', 'cerrada'],
        [$marta, 'Condición insegura', 'Orden y limpieza deficiente', 'Baja', 'deposito', 15, false, null, 'Pallets apilados obstruyendo la salida de emergencia del depósito.', 'descartada'],
        [$hys, 'Condición insegura', 'Riesgo eléctrico', 'Alta', 'mantenimiento', 4, false, null, 'Tablero eléctrico del taller sin tapa y con cables expuestos.', 'asignada'],
        [$jorge, 'Acto inseguro', 'Ruido', 'Media', 'corte', 6, false, null, 'Varios operarios de la guillotina sin protectores auditivos.', 'analisis'],
        [$marta, 'Condición insegura', 'Caída de objetos o materiales', 'Media', 'armado', 9, false, null, 'Estantería de perfiles sin traba: un perfil quedó salido sobre el pasillo.', 'abierta'],
        [null, 'Acto inseguro', 'Sobreesfuerzo / manipulación manual de cargas', 'Media', 'armado', 8, false, null, 'Levantan chapas de más de 40 kg entre dos personas sin usar el aparejo.', 'abierta'],
        [$supervisor, 'Condición insegura', 'Cortes con chapas, rebabas o herramientas', 'Media', 'corte', 20, false, null, 'Recortes de chapa con filo acumulados al lado de la guillotina.', 'cerrada'],
    ];
    $created = 0;
    foreach ($samples as $i => [$reporter, $category, $risk, $severity, $sec, $daysAgo, $imminent, $equip, $text, $flow]) {
        UserAuth::setCurrent($reporter ?? $marta);
        $when = gmdate('Y-m-d H:i:s', time() - $daysAgo * 86400 - (3 + $i) * 3600);
        $obs = ObservationService::create([
            'category_id' => $cat('categoria', $category), 'risk_type_id' => $risk ? $cat('tipo_riesgo', $risk) : null,
            'severity_id' => $cat('severidad', $severity), 'site_id' => $siteId, 'sector_id' => $S[$sec],
            'equipment_id' => $equip ? $eq($equip) : null, 'description' => $text, 'location_text' => null,
            'lat' => sprintf('%.7f', -34.4587 + (($i * 37) % 11 - 5) * 0.00012), 'lng' => sprintf('%.7f', -58.9140 + (($i * 53) % 13 - 6) * 0.00015),
            'gps_accuracy_m' => 8 + $i, 'imminent_risk' => $imminent ? 1 : 0, 'is_anonymous' => $reporter === null ? 1 : 0,
            'created_at_device' => $when,
        ], [], [])['observation'];
        $created++;

        UserAuth::setCurrent($hys);
        $due = gmdate('Y-m-d', strtotime('+' . (($i % 4) * 5 - 3) . ' days')); // alguna ya vencida
        $go = fn (string $a, array $in = []) => ObservationService::transition(App\Models\Observations::findById((int) $obs['id']), $a, $in);
        if (in_array($flow, ['analisis', 'asignada', 'cerrada'], true) && $category !== 'Buena práctica') {
            $go('analizar', ['comment' => 'Se verificó en el lugar.']);
        }
        if ($flow === 'asignada' || ($flow === 'cerrada' && $category !== 'Buena práctica')) {
            $go('asignar', ['assigned_user' => $supervisor['uuid'], 'action_text' => 'Corregir la condición y capacitar al personal del sector.', 'action_due_on' => $due]);
        }
        if ($flow === 'cerrada') {
            $go('cerrar', ['comment' => $category === 'Buena práctica' ? 'Se reconoció la buena práctica en la charla de 5 minutos.' : 'Acción realizada y verificada en el lugar.']);
        }
        if ($flow === 'descartada') {
            $go('descartar', ['comment' => 'Era un sector de acopio transitorio ya liberado al momento de revisar.']);
        }
    }
    Settings::set('demo.observaciones', '1');
    UserAuth::setCurrent(null);
    echo "{$created} observaciones de ejemplo creadas\n";
} else {
    echo "Observaciones de ejemplo: ya estaban cargadas\n";
}

// 8) Links de activación (fuera de git)
if ($links) {
    $out = "Links de activación — {$tenant['name']} — generados " . gmdate('Y-m-d H:i') . " UTC (vencen en " . UserInvitation::TTL_HOURS . " h)\n";
    $out .= "Abrí cada link (mejor en una ventana de incógnito) y elegí la contraseña. Ingreso: {$baseUrl}/login/{$tenant['slug']}\n\n";
    foreach ($links as [$name, $role, $login, $link]) {
        $link = preg_replace('#^https?://[^/]+#', $baseUrl, $link);
        $out .= "{$name} ({$role}) — ingresa con: {$login}\n{$link}\n\n";
    }
    file_put_contents(Storage::path('demo-links.txt'), $out);
    echo 'Links de activación en storage/demo-links.txt' . "\n";
}
echo "Listo.\n";
