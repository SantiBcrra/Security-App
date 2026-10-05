<?php
declare(strict_types=1);

namespace App\Services\Import;

use App\Core\Cuit;
use App\Models\Contractors;
use App\Resources\Resource;

final class ContractorsImporter extends EntityImporter
{
    public function key(): string { return 'contratistas'; }
    public function label(): string { return 'Contratistas'; }

    public function columns(): array
    {
        return [
            'razon_social' => ['label' => 'Razón social', 'required' => true, 'synonyms' => ['nombre', 'empresa', 'contratista', 'razon social']],
            'cuit'         => ['label' => 'CUIT', 'synonyms' => ['cuit cuil', 'nro cuit']],
            'contacto'     => ['label' => 'Contacto', 'synonyms' => ['responsable', 'referente']],
            'telefono'     => ['label' => 'Teléfono', 'synonyms' => ['tel', 'celular']],
            'email'        => ['label' => 'Email', 'synonyms' => ['mail', 'correo']],
            'art'          => ['label' => 'ART', 'synonyms' => ['aseguradora', 'art aseguradora']],
            'vto_art'      => ['label' => 'Vencimiento ART', 'synonyms' => ['vencimiento art', 'vto art', 'vencimiento poliza', 'vencimiento']],
        ];
    }

    public function rowKey(array $row): ?string
    {
        $cuit = Cuit::normalize((string) ($row['cuit'] ?? ''));
        $name = mb_strtolower(trim((string) ($row['razon_social'] ?? '')));
        return $cuit !== '' ? $cuit : ($name !== '' ? $name : null);
    }

    public function example(): array
    {
        return ['Montajes del Sur SRL', '30-71234567-1', 'Carlos Gómez', '11 4444-5555', 'contacto@montajes.com', 'Prevención ART', '31/12/2026'];
    }

    private function existing(array $row): ?array
    {
        $cuit = Cuit::normalize((string) ($row['cuit'] ?? ''));
        return ($cuit !== '' ? Contractors::findBy('cuit', $cuit) : null) ?? Contractors::findBy('name', trim((string) ($row['razon_social'] ?? '')));
    }

    public function validate(array $row, ImportContext $ctx): array
    {
        $errors = [];
        if (trim((string) ($row['razon_social'] ?? '')) === '') {
            $errors[] = 'Falta la razón social.';
        }
        if (($row['cuit'] ?? '') !== '' && !Cuit::isValid($row['cuit'])) {
            $errors[] = 'CUIT inválido.';
        }
        if (($row['vto_art'] ?? '') !== '' && Resource::parseDate($row['vto_art']) === null) {
            $errors[] = 'Vencimiento de ART inválido.';
        }
        if (($row['email'] ?? '') !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email inválido.';
        }
        return self::result($errors, $this->existing($row) ? 'update' : 'create');
    }

    public function apply(array $row, ImportContext $ctx): string
    {
        $data = self::nonEmpty([
            'name'           => trim($row['razon_social']),
            'cuit'           => Cuit::normalize((string) ($row['cuit'] ?? '')),
            'contact_name'   => trim((string) ($row['contacto'] ?? '')),
            'phone'          => trim((string) ($row['telefono'] ?? '')),
            'email'          => mb_strtolower(trim((string) ($row['email'] ?? ''))),
            'art_insurer'    => trim((string) ($row['art'] ?? '')),
            'art_expires_on' => ($row['vto_art'] ?? '') !== '' ? Resource::parseDate($row['vto_art']) : null,
        ]);
        $existing = $this->existing($row);
        if ($existing) {
            Contractors::update((int) $existing['id'], $data + ['is_active' => 1]);
            return 'updated';
        }
        Contractors::create($data);
        return 'created';
    }
}
