<?php
declare(strict_types=1);

namespace App\Resources;

use App\Core\Cuit;
use App\Core\Request;

/**
 * Definición de una entidad de datos maestros (plantas, puestos, equipos...): campos del
 * formulario, columnas del listado, validación y conversión formulario ↔ base.
 * El CRUD lo hace App\Controllers\Web\Panel\MasterDataController para todas igual.
 *
 * Campo: ['name' => columna, 'label', 'type' => text|textarea|email|tel|number|decimal|date|select|ref|color|cuit,
 *         'required', 'max', 'min', 'options' (select: valor => etiqueta; ref: callable(?int id actual) => uuid => etiqueta),
 *         'model' (ref: clase Repository para resolver uuid ↔ id), 'unique', 'col' (ancho 1-12), 'help', 'placeholder']
 */
abstract class Resource
{
    /** Segmento de URL: /panel/datos/{key} */
    abstract public function key(): string;

    /** Clase del modelo (subclase de App\Models\Repository). */
    abstract public function model(): string;

    abstract public function singular(): string;

    abstract public function plural(): string;

    /** Tipo de entidad para la auditoría. */
    abstract public function entity(): string;

    /** @return list<array> */
    abstract public function fields(?array $current): array;

    /** Columnas del listado: list<array{label:string, value:callable(array):string (HTML ya escapado)}> */
    abstract public function columns(): array;

    public function newLabel(): string
    {
        return 'Nuevo ' . mb_strtolower($this->singular());
    }

    /** Título del registro (encabezado de la ficha). */
    public function title(array $row): string
    {
        return (string) ($row['name'] ?? $this->singular());
    }

    /** Filtros fijos o elegidos en el listado → condiciones para el modelo. */
    public function filters(Request $request): array
    {
        return [];
    }

    /** Controles de filtro del listado: name => [label, options]. */
    public function filterControls(): array
    {
        return [];
    }

    public function indexView(): string
    {
        return 'panel/master/index';
    }

    /** Vista lateral opcional en la ficha (QR del equipo, personal del contratista...). */
    public function sideView(): ?string
    {
        return null;
    }

    public function sideData(array $row): array
    {
        return [];
    }

    /** Valores fijos que se agregan al guardar (ej: catálogo). */
    public function fixedValues(): array
    {
        return [];
    }

    public function rows(Request $request, bool $includeInactive): array
    {
        $model = $this->model();
        return $model::list((string) $request->input('q', ''), $includeInactive, $this->filters($request) + $this->fixedValues());
    }

    // ── validación y conversión ───────────────────────────────────────

