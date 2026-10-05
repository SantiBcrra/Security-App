<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\App;
use App\Core\Config;
use App\Core\DB;
use App\Core\EnvCheck;
use App\Core\Flash;
use App\Core\Logger;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Core\Storage;
use App\Core\Validator;
use App\Core\View;
use App\Models\PlatformAdmin;

/**
 * Instalador web (estilo WordPress): datos de MySQL → super-admin → instala.
 * Genera config/config.local.php, migra la base maestra, crea el super-admin y se bloquea
 * creando storage/installed.lock. Se puede reintentar si algo falla a mitad.
 */
final class InstallController
{
    private const TIMEZONES = [
        'America/Argentina/Buenos_Aires', 'America/Montevideo', 'America/Santiago',
        'America/Asuncion', 'America/La_Paz', 'America/Lima', 'America/Bogota', 'America/Mexico_City', 'UTC',
    ];

    private const LABELS = [
        'db_host' => 'Servidor', 'db_port' => 'Puerto', 'db_name' => 'Base de datos', 'db_user' => 'Usuario',
        'admin_name' => 'Nombre', 'admin_email' => 'Email', 'admin_password' => 'Contraseña',
        'admin_password_confirmation' => 'Repetir contraseña', 'env' => 'Entorno', 'timezone' => 'Zona horaria',
    ];

    public function show(Request $request): Response
    {
        if (App::isInstalled()) {
            return $this->locked();
        }
        [$old, $errors] = Flash::pullInput();
        $isLocal = in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true);

