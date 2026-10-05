<?php
// Valores por defecto (versionados). Las credenciales y overrides del entorno van en
// config.local.php, que NO va a git y lo genera el instalador (Etapa 1).
return [
    'app' => [
        'name'     => 'Security App',
        'version'  => '0.1.0',
        'env'      => 'production',   // 'local' en XAMPP (vía config.local.php)
        'debug'    => false,          // solo true en local; en producción SIEMPRE false
        'timezone' => 'America/Argentina/Buenos_Aires', // zona por defecto para mostrar fechas
    ],

    // Base maestra: empresas, super-admin y credenciales de la base de cada empresa.
    // Cada empresa tiene su propia base, 100% independiente (se registra en Etapa 2).
    'db' => [
        'master' => [
            'host'     => 'localhost',
            'port'     => 3306,
            'database' => '',
            'username' => '',
            'password' => '',
        ],
    ],

    'session' => [
        'name'     => 'secapp_sid',
        'lifetime' => 12 * 60 * 60, // 12 h: cubre la jornada sin relogueos
    ],
];
