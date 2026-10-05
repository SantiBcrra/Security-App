<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\DB;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Router;
use App\Core\Spreadsheet;
use App\Core\Storage;
use App\Core\Tenant;
use App\Core\TenantFiles;
use App\Models\CatalogItems;
use App\Models\Employees;
use App\Models\Equipment;
use App\Models\Imports;
use App\Models\Positions;
use App\Models\Roles;
use App\Models\Sectors;
use App\Models\Sites;
use App\Models\Tenants;
use App\Models\Users;
use App\Models\UserSectors;
use App\Services\Impersonation;
use App\Services\Import\Importer;
use App\Services\IndustryTemplates;
use App\Services\SectorScope;
use App\Services\TenantProvisioner;
use App\Services\UserAuth;

/**
 * Etapa 4: datos maestros, sectores jerárquicos, alcance por sector, plantilla de rubro e
 * importación CSV/XLSX. Maestra de prueba `securityapp_test_md` + empresas md-a y md-b.
 */
$server = [
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1', 'port' => 3306,
    'username' => getenv('TEST_DB_USER') ?: 'root', 'password' => getenv('TEST_DB_PASS') ?: '',
];
$master = 'securityapp_test_md';
$st = ['ready' => false];

$root = function () use ($server): PDO {
    try {
        return new PDO("mysql:host={$server['host']};charset=utf8mb4", $server['username'], $server['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]);
    } catch (PDOException) {
        throw new SkipTest('sin MySQL local');
    }
};
$dropAll = function (PDO $root) use ($master): void {
    foreach ($root->query("SHOW DATABASES LIKE '{$master}%'")->fetchAll(PDO::FETCH_COLUMN) as $db) {
        $root->exec("DROP DATABASE `{$db}`");
    }
};

$setup = function () use (&$st, $root, $dropAll, $server, $master): void {
    if ($st['ready']) {
        return;
    }
    $pdo = $root();
    $dropAll($pdo);
    $pdo->exec("CREATE DATABASE `{$master}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    Config::set('app.key', base64_encode(random_bytes(32)));
    Config::set('db.master', $server + ['database' => $master]);
    DB::reset();
    $_SESSION = [];
    assert_same(null, Migrator::run(DB::master(), BASE_PATH . Migrator::MASTER_DIR)['error']);
    $company = ['legal_name' => null, 'cuit' => null, 'timezone' => 'America/Argentina/Buenos_Aires', 'status' => 'active', 'plan' => null];
    $st['a'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'MD A', 'slug' => 'md-a'] + $company, null));
    $st['b'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'MD B', 'slug' => 'md-b'] + $company, null));
    file_put_contents(Storage::path('installed.lock'), 'test');
    $st['ready'] = true;
};

/** Crea un XLSX mínimo válido (sharedStrings + números) para probar el lector propio. */
$makeXlsx = function (array $rows): string {
    $path = tempnam(sys_get_temp_dir(), 'xlsx') . '.xlsx';
    $strings = [];
    $sheetRows = '';
    foreach ($rows as $r => $cells) {
        $sheetRows .= '<row r="' . ($r + 1) . '">';
        foreach ($cells as $c => $value) {
            if ($value === null) {
                continue; // celda vacía: no aparece en el XML
            }
            $ref = chr(65 + $c) . ($r + 1);
            if (is_int($value) || is_float($value)) {
                $sheetRows .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
            } else {
                $strings[] = $value;
                $sheetRows .= '<c r="' . $ref . '" t="s"><v>' . (count($strings) - 1) . '</v></c>';
            }
        }
        $sheetRows .= '</row>';
    }
    $sst = '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    foreach ($strings as $s) {
        $sst .= '<si><t>' . htmlspecialchars($s, ENT_XML1) . '</t></si>';
    }
    $sst .= '</sst>';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Hoja1" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', $sst);
    $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheetRows . '</sheetData></worksheet>');
    $zip->close();
    return $path;
};

/** Sube un archivo a la empresa activa y crea la importación. */
$importFile = function (string $entity, string $source, string $name): array {
    $types = ['text/plain' => 'csv', 'text/csv' => 'csv', 'application/csv' => 'csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx', 'application/zip' => 'xlsx', 'application/octet-stream' => 'xlsx'];
    $relative = TenantFiles::storeFile(Tenant::current()['uuid'], $source, 'imports', $types, 5 * 1024 * 1024);
    return Importer::createFromStored($entity, $relative, $name);
};

