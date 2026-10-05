<?php
declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Equipment;
use App\Models\Sectors;

final class EquipmentImporter extends EntityImporter
{
    private const STATUS_WORDS = ['operativo' => 'operativo', 'en servicio' => 'operativo', 'ok' => 'operativo',
        'fuera de servicio' => 'fuera_servicio', 'fuera servicio' => 'fuera_servicio', 'roto' => 'fuera_servicio',
        'baja' => 'baja', 'dado de baja' => 'baja'];

    public function key(): string { return 'equipos'; }
    public function label(): string { return 'Equipos'; }

    public function columns(): array
    {
        return [
            'codigo' => ['label' => 'Código interno', 'required' => true, 'synonyms' => ['codigo', 'cod', 'codigo interno', 'interno', 'id equipo', 'identificacion']],
            'nombre' => ['label' => 'Nombre', 'required' => true, 'synonyms' => ['equipo', 'descripcion', 'denominacion']],
            'tipo'   => ['label' => 'Tipo', 'synonyms' => ['tipo de equipo', 'clase']],
            'planta' => ['label' => 'Planta', 'synonyms' => ['sitio', 'establecimiento']],
            'sector' => ['label' => 'Sector', 'synonyms' => ['area', 'ubicacion']],
            'marca'  => ['label' => 'Marca', 'synonyms' => ['fabricante']],
            'modelo' => ['label' => 'Modelo', 'synonyms' => []],
            'serie'  => ['label' => 'N° de serie', 'synonyms' => ['numero de serie', 'nro serie', 'n serie', 'serial']],
            'anio'   => ['label' => 'Año', 'synonyms' => ['año', 'ano', 'año fabricacion', 'fabricacion']],
            'estado' => ['label' => 'Estado', 'synonyms' => ['situacion'], 'help' => 'Operativo, Fuera de servicio o Baja.'],
        ];
    }

    public function rowKey(array $row): ?string
    {
        $code = mb_strtoupper(trim((string) ($row['codigo'] ?? '')));
        return $code !== '' ? $code : null;
    }

    public function example(): array
    {
        return ['AE-01', 'Autoelevador Toyota 2,5 t', 'Autoelevador', 'Planta 1', 'Nave A > Depósito', 'Toyota', '8FGU25', 'TY123456', '2019', 'Operativo'];
    }

    private function status(string $text): ?string
    {
        $norm = mb_strtolower(trim($text));
        return $norm === '' ? 'operativo' : (self::STATUS_WORDS[$norm] ?? (isset(Equipment::STATUSES[$norm]) ? $norm : null));
    }

    /**
     * Sector del equipo: por nombre o camino; si es ambiguo y hay planta, se busca dentro de esa planta.
     * @return array{0:?int, 1:?string}
     */
    private function sector(array $row, ImportContext $ctx): array
    {
        [$id, $err] = $ctx->sector($row['sector']);
        $planta = trim((string) ($row['planta'] ?? ''));
        if ($planta !== '' && ($id === null || ($ctx->siteId($planta) !== null && $ctx->sectorMap()[$id]['site_id'] !== $ctx->siteId($planta)))) {
            [$id, $err] = $ctx->sector($planta . ' > ' . $row['sector']);
        }
        return [$id, $err];
    }

    public function validate(array $row, ImportContext $ctx): array
    {
        $errors = [];
        if ($this->rowKey($row) === null) {
            $errors[] = 'Falta el código.';
        }
        if (trim((string) ($row['nombre'] ?? '')) === '') {
            $errors[] = 'Falta el nombre.';
        }
        if (($row['planta'] ?? '') !== '' && $ctx->siteId($row['planta']) === null) {
            $errors[] = "No existe la planta \"{$row['planta']}\".";
        }
        if (($row['sector'] ?? '') !== '') {
            [, $err] = $this->sector($row, $ctx);
            if ($err) {
                $errors[] = $err;
            }
        }
        if (($row['anio'] ?? '') !== '' && (!ctype_digit($row['anio']) || (int) $row['anio'] < 1900 || (int) $row['anio'] > 2100)) {
            $errors[] = 'Año inválido.';
        }
        if ($this->status((string) ($row['estado'] ?? '')) === null) {
            $errors[] = 'Estado desconocido (usar Operativo, Fuera de servicio o Baja).';
        }
        return self::result($errors, Equipment::findBy('code', $this->rowKey($row) ?? '') ? 'update' : 'create');
    }

    public function apply(array $row, ImportContext $ctx): string
    {
        $sectorId = ($row['sector'] ?? '') !== '' ? $this->sector($row, $ctx)[0] : null;
        $siteId = ($row['planta'] ?? '') !== '' ? $ctx->siteId($row['planta'])
            : ($sectorId ? (int) Sectors::findById($sectorId)['site_id'] : null);
        $data = self::nonEmpty([
            'code'          => $this->rowKey($row),
            'name'          => trim((string) $row['nombre']),
            'type_id'       => ($row['tipo'] ?? '') !== '' ? $ctx->catalogId('tipo_equipo', $row['tipo'], true) : null,
            'site_id'       => $siteId,
            'sector_id'     => $sectorId,
            'brand'         => trim((string) ($row['marca'] ?? '')),
            'model'         => trim((string) ($row['modelo'] ?? '')),
            'serial_number' => trim((string) ($row['serie'] ?? '')),
            'year'          => ($row['anio'] ?? '') !== '' ? (int) $row['anio'] : null,
            'status'        => ($row['estado'] ?? '') !== '' ? $this->status($row['estado']) : null,
        ]);
        $existing = Equipment::findBy('code', $data['code']);
        if ($existing) {
            Equipment::update((int) $existing['id'], $data + ['is_active' => 1]);
            return 'updated';
        }
        Equipment::create($data);
        return 'created';
    }
}
