<?php
/** Helpers de badges para observaciones (se incluye una vez por vista). */
use App\Services\ObservationWorkflow;

$statusBadge = fn (string $s) => '<span class="badge text-bg-' . e(ObservationWorkflow::STATES[$s]['class'] ?? 'secondary') . '">' . e(ObservationWorkflow::label($s)) . '</span>';
$severityBadge = fn (?string $name, ?string $color) => $name ? '<span class="badge" style="background:' . e($color ?: '#6c757d') . '">' . e($name) . '</span>' : '';
$imminentBadge = '<span class="badge text-bg-danger">⚠ RIESGO INMINENTE</span>';
