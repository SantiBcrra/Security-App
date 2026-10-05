<?php
declare(strict_types=1);

// Front controller: todas las URLs pasan por acá (ver public/.htaccess).
require dirname(__DIR__) . '/app/bootstrap.php';

App\Core\App::run();
