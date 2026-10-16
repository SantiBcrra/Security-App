<?php
declare(strict_types=1);

/**
 * Orden de los puntos de una ruta: libre (cualquier orden; por defecto) | secuencial (el de la ruta) | azar (la app arma un
 * orden distinto en cada ronda). Cada ronda guarda el orden que le tocaba (`point_order`) y cada marca si fue fuera de orden
 * (solo se registra, no se rechaza). Idempotente.
 */
return function (PDO $db): void {
    $cols = fn (string $t) => $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$t}'")
        ->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('order_mode', $cols('patrol_routes'), true)) {
        $db->exec("ALTER TABLE patrol_routes ADD COLUMN order_mode VARCHAR(12) NOT NULL DEFAULT 'libre' AFTER expected_minutes");
    }
    if (!in_array('point_order', $cols('patrol_rounds'), true)) {
        $db->exec('ALTER TABLE patrol_rounds ADD COLUMN point_order TEXT NULL AFTER route_id');
    }
    if (!in_array('out_of_order', $cols('patrol_scans'), true)) {
        $db->exec('ALTER TABLE patrol_scans ADD COLUMN out_of_order TINYINT(1) NULL AFTER method');
    }
};
