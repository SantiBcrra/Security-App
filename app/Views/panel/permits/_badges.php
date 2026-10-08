<?php
/** Helpers de permisos de trabajo (se incluye una vez por vista). */
use App\Services\WorkPermitService;

$stateBadge = fn (string $s) => '<span class="badge text-bg-' . e(WorkPermitService::STATES[$s]['class'] ?? 'secondary') . '">' . e(WorkPermitService::STATES[$s]['label'] ?? $s) . '</span>';
$typeBadges = fn (array $types) => implode(' ', array_map(fn ($t) => '<span class="badge text-bg-' . e(WorkPermitService::TYPES[$t]['class'] ?? 'secondary') . '">'
    . e(WorkPermitService::TYPES[$t]['short'] ?? $t) . '</span>', $types));
$workerName = fn (array $w) => $w['employee_id'] ? $w['last_name'] . ', ' . $w['first_name'] : (string) $w['external_name'];
/** Minutos que faltan para el fin (negativo = venció). */
$minutesLeft = fn (array $p) => (int) floor((strtotime($p['ends_at']) - time()) / 60);
