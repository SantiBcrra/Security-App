<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Flash;
use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Imports;
use App\Services\Import\Importer;

/** Importar datos maestros desde Excel/CSV: subir → mapear → vista previa → lotes por AJAX. */
final class ImportController
{
    public function index(Request $request): Response
    {
        return Response::html(View::render('panel/import/index', [
            'title'     => 'Importar Excel / CSV',
            'importers' => Importer::importers(),
            'recent'    => Imports::recent(),
            'selected'  => (string) $request->input('tipo', 'empleados'),
        ], 'layouts/app'));
    }

    public function upload(Request $request): Response
    {
        try {
            $import = Importer::upload((string) $request->input('entity', ''), $request->files['file'] ?? []);
        } catch (\DomainException $e) {
            Flash::add('danger', $e->getMessage());
            return Response::redirect('/panel/importar?tipo=' . rawurlencode((string) $request->input('entity', '')));
        } catch (\Throwable $e) {
            Logger::error('Importación: no se pudo leer el archivo', ['error' => $e->getMessage()]);
            Flash::add('danger', 'No se pudo leer el archivo. Revisá que sea un .xlsx o .csv válido.');
            return Response::redirect('/panel/importar');
        }
        return Response::redirect('/panel/importar/' . $import['uuid']);
    }

    public function show(Request $request, string $uuid): Response
    {
        $import = Imports::findByUuid($uuid);
        if ($import === null) {
            return Response::html(View::render('errors/404', [], 'layouts/app'), 404);
        }
        $importer = Importer::importer($import['entity']);
        $mapping = (array) json_decode((string) $import['mapping'], true);
        $rows = Importer::rows($import);
        $editable = in_array($import['status'], ['uploaded', 'mapped'], true);
        return Response::html(View::render('panel/import/show', [
            'title'         => 'Importar ' . mb_strtolower($importer->label()),
            'import'        => $import,
            'importer'      => $importer,
            'headers'       => $rows[0] ?? [],
            'mapping'       => $mapping,
            'mappingErrors' => Importer::mappingErrors($mapping, $importer),
            'preview'       => $editable && !Importer::mappingErrors($mapping, $importer) ? Importer::preview($import) : null,
            'editable'      => $editable,
        ], 'layouts/app'));
    }

    public function saveMapping(Request $request, string $uuid): Response
    {
        $import = Imports::findByUuid($uuid);
        if ($import === null || !in_array($import['status'], ['uploaded', 'mapped'], true)) {
            return Response::redirect('/panel/importar');
        }
        $importer = Importer::importer($import['entity']);
        $headers = Importer::rows($import)[0] ?? [];
        $input = (array) $request->input('map', []);
        $mapping = [];
        foreach ($importer->columns() as $field => $_) {
            $v = $input[$field] ?? '';
            $mapping[$field] = ($v !== '' && ctype_digit((string) $v) && (int) $v < count($headers)) ? (int) $v : null;
        }
        Imports::update((int) $import['id'], ['mapping' => json_encode($mapping), 'status' => 'mapped']);
        return Response::redirect('/panel/importar/' . $uuid . '#vista-previa');
    }

    /** AJAX: procesa el próximo lote y devuelve el progreso. */
    public function batch(Request $request, string $uuid): Response
    {
        $import = Imports::findByUuid($uuid);
        if ($import === null) {
            return Response::jsonError('Importación inexistente.', 404);
        }
        $importer = Importer::importer($import['entity']);
        if (Importer::mappingErrors((array) json_decode((string) $import['mapping'], true), $importer)) {
            return Response::jsonError('Revisá el mapeo de columnas.', 422);
        }
        if ($import['status'] === 'done') {
            return Response::json(['done' => true, 'processed' => (int) $import['processed_rows'], 'total' => (int) $import['total_rows']]);
        }
        try {
            return Response::json(Importer::processBatch($import));
        } catch (\Throwable $e) {
            Logger::error('Importación: falló un lote', ['import' => $uuid, 'error' => $e->getMessage()]);
            return Response::jsonError('Falló un lote: ' . $e->getMessage() . ' Podés reintentar: continúa desde donde quedó.', 500);
        }
    }

    public function errors(Request $request, string $uuid): Response
    {
        $import = Imports::findByUuid($uuid);
        if ($import === null) {
            return Response::html(View::render('errors/404', [], 'layouts/app'), 404);
        }
        return new Response(Importer::errorsCsv($import), 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="errores-importacion-' . $import['entity'] . '.csv"',
        ]);
    }

    public function template(Request $request, string $entity): Response
    {
        $importer = Importer::importer($entity);
        if ($importer === null) {
            return Response::html(View::render('errors/404', [], 'layouts/app'), 404);
        }
        return new Response(Importer::templateCsv($importer), 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="plantilla-' . $entity . '.csv"',
        ]);
    }
}