        return Response::html(View::render('install/index', [
            'title'        => 'Instalación',
            'requirements' => EnvCheck::requirements(),
            'canInstall'   => EnvCheck::passes(),
            'timezones'    => self::TIMEZONES,
            'errors'       => $errors,
            'old'          => $old + [
                'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '',
                'admin_name' => '', 'admin_email' => '',
                'env' => $isLocal ? 'local' : 'production', 'timezone' => 'America/Argentina/Buenos_Aires',
            ],
        ], 'layouts/blank'));
    }

    /** AJAX "Probar conexión". */
    public function testDb(Request $request): Response
    {
        if (App::isInstalled()) {
            return Response::jsonError('El sistema ya está instalado.', 403);
        }
        try {
            return Response::json(self::probe(self::dbConfig($request)));
        } catch (\Throwable $e) {
            return Response::jsonError(self::friendlyDbError($e), 422, 'db');
        }
    }

    public function install(Request $request): Response
    {
        if (App::isInstalled()) {
            return $this->locked();
        }
        if (!EnvCheck::passes()) {
            Flash::add('danger', 'El servidor no cumple los requisitos mínimos.');
            return Response::redirect('/install');
        }

        $data = $request->post;
        $errors = Validator::validate($data, [
            'db_host'                     => 'required|max:191',
            'db_port'                     => 'required|max:5',
            'db_name'                     => 'required|max:64',
            'db_user'                     => 'required|max:64',
            'admin_name'                  => 'required|max:120',
            'admin_email'                 => 'required|email|max:191',
            'admin_password'              => 'required|min:10|max:200',
            'admin_password_confirmation' => 'required|same:admin_password',
            'env'                         => 'required|in:local,production',
            'timezone'                    => 'required|in:' . implode(',', self::TIMEZONES),
        ], self::LABELS);
        if (!ctype_digit((string) ($data['db_port'] ?? ''))) {
            $errors['db_port'] ??= 'Puerto: tiene que ser un número.';
        }

        $db = self::dbConfig($request);
        if (!$errors) {
            try {
                self::probe($db);
            } catch (\Throwable $e) {
                $errors['db_name'] = self::friendlyDbError($e);
            }
        }
        if ($errors) {
            return self::backWithErrors($data, $errors);
        }

        // 1) config.local.php
        $local = [
            'app' => [
                'env'      => $data['env'],
                'debug'    => $data['env'] === 'local',
                'timezone' => $data['timezone'],
                'key'      => base64_encode(random_bytes(32)),
            ],
            'db' => ['master' => $db],
        ];
        $existing = BASE_PATH . '/config/config.local.php';
        if (is_file($existing)) {
            // Reintento: conservar la key ya generada (firma links/tokens emitidos).
            $prev = require $existing;
            $local['app']['key'] = $prev['app']['key'] ?? $local['app']['key'];
        }
        try {
            self::writeConfig($local);
        } catch (\Throwable $e) {
            Logger::error('Instalador: no se pudo escribir config.local.php', ['error' => $e->getMessage()]);
            Flash::add('danger', 'No se pudo escribir config/config.local.php. Revisá los permisos de la carpeta config/.');
            return self::backWithErrors($data, []);
        }
        Config::load(BASE_PATH . '/config');

        // 2) migraciones de la base maestra
        $result = Migrator::run(DB::master(), BASE_PATH . Migrator::MASTER_DIR);
        if ($result['error'] !== null) {
            Flash::add('danger', 'Falló la migración ' . $result['error']['migration'] . ': ' . $result['error']['message']
                . ' — corregí el problema y volvé a instalar (lo ya aplicado no se repite).');
            return self::backWithErrors($data, []);
        }

        // 3) super-admin + 4) bloqueo del instalador
        PlatformAdmin::upsert($data['admin_name'], $data['admin_email'], $data['admin_password']);
        file_put_contents(Storage::path('installed.lock'), gmdate('c') . "\n");
        Logger::info('Sistema instalado', ['admin' => mb_strtolower(trim($data['admin_email']))]);

        Flash::add('success', 'Instalación completa. Ingresá con el super-admin que acabás de crear.');
        return Response::redirect('/admin/login');
    }

    private function locked(): Response
    {
        return Response::html(View::render('install/locked', ['title' => 'Ya instalado'], 'layouts/blank'), 403);
    }

    private static function backWithErrors(array $data, array $errors): Response
    {
        unset($data['admin_password'], $data['admin_password_confirmation'], $data['db_pass'], $data['csrf_token']);
        Flash::withInput($data, $errors);
        return Response::redirect('/install');
    }

    private static function dbConfig(Request $request): array
    {
        return [
            'host'     => trim((string) $request->input('db_host', 'localhost')),
            'port'     => (int) $request->input('db_port', 3306),
            'database' => trim((string) $request->input('db_name', '')),
            'username' => trim((string) $request->input('db_user', '')),
            'password' => (string) $request->input('db_pass', ''),
        ];
    }

    /**
     * Conecta, verifica versión y permiso de crear tablas.
     * @return array{version:string, tables:int, warning:?string}
     */
    private static function probe(array $config): array
    {
        if ($config['database'] === '') {
            throw new \InvalidArgumentException('Falta el nombre de la base de datos.');
        }
        $pdo = DB::connect($config);
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $isMaria = stripos($version, 'mariadb') !== false;
        $num = preg_replace('/[^0-9.].*$/', '', $version);
        if (version_compare($num, $isMaria ? '10.4' : '5.7', '<')) {
            throw new \RuntimeException("Versión no soportada: {$version} (mínimo MySQL 5.7 o MariaDB 10.4).");
        }

        $pdo->exec('CREATE TABLE IF NOT EXISTS _secapp_install_probe (id INT) ENGINE=InnoDB');
        $pdo->exec('DROP TABLE _secapp_install_probe');

        $tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn();
        $warning = null;
        if ($tables > 0) {
            $hasOurs = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'platform_admins'")->fetchColumn();
            $warning = $hasOurs
                ? 'La base ya tiene una instalación previa: se conservan los datos y solo se aplica lo pendiente.'
                : "La base no está vacía ({$tables} tablas). Conviene usar una base nueva.";
        }
        return ['version' => $version, 'tables' => $tables, 'warning' => $warning];
    }

    private static function friendlyDbError(\Throwable $e): string
    {
        $msg = $e->getMessage();
        return match (true) {
            str_contains($msg, '[1045]') => 'Usuario o contraseña incorrectos.',
            str_contains($msg, '[1049]') => 'La base de datos no existe. Creala primero desde el panel del hosting.',
            str_contains($msg, '[2002]') => 'No se pudo conectar al servidor MySQL (revisá servidor y puerto).',
            str_contains($msg, '[1142]') => 'El usuario no tiene permiso para crear tablas en esa base.',
            $e instanceof \PDOException => 'Error de base de datos: ' . $msg,
            default => $msg,
        };
    }

    /** Escritura atómica: archivo temporal + rename, para no dejar un config a medias. */
    private static function writeConfig(array $local): void
    {
        $php = "<?php\n// Generado por el instalador el " . gmdate('Y-m-d H:i') . " UTC. NO subir a git.\n"
            . 'return ' . var_export($local, true) . ";\n";
        $target = BASE_PATH . '/config/config.local.php';
        $tmp = $target . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, $php, LOCK_EX) === false || !rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException('No se pudo escribir ' . $target);
        }
        @chmod($target, 0640);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($target, true);
        }
    }
}