$runAll = function (array $import): array {
    $progress = [];
    for ($guard = 0; $guard < 1000; $guard++) {
        $progress = Importer::processBatch(Imports::findByUuid($import['uuid']));
        if ($progress['done']) {
            break;
        }
    }
    return $progress;
};

return [
    'CSV: separador ;, BOM, Windows-1252 y comillas' => function () {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, "\xEF\xBB\xBF" . "DNI;Apellido y nombre;Sector\n30111222;\"Pérez, Juan\";Soldadura\n\n");
        assert_same([['DNI', 'Apellido y nombre', 'Sector'], ['30111222', 'Pérez, Juan', 'Soldadura']], Spreadsheet::read($path, 'x.csv'));
        file_put_contents($path, mb_convert_encoding("Nombre,Puesto\nMuñoz,Pañolero\n", 'Windows-1252', 'UTF-8'));
        assert_same(['Muñoz', 'Pañolero'], Spreadsheet::read($path, 'x.csv')[1]);
        unlink($path);
    },

    'XLSX propio: textos compartidos, números enteros, celdas vacías y fechas como serie' => function () use ($makeXlsx) {
        $path = $makeXlsx([['DNI', 'Nombre', 'Ingreso', 'Legajo'], [30111222, 'Ana', 45000, null], [27999888, 'Ñandú', null, 'L-9']]);
        $rows = Spreadsheet::read($path, 'x.xlsx');
        assert_same(['30111222', 'Ana', '45000', ''], $rows[1]);
        assert_same(['27999888', 'Ñandú', '', 'L-9'], $rows[2]);
        assert_same('2023-03-15', App\Resources\Resource::parseDate($rows[1][2]));
        assert_same(26, Spreadsheet::columnIndex('AA1'));
        unlink($path);
    },

    'fechas: dd/mm/aaaa, aaaa-mm-dd, d/m/aaaa e inválidas' => function () {
        $p = fn ($v) => App\Resources\Resource::parseDate($v);
        assert_same('2024-02-01', $p('01/02/2024'));
        assert_same('2024-02-01', $p('1/2/2024'));
        assert_same('2024-02-01', $p('2024-02-01'));
        assert_same(null, $p('31/02/2024'));
        assert_same(null, $p('ayer'));
    },

    'sectores: path, árbol de 3 niveles, mover y descendientes' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        $site = Sites::create(['name' => 'Planta 1']);
        $naveA = Sectors::create(['site_id' => $site, 'parent_id' => null, 'name' => 'Nave A']);
        $sold = Sectors::create(['site_id' => $site, 'parent_id' => $naveA, 'name' => 'Soldadura']);
        $naveB = Sectors::create(['site_id' => $site, 'parent_id' => null, 'name' => 'Nave B']);
        assert_same("/{$naveA}/{$sold}/", Sectors::findById($sold)['path']);
        assert_same(2, (int) Sectors::findById($sold)['depth']);
        $ids = Sectors::withDescendants([$naveA]);
        sort($ids);
        assert_same([$naveA, $sold], $ids);
        assert_same('Planta 1 › Nave A › Soldadura', Sectors::labelMap()[$sold]['label']);
        assert_same([$sold, null], Sectors::resolve('Soldadura'));
        assert_same([$sold, null], Sectors::resolve('planta 1 > nave a > soldadura'));
        Sectors::move($sold, $site, $naveB);
        assert_same("/{$naveB}/{$sold}/", Sectors::findById($sold)['path']);
        $st['site'] = $site; $st['naveA'] = $naveA; $st['naveB'] = $naveB; $st['sold'] = $sold;
    },

    'alcance por sector: el supervisor ve sus sectores y los de abajo' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        $role = Roles::findBySlug('supervisor');
        $id = Users::create(['name' => 'Sup', 'email' => 'sup@md.test', 'role_id' => (int) $role['id'], 'password_hash' => password_hash('x', PASSWORD_DEFAULT)]);
        UserSectors::replace($id, [$st['naveB']]);
        UserAuth::setCurrent(Users::findById($id));
        $ids = SectorScope::sectorIds('observaciones');
        sort($ids);
        $expected = [$st['naveB'], $st['sold']];
        sort($expected);
        assert_same($expected, $ids, 'Nave B y Soldadura (que se movió debajo)');
        assert_same(null, SectorScope::sectorIds('datos_maestros'), 'en datos maestros el supervisor tiene alcance total');
        $admin = Users::create(['name' => 'Adm', 'email' => 'adm@md.test', 'role_id' => (int) Roles::findBySlug('admin_empresa')['id']]);
        UserAuth::setCurrent(Users::findById($admin));
        assert_same(null, SectorScope::sectorIds('observaciones'), 'admin ve todo');
        UserAuth::setCurrent(null);
    },

    'plantilla de rubro: carga catálogos y puestos, y es idempotente' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        $first = IndustryTemplates::apply('metalurgica');
        assert_true($first['created'] > 50, 'debe cargar los catálogos completos');
        $second = IndustryTemplates::apply('metalurgica');
        assert_same(0, $second['created'], 'aplicarla de nuevo no agrega nada');
        assert_same(4, count(CatalogItems::list(null, false, ['catalog' => 'severidad'])));
        assert_same('#dc3545', CatalogItems::findByName('severidad', 'Crítica')['color']);
        assert_true(Positions::exists('name', 'Soldador'));
        Tenant::activate($st['b']);
        assert_same(0, count(CatalogItems::list(null, true)), 'la empresa B no recibe la plantilla de A');
    },

    'importación de empleados: mapeo automático, errores por fila, duplicados y lotes' => function () use ($setup, &$st, $importFile, $runAll) {
        $setup();
        Tenant::activate($st['a']);
        $csv = "Nro Legajo;DNI;Apellido y Nombre;Puesto;Sector;Fecha Ingreso;Mail\n";
        for ($i = 1; $i <= 230; $i++) {
            $csv .= sprintf("%d;%d;APELLIDO%d NOMBRE%d;%s;%s;01/03/2020;\n", 1000 + $i, 20000000 + $i, $i, $i, $i % 2 ? 'Soldador' : 'Puesto Nuevo', $i % 3 ? 'Soldadura' : 'Nave A');
        }
        $csv .= "9001;123;Sin DNI valido;;;;\n";                     // DNI inválido
        $csv .= "9002;20000005;Repetido, En archivo;;;;\n";          // DNI repetido en el archivo
        $csv .= "9003;30999999;Lopez, Ana;;Sector Fantasma;;\n";    // sector inexistente
        $csv .= "9004;31999999;Gomez, Luis;;;32/13/2020;mal@\n";    // fecha y email inválidos
        $path = tempnam(sys_get_temp_dir(), 'emp');
        file_put_contents($path, $csv);
        $import = $importFile('empleados', $path, 'empleados.csv');
        $mapping = json_decode($import['mapping'], true);
        assert_same(1, $mapping['dni']);
        assert_same(0, $mapping['legajo']);
        assert_same(2, $mapping['apellido_nombre']);
        assert_same(6, $mapping['email']);
        assert_same([], Importer::mappingErrors($mapping, Importer::importer('empleados')));

        $preview = Importer::preview($import);
        assert_same(230, $preview['counts']['create']);
        assert_same(4, $preview['counts']['error']);
        assert_same(0, Employees::count(null, true), 'la vista previa no escribe nada');

        $progress = $runAll($import);
        assert_same(['processed' => 234, 'total' => 234, 'created' => 230, 'updated' => 0, 'errors' => 4, 'done' => true], $progress);
        assert_same(230, Employees::count());
        $e = Employees::findBy('dni', '20000001');
        assert_same('Apellido1', $e['last_name']);
        assert_same('Nombre1', $e['first_name']);
        assert_same('Soldador', $e['position_name']);
        assert_same('2020-03-01', $e['hire_date']);
        assert_true(Positions::exists('name', 'Puesto Nuevo'), 'los puestos que faltan se crean');
        assert_true(str_contains(Importer::errorsCsv(Imports::findByUuid($import['uuid'])), 'Sector Fantasma'));
        unlink($path);
    },

    'importación: reimportar actualiza sin duplicar y no borra datos que vienen vacíos' => function () use ($setup, &$st, $importFile, $runAll) {
        $setup();
        Tenant::activate($st['a']);
        $path = tempnam(sys_get_temp_dir(), 'emp');
        file_put_contents($path, "DNI,Apellido,Nombre,Telefono\n20000001,Apellido1,Nombre1,11-5555-0001\n");
        $progress = $runAll($importFile('empleados', $path, 'tel.csv'));
        assert_same(1, $progress['updated']);
        assert_same(230, Employees::count(), 'no duplica');
        $e = Employees::findBy('dni', '20000001');
        assert_same('11-5555-0001', $e['phone']);
        assert_same('Soldador', $e['position_name'], 'el puesto no se borra aunque la columna no venga');
        unlink($path);
    },

    'importación XLSX de equipos y de estructura (plantas > naves > sectores)' => function () use ($setup, &$st, $importFile, $runAll, $makeXlsx) {
        $setup();
        Tenant::activate($st['a']);
        $structure = $makeXlsx([['Planta', 'Nave', 'Sector'], ['Planta 2', 'Galpón Norte', 'Corte'], ['Planta 2', 'Galpón Norte', 'Plegado'], ['Planta 2', null, null]]);
        $progress = $runAll($importFile('sectores', $structure, 'estructura.xlsx'));
        assert_same(0, $progress['errors']);
        [$corte] = Sectors::resolve('Planta 2 > Galpón Norte > Corte');
        assert_true($corte !== null, 'crea la jerarquía');

        $xlsx = $makeXlsx([['Código', 'Equipo', 'Tipo', 'Planta', 'Sector', 'Año', 'Estado'],
            ['AE-01', 'Autoelevador 2,5 t', 'Autoelevador', 'Planta 2', 'Corte', 2019, 'Operativo'],
            ['PG-01', 'Puente grúa 5 t', 'Puente grúa', 'Planta 2', 'Galpón Norte > Plegado', 2015, 'Fuera de servicio'],
            ['XX-01', 'Mal', 'Prensa', 'Planta Inexistente', null, 1800, 'Rotísimo']]);
        $progress = $runAll($importFile('equipos', $xlsx, 'equipos.xlsx'));
        assert_same(2, $progress['created']);
        assert_same(1, $progress['errors']);
        $ae = Equipment::findBy('code', 'AE-01');
        assert_same('Autoelevador', $ae['type_name']);
        assert_same('Corte', $ae['sector_name']);
        assert_same('fuera_servicio', Equipment::findBy('code', 'PG-01')['status']);
        unlink($structure);
        unlink($xlsx);
    },

    'aislamiento: lo importado en A no existe en B' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['b']);
        assert_same(0, Employees::count(null, true));
        assert_same(0, Equipment::count(null, true));
        assert_same(null, Equipment::findBy('code', 'AE-01'));
    },

    'rutas: reportante sin acceso, auditor solo lectura, admin completo' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        $mk = fn (string $role, string $email) => Users::findById(Users::create(['name' => $role, 'email' => $email,
            'role_id' => (int) Roles::findBySlug($role)['id'], 'password_hash' => password_hash('x', PASSWORD_DEFAULT)]));
        $rep = $mk('reportante', 'rep@md.test');
        $aud = $mk('auditor', 'aud@md.test');
        $adm = $mk('admin_empresa', 'adm2@md.test');
        $go = function (string $method, string $path, array $user) use ($st): int {
            $_SESSION = [Impersonation::TENANT_KEY => $st['a']['uuid'], UserAuth::USER_KEY => $user['uuid'], '_csrf_token' => 't'];
            UserAuth::setCurrent(null);
            $router = new Router();
            (require BASE_PATH . '/app/routes.php')($router);
            return $router->dispatch(new Request($method, $path, [], ['csrf_token' => 't', 'name' => 'X']))->status;
        };
        assert_same(403, $go('GET', '/panel/datos/empleados', $rep));
        assert_same(200, $go('GET', '/panel/datos/empleados', $aud));
        assert_same(200, $go('GET', '/panel/datos/sectores', $aud));
        assert_same(403, $go('GET', '/panel/datos/empleados/nuevo', $aud));
        assert_same(403, $go('POST', '/panel/datos/puestos', $aud));
        assert_same(403, $go('GET', '/panel/importar', $aud));
        assert_same(200, $go('GET', '/panel/datos/riesgos', $adm));
        assert_same(200, $go('GET', '/panel/importar', $adm));
        assert_same(404, $go('GET', '/panel/datos/inventado', $adm));
        $_SESSION = [];
    },

    'limpieza: se borran las bases de prueba' => function () use ($root, $dropAll, &$st, $master) {
        if (!$st['ready']) {
            throw new SkipTest('no hubo setup');
        }
        Tenant::deactivate();
        UserAuth::setCurrent(null);
        DB::reset();
        @unlink(Storage::path('installed.lock'));
        $pdo = $root();
        $dropAll($pdo);
        Config::load(BASE_PATH . '/config');
        $_SESSION = [];
        assert_same([], $pdo->query("SHOW DATABASES LIKE '{$master}%'")->fetchAll(PDO::FETCH_COLUMN));
    },
];
