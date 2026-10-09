<?php
declare(strict_types=1);

use App\Core\ErrorHandler;

return [
    'tabla ausente: reconoce los códigos MySQL y MariaDB' => function (): void {
        $byCode = new PDOException('tabla ausente', 0);
        $byCode->errorInfo = ['42S02', 1146, 'Table missing'];
        assert_true(ErrorHandler::isMissingTable($byCode));

        $bySqlState = new PDOException('tabla ausente', 0);
        $bySqlState->errorInfo = ['42S02', 0, 'Table missing'];
        assert_true(ErrorHandler::isMissingTable($bySqlState));

        $other = new PDOException('otro error', 0);
        $other->errorInfo = ['23000', 1062, 'Duplicate'];
        assert_same(false, ErrorHandler::isMissingTable($other));
    },
];
