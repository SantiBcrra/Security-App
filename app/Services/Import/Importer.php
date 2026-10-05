<?php
declare(strict_types=1);

namespace App\Services\Import;

use App\Core\DB;
use App\Core\Spreadsheet;
use App\Core\Tenant;
use App\Core\TenantFiles;
use App\Models\Imports;
use App\Services\Audit;
use App\Services\UserAuth;

/**
 * Importación CSV/Excel en pasos: subir → mapear columnas → vista previa validada → procesar por
 * lotes cortos (cada lote es un request; nunca se pasa el max_execution_time del hosting).
 * Las filas leídas se guardan en un JSON junto al archivo para no re-leer el Excel en cada lote.
 */
final class Importer
{
    public const BATCH_SIZE = 100;
    public const MAX_BYTES = 5 * 1024 * 1024;
    private const FILE_TYPES = [
        'text/plain' => 'csv', 'text/csv' => 'csv', 'application/csv' => 'csv', 'text/x-csv' => 'csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx', 'application/zip' => 'xlsx',
        'application/octet-stream' => 'xlsx',
    ];

    /** @return array<string, EntityImporter> */
    public static function importers(): array
    {
        $list = [new EmployeesImporter(), new EquipmentImporter(), new SectorsImporter(), new PositionsImporter(), new ContractorsImporter()];
        return array_combine(array_map(fn ($i) => $i->key(), $list), $list);
    }

    public static function importer(string $key): ?EntityImporter
    {
        return self::importers()[$key] ?? null;
    }