    public function validate(array $data, ?array $current): array
    {
        $errors = [];
        $model = $this->model();
        foreach ($this->fields($current) as $f) {
            $name = $f['name'];
            $value = $data[$name] ?? '';
            $value = is_string($value) ? trim($value) : $value;
            $label = $f['label'];
            if ($value === '' || $value === null) {
                if (!empty($f['required'])) {
                    $errors[$name] = "{$label}: es obligatorio.";
                }
                continue;
            }
            $error = match ($f['type'] ?? 'text') {
                'email'   => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : "{$label}: no es un email válido.",
                'number'  => preg_match('/^-?\d+$/', (string) $value) && (!isset($f['min']) || (int) $value >= $f['min'])
                             && (!isset($f['max']) || (int) $value <= $f['max']) ? null
                             : "{$label}: número inválido" . (isset($f['min'], $f['max']) ? " (entre {$f['min']} y {$f['max']})" : '') . '.',
                'decimal' => is_numeric(str_replace(',', '.', (string) $value)) ? null : "{$label}: número inválido.",
                'date'    => self::parseDate((string) $value) ? null : "{$label}: fecha inválida.",
                'select'  => array_key_exists((string) $value, $f['options']) ? null : "{$label}: valor no permitido.",
                'ref'     => array_key_exists((string) $value, ($f['options'])($current ? $this->currentRefId($f, $current) : null)) ? null : "{$label}: elegí una opción válida.",
                'color'   => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $value) ? null : "{$label}: color inválido.",
                'cuit'    => Cuit::isValid((string) $value) ? null : "{$label}: el CUIT no es válido (revisá el dígito verificador).",
                default   => null,
            };
            if ($error === null && isset($f['max']) && !in_array($f['type'] ?? 'text', ['number'], true) && mb_strlen((string) $value) > $f['max']) {
                $error = "{$label}: máximo {$f['max']} caracteres.";
            }
            if ($error === null && !empty($f['unique'])) {
                $stored = $this->convert($f, $value);
                if ($this->isDuplicate($name, $stored, $current)) {
                    $error = "{$label}: ya existe otro registro con ese valor.";
                }
            }
            if ($error !== null) {
                $errors[$name] = $error;
            }
        }
        return $this->extraValidate($data, $current, $errors);
    }

    protected function isDuplicate(string $column, mixed $value, ?array $current): bool
    {
        $model = $this->model();
        return $model::exists($column, $value, $current ? (int) $current['id'] : null);
    }

    protected function extraValidate(array $data, ?array $current, array $errors): array
    {
        return $errors;
    }

    /** Formulario → columnas de la base. */
    public function toRow(array $data, ?array $current): array
    {
        $row = [];
        foreach ($this->fields($current) as $f) {
            $value = $data[$f['name']] ?? '';
            $value = is_string($value) ? trim($value) : $value;
            $row[$f['name']] = ($value === '' || $value === null) ? null : $this->convert($f, $value);
        }
        return $row + $this->fixedValues();
    }

    /** Base → valores del formulario. */
    public function formValues(?array $row): array
    {
        $values = [];
        foreach ($this->fields($row) as $f) {
            $v = $row[$f['name']] ?? ($f['default'] ?? '');
            if (($f['type'] ?? '') === 'ref' && $row !== null) {
                $v = $this->currentRefUuid($f, $row);
            } elseif (($f['type'] ?? '') === 'cuit' && $v) {
                $v = Cuit::format((string) $v);
            }
            $values[$f['name']] = $v ?? '';
        }
        return $values;
    }

    /** Lo que se guarda en la auditoría (por defecto, las columnas del formulario). */
    public function auditable(array $row): array
    {
        $out = [];
        foreach ($this->fields($row) as $f) {
            $out[$f['name']] = $row[$f['name']] ?? null;
        }
        return $out;
    }

    /** Guarda; las subclases pueden sobrescribir (ej: sectores recalculan el árbol). @return int id */
    public function save(array $row, ?array $current): int
    {
        $model = $this->model();
        if ($current === null) {
            return $model::create($row);
        }
        $model::update((int) $current['id'], $row);
        return (int) $current['id'];
    }

    /** Validación antes de desactivar (ej: sector con hijos). Devuelve mensaje de error o null. */
    public function canDeactivate(array $row): ?string
    {
        return null;
    }

    protected function convert(array $f, mixed $value): mixed
    {
        return match ($f['type'] ?? 'text') {
            'number'  => (int) $value,
            'decimal' => str_replace(',', '.', (string) $value),
            'date'    => self::parseDate((string) $value),
            'cuit'    => Cuit::normalize((string) $value),
            'email'   => mb_strtolower((string) $value),
            'ref'     => ($f['model'])::findByUuid((string) $value)['id'] ?? null,
            default   => $value,
        };
    }

    private function currentRefId(array $f, array $current): ?int
    {
        return isset($current[$f['name']]) ? (int) $current[$f['name']] : null;
    }

    private function currentRefUuid(array $f, array $row): string
    {
        $id = $row[$f['name']] ?? null;
        return $id ? (string) (($f['model'])::findById((int) $id)['uuid'] ?? '') : '';
    }

    /** Acepta dd/mm/aaaa, aaaa-mm-dd y número de serie de Excel. Devuelve Y-m-d o null. */
    public static function parseDate(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^\d{4,5}(\.\d+)?$/', $value) && (float) $value > 20000 && (float) $value < 80000) {
            return gmdate('Y-m-d', ((int) $value - 25569) * 86400); // serie de Excel
        }
        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y', '!d/m/y', '!Y/m/d'] as $format) {
            $d = \DateTimeImmutable::createFromFormat($format, $value);
            if ($d && $d->format(ltrim($format, '!')) === $value) {
                return $d->format('Y-m-d');
            }
        }
        // d/m/Y sin ceros a la izquierda (1/2/2024)
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$#', $value, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        return null;
    }

    /** Helper para campos que referencian otro modelo de datos maestros. */
    protected static function ref(string $name, string $label, string $model, bool $required = false, array $extra = []): array
    {
        return [
            'name' => $name, 'label' => $label, 'type' => 'ref', 'model' => $model, 'required' => $required,
            'options' => fn (?int $currentId) => $model::options($currentId),
        ] + $extra;
    }

    protected static function badge(string $text, string $class): string
    {
        return '<span class="badge text-bg-' . e($class) . '">' . e($text) . '</span>';
    }
}
