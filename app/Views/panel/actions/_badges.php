<?php
/** Helpers de badges para acciones (se incluye una vez por vista). */
use App\Services\ActionWorkflow;

$actionBadge = fn (string $s) => '<span class="badge text-bg-' . e(ActionWorkflow::STATES[$s]['class'] ?? 'secondary') . '">' . e(ActionWorkflow::label($s)) . '</span>';
$priorityBadge = fn (string $p) => '<span class="badge text-bg-' . e(ActionWorkflow::PRIORITY_CLASS[$p] ?? 'secondary') . '">' . e(ActionWorkflow::PRIORITIES[$p] ?? $p) . '</span>';
/** Vencimiento legible: "vence 12/10" o "vencida hace 3 días" (en rojo). */
$dueLabel = function (array $a, string $today): string {
    $date = date('d/m/Y', strtotime($a['due_on']));
    if (!ActionWorkflow::isOpen($a['status'])) {
        return '<span class="text-body-secondary">límite ' . e($date) . '</span>';
    }
    $days = (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($a['due_on']))->format('%r%a');
    if ($days < 0) {
        return '<span class="text-danger fw-semibold">vencida hace ' . e(-$days) . ' día' . ($days === -1 ? '' : 's') . ' (' . e($date) . ')</span>';
    }
    return '<span class="' . ($days <= 3 ? 'text-warning-emphasis fw-semibold' : 'text-body-secondary') . '">'
        . ($days === 0 ? 'vence hoy' : 'vence el ' . e($date)) . '</span>';
};
