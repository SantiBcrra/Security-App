<?php
declare(strict_types=1);

/**
 * Mini test runner (sin PHPUnit/Composer).
 * Uso: /Applications/XAMPP/xamppfiles/bin/php tests/run.php
 * Cada archivo tests/*Test.php devuelve un array ['descripción' => fn () => ...].
 */

require dirname(__DIR__) . '/app/bootstrap.php';
restore_exception_handler();
// Los tests no escriben en el storage real (logs, locks): usan una carpeta temporal.
App\Core\Storage::useRoot(sys_get_temp_dir() . '/secapp_test_storage');

final class AssertionFailed extends Exception
{
}

/** Lanzar desde un test para saltearlo (ej: no hay MySQL disponible). */
final class SkipTest extends Exception
{
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($message !== '' ? $message . ': ' : '')
            . 'se esperaba ' . var_export($expected, true) . ', llegó ' . var_export($actual, true));
    }
}

function assert_true(bool $condition, string $message = 'la condición no se cumple'): void
{
    if (!$condition) {
        throw new AssertionFailed($message);
    }
}

$passed = 0;
$failed = 0;
$skipped = 0;
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    $tests = require $file;
    echo basename($file) . PHP_EOL;
    foreach ($tests as $name => $test) {
        try {
            $test();
            $passed++;
            echo "  ✓ {$name}" . PHP_EOL;
        } catch (SkipTest $e) {
            $skipped++;
            echo "  - {$name} (salteado: {$e->getMessage()})" . PHP_EOL;
        } catch (Throwable $e) {
            $failed++;
            echo "  ✗ {$name}" . PHP_EOL . '      ' . $e->getMessage() . PHP_EOL;
        }
    }
}

echo PHP_EOL . "{$passed} OK, {$failed} fallidos" . ($skipped ? ", {$skipped} salteados" : '') . PHP_EOL;
exit($failed > 0 ? 1 : 0);
