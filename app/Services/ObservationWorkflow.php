<?php
declare(strict_types=1);

namespace App\Services;

/** Estados de una observación y transiciones permitidas (validadas siempre en el servidor). */
final class ObservationWorkflow
{
    public const STATES = [
        'abierta'         => ['label' => 'Abierta', 'class' => 'secondary'],
        'en_analisis'     => ['label' => 'En análisis', 'class' => 'info'],
        'accion_asignada' => ['label' => 'Con acción asignada', 'class' => 'warning'],
        'cerrada'         => ['label' => 'Cerrada', 'class' => 'success'],
        'descartada'      => ['label' => 'Descartada', 'class' => 'dark'],
    ];

    /** acción => desde qué estados, a cuál, qué permiso pide y si el comentario es obligatorio */
    public const TRANSITIONS = [
        'analizar'  => ['from' => ['abierta'], 'to' => 'en_analisis', 'perm' => 'editar', 'comment' => false, 'label' => 'Tomar en análisis'],
        'asignar'   => ['from' => ['abierta', 'en_analisis', 'accion_asignada'], 'to' => 'accion_asignada', 'perm' => 'editar', 'comment' => false, 'label' => 'Asignar acción'],
        'cerrar'    => ['from' => ['abierta', 'en_analisis', 'accion_asignada'], 'to' => 'cerrada', 'perm' => 'cerrar', 'comment' => true, 'label' => 'Cerrar'],
        'descartar' => ['from' => ['abierta', 'en_analisis'], 'to' => 'descartada', 'perm' => 'cerrar', 'comment' => true, 'label' => 'Descartar'],
        'reabrir'   => ['from' => ['cerrada', 'descartada'], 'to' => 'en_analisis', 'perm' => 'cerrar', 'comment' => true, 'label' => 'Reabrir'],
    ];

    public static function label(string $status): string
    {
        return self::STATES[$status]['label'] ?? $status;
    }

    /** Acciones que el usuario actual puede hacer sobre una observación en este estado. */
    public static function available(string $status): array
    {
        return array_filter(self::TRANSITIONS, fn ($t) => in_array($status, $t['from'], true)
            && UserAuth::can('observaciones', $t['perm']));
    }

    public static function isOpen(string $status): bool
    {
        return in_array($status, ['abierta', 'en_analisis', 'accion_asignada'], true);
    }
}
