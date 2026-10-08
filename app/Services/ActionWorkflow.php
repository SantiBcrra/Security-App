<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Estados de una acción CAPA y transiciones permitidas (validadas siempre en el servidor).
 * "Vencida" no es un estado: abierta/en_curso con due_on anterior a hoy.
 */
final class ActionWorkflow
{
    public const STATES = [
        'abierta'    => ['label' => 'Abierta', 'class' => 'secondary'],
        'en_curso'   => ['label' => 'En curso', 'class' => 'info'],
        'cerrada'    => ['label' => 'Cerrada · a verificar', 'class' => 'warning'],
        'verificada' => ['label' => 'Verificada', 'class' => 'success'],
        'cancelada'  => ['label' => 'Cancelada', 'class' => 'dark'],
    ];

    public const TYPES = ['correctiva' => 'Correctiva', 'preventiva' => 'Preventiva', 'mejora' => 'Mejora'];
    public const PRIORITIES = ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'critica' => 'Crítica'];
    public const PRIORITY_CLASS = ['baja' => 'secondary', 'media' => 'info', 'alta' => 'warning', 'critica' => 'danger'];
    public const ORIGINS = ['observacion' => 'Observación', 'inspeccion' => 'Inspección', 'incidente' => 'Incidente',
        'ronda' => 'Ronda', 'auditoria' => 'Auditoría', 'manual' => 'Manual'];

    /**
     * acción => desde qué estados, a cuál, qué permiso pide, si el responsable puede hacerla aunque su
     * rol no tenga ese permiso (owner) y si el comentario es obligatorio.
     */
    public const TRANSITIONS = [
        'tomar'     => ['from' => ['abierta'], 'to' => 'en_curso', 'perm' => 'editar', 'owner' => true, 'comment' => false, 'label' => 'Tomar (empezar a trabajar)'],
        'cerrar'    => ['from' => ['abierta', 'en_curso'], 'to' => 'cerrada', 'perm' => 'cerrar', 'owner' => true, 'comment' => false, 'label' => 'Cerrar con evidencia'],
        'verificar' => ['from' => ['cerrada'], 'to' => 'verificada', 'perm' => 'verificar', 'owner' => false, 'comment' => false, 'label' => 'Verificar: fue eficaz'],
        'rechazar'  => ['from' => ['cerrada'], 'to' => 'en_curso', 'perm' => 'verificar', 'owner' => false, 'comment' => true, 'label' => 'Rechazar: no fue eficaz'],
        'cancelar'  => ['from' => ['abierta', 'en_curso'], 'to' => 'cancelada', 'perm' => 'cerrar', 'owner' => false, 'comment' => true, 'label' => 'Cancelar'],
    ];

    public static function label(string $status): string
    {
        return self::STATES[$status]['label'] ?? $status;
    }

    public static function isOpen(string $status): bool
    {
        return in_array($status, ['abierta', 'en_curso'], true);
    }

    public static function isOverdue(array $action, string $today): bool
    {
        return self::isOpen($action['status']) && $action['due_on'] < $today;
    }

    /** Transiciones que el usuario actual puede hacer sobre la acción en su estado actual. */
    public static function available(array $action): array
    {
        return array_filter(self::TRANSITIONS, fn ($t, $key) => in_array($action['status'], $t['from'], true)
            && ActionService::canDo($action, $key), ARRAY_FILTER_USE_BOTH);
    }
}
