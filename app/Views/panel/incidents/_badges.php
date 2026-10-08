<?php
/** Helpers de badges para incidentes (se incluye una vez por vista). */
use App\Services\IncidentService;

$typeBadge = fn (string $t) => '<span class="badge text-bg-' . e(IncidentService::TYPES[$t]['class'] ?? 'secondary') . '">' . e(IncidentService::typeLabel($t)) . '</span>';
$stateBadge = fn (string $s) => '<span class="badge text-bg-' . e(IncidentService::STATES[$s]['class'] ?? 'secondary') . '">' . e(IncidentService::STATES[$s]['label'] ?? $s) . '</span>';
$personName = fn (array $p) => $p['employee_id'] ? $p['last_name'] . ', ' . $p['first_name'] : (string) $p['external_name'];
