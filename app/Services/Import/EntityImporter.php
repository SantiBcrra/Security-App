<?php
declare(strict_types=1);

namespace App\Services\Import;

/**
 * Importador de una entidad (empleados, equipos...). Define las columnas que acepta (con sinónimos
 * para reconocer encabezados automáticamente), cómo validar una fila y cómo guardarla.
 */
abstract class EntityImporter
{
    abstract public function key(): string;

    abstract public function label(): string;

    /** @return array<string, array{label:string, required?:bool, synonyms?:list<string>, help?:string}> */
    abstract public function columns(): array;

    /** Valor que identifica la fila para detectar duplicados dentro del archivo (DNI, código...). */
    abstract public function rowKey(array $row): ?string;

    /** @return array{errors: list<string>, action: 'create'|'update'|'unchanged'} */
    abstract public function validate(array $row, ImportContext $ctx): array;

    /** @return 'created'|'updated'|'unchanged' */
    abstract public function apply(array $row, ImportContext $ctx): string;

    /** Fila de ejemplo para la plantilla CSV (en el orden de columns()). */
    abstract public function example(): array;

    /** Al menos uno de estos campos tiene que estar mapeado (además de los required). */
    public function requiredAnyOf(): array
    {
        return [];
    }

    protected static function result(array $errors, string $action): array
    {
        return ['errors' => $errors, 'action' => $errors ? 'error' : $action];
    }

    /** Solo los campos con valor (en una actualización no se borra lo que el archivo trae vacío). */
    protected static function nonEmpty(array $data): array
    {
        return array_filter($data, fn ($v) => $v !== null && $v !== '');
    }
}
