<?php $statusClass = ['trial' => 'info', 'active' => 'success', 'suspended' => 'danger'][$status] ?? 'secondary'; ?>
<span class="badge text-bg-<?= e($statusClass) ?>"><?= e(App\Core\Tenant::STATUSES[$status] ?? $status) ?></span>
