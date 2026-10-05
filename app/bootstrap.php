<?php
declare(strict_types=1);

// Autoload PSR-4 simple (sin Composer): App\Core\Router → app/Core/Router.php
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require __DIR__ . '/Core/helpers.php';

App\Core\App::boot(dirname(__DIR__));
