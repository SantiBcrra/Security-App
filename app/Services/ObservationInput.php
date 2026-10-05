<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Tenant;
use App\Core\Uuid;
use App\Models\CatalogItems;
use App\Models\Employees;
use App\Models\Equipment;
use App\Models\Sectors;
use App\Models\Settings;

/** Validación del alta de una observación, común a la web y a la API de la app. */
final class ObservationInput
{
    /**
     * @param array $in campos del formulario o de la API (catálogos, sector, equipo y personas por UUID).
     *        Hora del hecho: 'occurred_at' (Y-m-d\TH:i en la zona de la empresa, formulario web) o
     *        'created_at_device' (ISO 8601 con zona, app). 'uuid' opcional: lo genera el celular.
     * @return array{0: array, 1: list<int>, 2: array} [datos, ids de involucrados, errores]
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $cat = fn (string $catalog, string $key) => ($item = CatalogItems::findByUuid((string) ($in[$key] ?? ''))) && $item['catalog'] === $catalog ? $item : null;
        $category = $cat('categoria', 'category');
        $risk = $cat('tipo_riesgo', 'risk_type');
        $severity = $cat('severidad', 'severity');
        $sector = Sectors::findByUuid((string) ($in['sector'] ?? ''));
        $equipment = ($in['equipment'] ?? '') !== '' ? Equipment::findByUuid((string) $in['equipment']) : null;
        if ($category === null) {
            $errors['category'] = 'Elegí si es un acto, una condición o una buena práctica.';
        }
        if ($severity === null) {
            $errors['severity'] = 'Elegí la severidad.';
        }
        if ($sector === null && $equipment && $equipment['sector_id']) {
            $sector = Sectors::findById((int) $equipment['sector_id']);
        }
        if ($sector === null) {
            $errors['sector'] = 'Elegí el sector donde ocurrió.';
        }
        if (($in['equipment'] ?? '') !== '' && $equipment === null) {
            $errors['equipment'] = 'Equipo inválido.';
        }
        $description = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($description) < 10) {
            $errors['description'] = 'Contá qué viste (mínimo 10 caracteres).';
        } elseif (mb_strlen($description) > 5000) {
            $errors['description'] = 'La descripción es demasiado larga (máx. 5000).';
        }
        // Fecha y hora del hecho → UTC. No futura ni de hace más de un año.
        $occurred = self::occurredAt($in);
        if (!$occurred) {
            $errors['occurred_at'] = 'Fecha y hora inválidas.';
        } elseif ($occurred->getTimestamp() > time() + 300) {
            $errors['occurred_at'] = 'La fecha no puede ser futura.';
        } elseif ($occurred->getTimestamp() < time() - 366 * 86400) {
            $errors['occurred_at'] = 'La fecha es de hace más de un año.';
        }
        $lat = trim((string) ($in['lat'] ?? ''));
        $lng = trim((string) ($in['lng'] ?? ''));
        if (($lat !== '' || $lng !== '') && (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180)) {
            $errors['lat'] = 'Ubicación GPS inválida.';
        }
        $people = [];
        foreach ((array) ($in['people'] ?? []) as $uuid) {
            if (($e = Employees::findByUuid((string) $uuid)) !== null) {
                $people[] = (int) $e['id'];
            }
        }
        $anonymous = !empty($in['anonymous']) && Settings::bool('observaciones.anonimo_habilitado');
        $uuid = isset($in['uuid']) ? (string) $in['uuid'] : null;
        if ($uuid !== null && !Uuid::isValid($uuid)) {
            $errors['uuid'] = 'Identificador inválido.';
        }
        $data = [
            'category_id'       => $category['id'] ?? null,
            'risk_type_id'      => $risk['id'] ?? null,
            'severity_id'       => $severity['id'] ?? null,
            'site_id'           => $sector['site_id'] ?? null,
            'sector_id'         => $sector['id'] ?? null,
            'equipment_id'      => $equipment['id'] ?? null,
            'description'       => $description,
            'location_text'     => mb_substr(trim((string) ($in['location_text'] ?? '')), 0, 191) ?: null,
            'lat'               => $lat !== '' ? $lat : null,
            'lng'               => $lng !== '' ? $lng : null,
            'gps_accuracy_m'    => ctype_digit((string) ($in['gps_accuracy'] ?? '')) ? (int) $in['gps_accuracy'] : null,
            'imminent_risk'     => !empty($in['imminent']) ? 1 : 0,
            'is_anonymous'      => $anonymous ? 1 : 0,
            'created_at_device' => $occurred ? $occurred->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null,
        ];
        if ($uuid !== null) {
            $data['uuid'] = strtolower($uuid);
        }
        return [$data, $people, $errors];
    }

    private static function occurredAt(array $in): ?\DateTimeImmutable
    {
        if (!empty($in['created_at_device'])) {
            try {
                return new \DateTimeImmutable((string) $in['created_at_device']);
            } catch (\Exception) {
                return null;
            }
        }
        return \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', (string) ($in['occurred_at'] ?? ''), new \DateTimeZone(Tenant::timezone() ?? 'UTC')) ?: null;
    }

}
