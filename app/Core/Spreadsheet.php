<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Lector de planillas sin librerías:
 *  - CSV: detecta separador (, ; tab), BOM y Windows-1252 (Excel en castellano). Mismo criterio que
 *    erp/core/XlsParser.php::parseCsv.
 *  - XLSX: es un ZIP con XML → ZipArchive + XMLReader (strings compartidos, texto inline, números).
 *    Las fechas llegan como número de serie de Excel; las convierte quien sabe que la columna es fecha.
 * Devuelve una lista de filas, cada fila una lista de celdas string (la primera suele ser el encabezado).
 */
final class Spreadsheet
{
    public const MAX_ROWS = 20000;

    /** @return list<list<string>> */
    public static function read(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $rows = match ($ext) {
            'csv', 'txt' => self::readCsv($path),
            'xlsx'       => self::readXlsx($path),
            'xls'        => throw new \DomainException('El formato .xls (Excel 97-2003) no se soporta: abrilo en Excel y guardalo como .xlsx o .csv.'),
            default      => throw new \DomainException('Formato no soportado: subí un archivo .xlsx o .csv.'),
        };
        // Quitar filas totalmente vacías y espacios sobrantes
        $clean = [];
        foreach ($rows as $row) {
            $row = array_map(fn ($c) => trim(preg_replace('/\s+/u', ' ', (string) $c)), $row);
            if (implode('', $row) !== '') {
                $clean[] = $row;
            }
        }
        if (count($clean) > self::MAX_ROWS + 1) {
            throw new \DomainException('El archivo tiene más de ' . self::MAX_ROWS . ' filas: dividilo en partes.');
        }
        return $clean;
    }

    /** @return list<list<string>> */
    public static function readCsv(string $path): array
    {
        $raw = (string) file_get_contents($path);
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $firstLine = strtok($raw, "\r\n") ?: '';
        $separator = ',';
        $best = substr_count($firstLine, ',');
        foreach ([';', "\t"] as $candidate) {
            if (($count = substr_count($firstLine, $candidate)) > $best) {
                $best = $count;
                $separator = $candidate;
            }
        }
        // str_getcsv sobre un stream respeta comillas con saltos de línea adentro
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $raw);
        rewind($stream);
        $rows = [];
        while (($cells = fgetcsv($stream, 0, $separator, '"', '\\')) !== false) {
            if ($cells === [null]) {
                continue;
            }
            $rows[] = array_map(fn ($c) => (string) $c, $cells);
        }
        fclose($stream);
        return $rows;
    }

    /** @return list<list<string>> */
    public static function readXlsx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \DomainException('No se pudo abrir el Excel (¿archivo dañado?).');
        }
        try {
            $sheetPath = self::firstSheetPath($zip);
            $shared = self::sharedStrings($zip);
            $xml = $zip->getFromName($sheetPath);
            if ($xml === false) {
                throw new \DomainException('El Excel no tiene hojas legibles.');
            }
        } finally {
            $zip->close();
        }

        $reader = new \XMLReader();
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $rows = [];
        $row = null;
        $col = 0;
        $type = '';
        $value = null;
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                switch ($reader->localName) {
                    case 'row':
                        $row = [];
                        $col = 0;
                        if ($reader->isEmptyElement) {
                            $rows[] = [];
                            $row = null;
                        }
                        break;
                    case 'c':
                        $ref = (string) $reader->getAttribute('r');
                        $col = $ref !== '' ? self::columnIndex($ref) : $col;
                        $type = (string) $reader->getAttribute('t');
                        $value = '';
                        if ($reader->isEmptyElement && $row !== null) {
                            $row[$col] = '';
                            $col++;
                        }
                        break;
                    case 'v':
                    case 't':
                        if ($row !== null) {
                            $value .= $reader->readString();
                        }
                        break;
                }
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT) {
                if ($reader->localName === 'c' && $row !== null) {
                    $row[$col] = match ($type) {
                        's'     => $shared[(int) $value] ?? '',
                        'b'     => $value === '1' ? 'VERDADERO' : 'FALSO',
                        default => self::number((string) $value),
                    };
                    $col++;
                } elseif ($reader->localName === 'row' && $row !== null) {
                    $rows[] = $row;
                    $row = null;
                    if (count($rows) > self::MAX_ROWS + 1) {
                        break;
                    }
                }
            }
        }
        $reader->close();

        // Completar huecos (celdas vacías no aparecen en el XML)
        $width = 0;
        foreach ($rows as $r) {
            $width = max($width, $r ? max(array_keys($r)) + 1 : 0);
        }
        return array_map(function (array $r) use ($width) {
            $full = array_fill(0, $width, '');
            foreach ($r as $i => $v) {
                $full[$i] = $v;
            }
            return $full;
        }, $rows);
    }

    private static function firstSheetPath(\ZipArchive $zip): string
    {
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $rels = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (preg_match('/<sheet\b[^>]*\br:id="([^"]+)"/', $workbook, $m)
            && preg_match_all('/<Relationship\b[^>]*>/', $rels, $all)) {
            foreach ($all[0] as $tag) {
                if (str_contains($tag, 'Id="' . $m[1] . '"') && preg_match('/Target="([^"]+)"/', $tag, $t)) {
                    $target = ltrim($t[1], '/');
                    return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                }
            }
        }
        return 'xl/worksheets/sheet1.xml';
    }

    /** @return list<string> */
    private static function sharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $reader = new \XMLReader();
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $strings = [];
        $current = null;
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'si') {
                $current = '';
            } elseif ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 't' && $current !== null) {
                $current .= $reader->readString();
            } elseif ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'rPh') {
                $reader->next(); // texto fonético (japonés): ignorar
            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'si') {
                $strings[] = $current;
                $current = null;
            }
        }
        $reader->close();
        return $strings;
    }

    /** "B12" → 1 */
    public static function columnIndex(string $ref): int
    {
        $letters = preg_replace('/\d+/', '', strtoupper($ref));
        $index = 0;
        foreach (str_split($letters) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }
        return $index - 1;
    }

    /** Números de Excel sin ".0" ni notación científica para enteros (DNI, legajos). */
    private static function number(string $value): string
    {
        if (is_numeric($value) && preg_match('/^-?\d+(\.0+)?$|E\+?\d+$/i', $value) && abs((float) $value) < 1e15 && floor((float) $value) == (float) $value) {
            return (string) (int) round((float) $value);
        }
        return $value;
    }
}
