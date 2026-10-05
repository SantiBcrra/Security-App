<?php
declare(strict_types=1);

namespace App\Resources;

use App\Core\Cuit;
use App\Models\Contractors;
use App\Models\Employees;

final class ContractorsResource extends Resource
{
    public function key(): string { return 'contratistas'; }
    public function model(): string { return Contractors::class; }
    public function singular(): string { return 'Contratista'; }
    public function plural(): string { return 'Contratistas'; }
    public function entity(): string { return 'contractor'; }

    public function fields(?array $current): array
    {
        return [
            ['name' => 'name', 'label' => 'Razón social', 'type' => 'text', 'required' => true, 'max' => 191, 'unique' => true, 'col' => 8],
            ['name' => 'cuit', 'label' => 'CUIT', 'type' => 'cuit', 'unique' => true, 'col' => 4, 'placeholder' => '30-12345678-9'],
            ['name' => 'contact_name', 'label' => 'Contacto', 'type' => 'text', 'max' => 120, 'col' => 4],
            ['name' => 'phone', 'label' => 'Teléfono', 'type' => 'tel', 'max' => 40, 'col' => 4],
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'max' => 191, 'col' => 4],
            ['name' => 'art_insurer', 'label' => 'ART', 'type' => 'text', 'max' => 120],
            ['name' => 'art_expires_on', 'label' => 'Vencimiento de la póliza ART', 'type' => 'date'],
        ];
    }

    public function columns(): array
    {
        return [
            ['label' => 'Contratista', 'value' => fn ($r) => e($r['name']) . ($r['cuit'] ? '<div class="small text-body-secondary">' . e(Cuit::format($r['cuit'])) . '</div>' : '')],
            ['label' => 'ART', 'value' => fn ($r) => e($r['art_insurer']) . ' ' . self::artBadge($r['art_expires_on'])],
            ['label' => 'Personal', 'value' => fn ($r) => e($r['workers'])],
        ];
    }

    public static function artBadge(?string $date): string
    {
        if (!$date) {
            return self::badge('sin vencimiento', 'secondary');
        }
        $days = (int) floor((strtotime($date . ' UTC') - strtotime(gmdate('Y-m-d') . ' UTC')) / 86400);
        $text = 'vence ' . date('d/m/Y', strtotime($date));
        return match (true) {
            $days < 0   => self::badge('vencida ' . date('d/m/Y', strtotime($date)), 'danger'),
            $days <= 30 => self::badge($text, 'warning'),
            default     => self::badge($text, 'success'),
        };
    }

    public function sideView(): ?string { return 'panel/master/side_contractor'; }

    public function sideData(array $row): array
    {
        return ['workers' => Employees::list(null, false, ['contractor_id' => (int) $row['id']])];
    }
}
