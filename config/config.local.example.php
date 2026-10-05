<?php
// Plantilla de config.local.php. En Etapa 1 el instalador web lo genera solo.
// Para desarrollo local: copiar como config.local.php y completar.
return [
    'app' => [
        'env'   => 'local',
        'debug' => true,
    ],
    'db' => [
        'master' => [
            'host'     => 'localhost',
            'port'     => 3306,
            'database' => 'secapp_master',
            'username' => 'root',
            'password' => '',
        ],
    ],
];
