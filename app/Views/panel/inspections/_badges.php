<?php
/** Helpers de badges para inspecciones (se incluye una vez por vista). */
use App\Services\InspectionService;

$resultBadge = fn (string $r) => '<span class="badge text-bg-' . e(InspectionService::RESULTS[$r]['class'] ?? 'secondary') . '">' . e(InspectionService::RESULTS[$r]['label'] ?? $r) . '</span>';
$scoreText = fn ($s) => $s === null ? '—' : e($s) . '%';