    /** Recibe el archivo subido, lo lee y crea la importación con un mapeo sugerido. */
    public static function upload(string $entity, array $file): array
    {
        $importer = self::importer($entity) ?? throw new \DomainException('Elegí qué querés importar.');
        $name = (string) ($file['name'] ?? 'archivo');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt', 'xlsx', 'xls'], true)) {
            throw new \DomainException('Formato no soportado: subí un archivo .xlsx o .csv.');
        }
        if ($ext === 'xls') {
            throw new \DomainException('El formato .xls (Excel 97-2003) no se soporta: abrilo en Excel y guardalo como .xlsx o .csv.');
        }
        $relative = TenantFiles::storeUpload(Tenant::current()['uuid'], $file, 'imports', self::FILE_TYPES, self::MAX_BYTES);
        return self::createFromStored($entity, $relative, $name);
    }

    /** Crea la importación a partir de un archivo ya guardado en la carpeta de la empresa. */
    public static function createFromStored(string $entity, string $relative, string $name): array
    {
        $importer = self::importer($entity) ?? throw new \DomainException('Elegí qué querés importar.');
        $path = TenantFiles::path(Tenant::current()['uuid'], $relative);
        $rows = Spreadsheet::read($path, $name);
        if (count($rows) < 2) {
            throw new \DomainException('El archivo no tiene datos (se necesita una fila de encabezados y al menos una fila).');
        }
        file_put_contents($path . '.json', json_encode($rows, JSON_UNESCAPED_UNICODE));

        return Imports::create([
            'entity'        => $entity,
            'original_name' => mb_substr($name, 0, 191),
            'file_path'     => $relative,
            'mapping'       => json_encode(self::autoMap($rows[0], $importer)),
            'total_rows'    => count($rows) - 1,
            'status'        => 'mapped',
            'user_id'       => UserAuth::user()['id'] ?? null,
        ]);
    }

    /** Filas guardadas (la primera es el encabezado). @return list<list<string>> */
    public static function rows(array $import): array
    {
        $path = TenantFiles::path(Tenant::current()['uuid'], $import['file_path']);
        $json = $path ? @file_get_contents($path . '.json') : false;
        return $json ? (array) json_decode($json, true) : [];
    }

    /** Sugiere qué columna del archivo corresponde a cada campo, por nombre y sinónimos. */
    public static function autoMap(array $headers, EntityImporter $importer): array
    {
        $normalized = array_map([self::class, 'normalize'], $headers);
        $mapping = [];
        $used = [];
        foreach ($importer->columns() as $field => $def) {
            $candidates = array_map([self::class, 'normalize'], array_merge([$field, $def['label']], $def['synonyms'] ?? []));
            $mapping[$field] = null;
            foreach ($normalized as $i => $header) {
                if (!isset($used[$i]) && in_array($header, $candidates, true)) {
                    $mapping[$field] = $i;
                    $used[$i] = true;
                    break;
                }
            }
        }
        return $mapping;
    }

    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', '°' => '', 'º' => '']);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $text));
    }

    /** Valida el mapeo elegido: campos obligatorios mapeados y sin columnas repetidas. @return list<string> */
    public static function mappingErrors(array $mapping, EntityImporter $importer): array
    {
        $errors = [];
        foreach ($importer->columns() as $field => $def) {
            if (!empty($def['required']) && ($mapping[$field] ?? null) === null) {
                $errors[] = "Falta indicar la columna de \"{$def['label']}\".";
            }
        }
        foreach ($importer->requiredAnyOf() as $group) {
            if (!array_filter($group, fn ($f) => ($mapping[$f] ?? null) !== null)) {
                $labels = array_map(fn ($f) => '"' . $importer->columns()[$f]['label'] . '"', $group);
                $errors[] = 'Indicá la columna de ' . implode(' o ', $labels) . '.';
            }
        }
        $used = array_filter($mapping, fn ($v) => $v !== null);
        if (count($used) !== count(array_unique($used))) {
            $errors[] = 'Una misma columna del archivo está asignada a dos campos.';
        }
        return $errors;
    }

    /** Fila del archivo → [campo => valor] según el mapeo. */
    public static function mapRow(array $cells, array $mapping): array
    {
        $out = [];
        foreach ($mapping as $field => $index) {
            $out[$field] = $index === null ? '' : trim((string) ($cells[(int) $index] ?? ''));
        }
        return $out;
    }

    /**
     * Valida TODAS las filas sin escribir nada.
     * @return array{counts: array<string,int>, rows: list<array{line:int, values:array, action:string, errors:list<string>}>}
     */
    public static function preview(array $import): array
    {
        $importer = self::importer($import['entity']);
        $mapping = (array) json_decode((string) $import['mapping'], true);
        $rows = self::rows($import);
        $ctx = new ImportContext();
        $dupes = self::duplicates($rows, $mapping, $importer);
        $counts = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'error' => 0];
        $result = [];
        foreach (array_slice($rows, 1) as $i => $cells) {
            $line = $i + 2; // número de fila en Excel (1 = encabezado)
            $values = self::mapRow($cells, $mapping);
            $check = $importer->validate($values, $ctx);
            if (isset($dupes[$line])) {
                $check['errors'][] = 'Repetido en el archivo (también en la fila ' . $dupes[$line] . ').';
                $check['action'] = 'error';
            }
            $counts[$check['action']]++;
            $result[] = ['line' => $line, 'values' => $values, 'action' => $check['action'], 'errors' => $check['errors']];
        }
        return ['counts' => $counts, 'rows' => $result];
    }

    /** Procesa el siguiente lote. @return array progreso */
    public static function processBatch(array $import): array
    {
        $importer = self::importer($import['entity']);
        $mapping = (array) json_decode((string) $import['mapping'], true);
        $rows = self::rows($import);
        $dupes = self::duplicates($rows, $mapping, $importer);
        $errors = (array) json_decode((string) ($import['errors'] ?? '[]'), true);
        $start = (int) $import['processed_rows'];
        $batch = array_slice($rows, 1 + $start, self::BATCH_SIZE);
        $counts = ['created' => 0, 'updated' => 0, 'error' => 0];
        $ctx = new ImportContext();

        $db = DB::tenant();
        $db->beginTransaction();
        try {
            foreach ($batch as $offset => $cells) {
                $line = $start + $offset + 2;
                $values = self::mapRow($cells, $mapping);
                $check = $importer->validate($values, $ctx);
                if (isset($dupes[$line])) {
                    $check['errors'][] = 'Repetido en el archivo (también en la fila ' . $dupes[$line] . ').';
                }
                if ($check['errors']) {
                    $errors[] = ['line' => $line, 'errors' => $check['errors'], 'values' => $values];
                    $counts['error']++;
                    continue;
                }
                $outcome = $importer->apply($values, $ctx);
                if ($outcome !== 'unchanged') {
                    $counts[$outcome]++;
                }
            }
            $processed = $start + count($batch);
            $done = $processed >= (int) $import['total_rows'];
            Imports::update((int) $import['id'], [
                'processed_rows' => $processed,
                'created_count'  => (int) $import['created_count'] + $counts['created'],
                'updated_count'  => (int) $import['updated_count'] + $counts['updated'],
                'error_count'    => (int) $import['error_count'] + $counts['error'],
                'errors'         => json_encode($errors, JSON_UNESCAPED_UNICODE),
                'status'         => $done ? 'done' : 'processing',
            ]);
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $fresh = Imports::findByUuid($import['uuid']);
        if ($done) {
            Audit::tenant('import.done', 'import', $import['uuid'], null, [
                'entidad' => $import['entity'], 'archivo' => $import['original_name'], 'filas' => (int) $fresh['total_rows'],
                'creados' => (int) $fresh['created_count'], 'actualizados' => (int) $fresh['updated_count'], 'errores' => (int) $fresh['error_count'],
            ]);
        }
        return [
            'processed' => (int) $fresh['processed_rows'], 'total' => (int) $fresh['total_rows'],
            'created' => (int) $fresh['created_count'], 'updated' => (int) $fresh['updated_count'],
            'errors' => (int) $fresh['error_count'], 'done' => $fresh['status'] === 'done',
        ];
    }

    /** Filas cuya clave (DNI, código...) aparece repetida más arriba: línea => línea original. */
    private static function duplicates(array $rows, array $mapping, EntityImporter $importer): array
    {
        $seen = [];
        $dupes = [];
        foreach (array_slice($rows, 1) as $i => $cells) {
            $key = $importer->rowKey(self::mapRow($cells, $mapping));
            if ($key === null) {
                continue;
            }
            if (isset($seen[$key])) {
                $dupes[$i + 2] = $seen[$key];
            } else {
                $seen[$key] = $i + 2;
            }
        }
        return $dupes;
    }

    /** CSV de ejemplo para una entidad (separador ; y BOM, así Excel en castellano lo abre bien). */
    public static function templateCsv(EntityImporter $importer): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(fn ($d) => $d['label'], $importer->columns()), ';');
        fputcsv($out, $importer->example(), ';');
        rewind($out);
        return (string) stream_get_contents($out);
    }

    public static function errorsCsv(array $import): string
    {
        $importer = self::importer($import['entity']);
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_merge(['Fila', 'Errores'], array_map(fn ($d) => $d['label'], $importer->columns())), ';');
        foreach ((array) json_decode((string) $import['errors'], true) as $err) {
            fputcsv($out, array_merge([$err['line'], implode(' ', $err['errors'])], array_values($err['values'])), ';');
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }
}
