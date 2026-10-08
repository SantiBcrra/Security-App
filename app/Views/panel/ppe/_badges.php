<?php
/** Helpers de EPP (se incluye una vez por vista). */
use App\Services\PpeService;

$ppeState = fn (string $s) => '<span class="badge text-bg-' . e(PpeService::STATES[$s]['class'] ?? 'secondary') . '">' . e(PpeService::STATES[$s]['label'] ?? $s) . '</span>';
$ppeDate = fn (?string $ymd) => $ymd ? date('d/m/Y', strtotime($ymd)) : '—';
$ppeLife = fn (?int $days) => $days === null ? 'sin vencimiento' : ($days % 365 === 0 ? ($days / 365) . ' año(s)' : ($days % 30 === 0 ? ($days / 30) . ' mes(es)' : $days . ' días'));
