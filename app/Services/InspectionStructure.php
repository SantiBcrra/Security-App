<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Uuid;

/**
 * Estructura de un checklist: {"sections":[{"title":"…","items":[{key,text,help,type,ok_when,min,max,unit,critical,photo}]}]}
 * - type: si_no | si_no_na | numero | texto
 * - ok_when (si_no/si_no_na): "si" o "no" = qué respuesta cumple ("¿Pierde aceite?" cumple con "no")
 * - numero: cumple si está entre min y max (los que estén cargados); texto: informativo, no suma ni resta
 * - photo: nunca | siempre | si_no_cumple
 * Las respuestas se evalúan SIEMPRE acá, en el servidor (no se confía en lo que manda el cliente).
 */
final class InspectionStructure
{
    public const TYPES = ['si_no' => 'Sí / No', 'si_no_na' => 'Sí / No / No aplica', 'numero' => 'Número', 'texto' => 'Texto'];
    public const PHOTO = ['nunca' => 'No', 'siempre' => 'Siempre', 'si_no_cumple' => 'Si no cumple'];
    public const MAX_ITEMS = 120;

    /**
     * Valida y normaliza lo que manda el constructor (o una plantilla precargada).
     * @return array{0: array, 1: int, 2: list<string>} [estructura, cantidad de ítems, errores]
     */
    public static function normalize(mixed $raw): array
    {
        $errors = [];
        $sections = [];
        $count = 0;
        $seen = [];
        foreach ((array) ($raw['sections'] ?? []) as $si => $sec) {
            $title = trim((string) ($sec['title'] ?? ''));
            $items = [];
            foreach ((array) ($sec['items'] ?? []) as $item) {
                $text = trim((string) ($item['text'] ?? ''));
                if ($text === '') {
                    continue; // fila vacía del constructor
                }
                $type = isset(self::TYPES[$item['type'] ?? '']) ? $item['type'] : 'si_no';
                $key = strtolower((string) ($item['key'] ?? ''));
                if (!Uuid::isValid($key) || isset($seen[$key])) {
                    $key = Uuid::v4();
                }
                $seen[$key] = true;
                $num = fn ($v) => is_numeric($v) ? (float) $v : null;
                $min = $type === 'numero' ? $num($item['min'] ?? null) : null;
                $max = $type === 'numero' ? $num($item['max'] ?? null) : null;
                if ($min !== null && $max !== null && $min > $max) {
                    $errors[] = "«{$text}»: el mínimo es mayor que el máximo.";
                }
                $items[] = [
                    'key'      => $key,
                    'text'     => mb_substr($text, 0, 255),
                    'help'     => mb_substr(trim((string) ($item['help'] ?? '')), 0, 255) ?: null,
                    'type'     => $type,
                    'ok_when'  => in_array($type, ['si_no', 'si_no_na'], true) ? (($item['ok_when'] ?? 'si') === 'no' ? 'no' : 'si') : null,
                    'min'      => $min,
                    'max'      => $max,
                    'unit'     => $type === 'numero' ? (mb_substr(trim((string) ($item['unit'] ?? '')), 0, 20) ?: null) : null,
                    'critical' => $type !== 'texto' && !empty($item['critical']),
                    'photo'    => isset(self::PHOTO[$item['photo'] ?? '']) ? $item['photo'] : 'nunca',
                ];
            }
            if (!$items) {
                continue;
            }
            $sections[] = ['title' => mb_substr($title !== '' ? $title : 'Sección ' . ($si + 1), 0, 160), 'items' => $items];
            $count += count($items);
        }
        if ($count === 0) {
            $errors[] = 'El checklist necesita al menos un ítem.';
        }
        if ($count > self::MAX_ITEMS) {
            $errors[] = 'Máximo ' . self::MAX_ITEMS . ' ítems por checklist.';
        }
        return [['sections' => $sections], $count, $errors];
    }

    /** @return iterable<array{section:string, item:array, sort:int}> */
    public static function items(array $structure): iterable
    {
        $sort = 0;
        foreach ($structure['sections'] ?? [] as $sec) {
            foreach ($sec['items'] as $item) {
                yield ['section' => $sec['title'], 'item' => $item, 'sort' => ++$sort];
            }
        }
    }

    /**
     * Evalúa las respuestas. @param array $answers key => ['value' => …, 'comment' => …]
     * @param array $photoCounts key => cantidad de fotos que llegan para ese ítem
     * @return array{rows: list<array>, errors: array<string,string>, result: string, score: ?int, ok: int, fail: int, critical_fail: int}
     */
    public static function evaluate(array $structure, array $answers, array $photoCounts = []): array
    {
        $rows = [];
        $errors = [];
        $okCount = $fail = $criticalFail = 0;
        foreach (self::items($structure) as ['section' => $section, 'item' => $item, 'sort' => $sort]) {
            $key = $item['key'];
            $raw = $answers[$key]['value'] ?? null;
            $value = is_string($raw) ? trim($raw) : (is_numeric($raw) ? (string) $raw : null);
            $value = $value === '' ? null : $value;
            $comment = trim((string) ($answers[$key]['comment'] ?? '')) ?: null;
            $ok = null;
            switch ($item['type']) {
                case 'si_no':
                case 'si_no_na':
                    $allowed = $item['type'] === 'si_no_na' ? ['si', 'no', 'na'] : ['si', 'no'];
                    if (!in_array($value, $allowed, true)) {
                        $errors[$key] = 'Respondé: ' . $item['text'];
                        break;
                    }
                    $ok = $value === 'na' ? null : $value === $item['ok_when'];
                    break;
                case 'numero':
                    if ($value === null || !is_numeric(str_replace(',', '.', $value))) {
                        $errors[$key] = 'Cargá un número: ' . $item['text'];
                        break;
                    }
                    $n = (float) str_replace(',', '.', $value);
                    $value = rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
                    $ok = !(($item['min'] !== null && $n < $item['min']) || ($item['max'] !== null && $n > $item['max']));
                    break;
                default: // texto: informativo
                    $value = $value !== null ? mb_substr($value, 0, 255) : null;
            }
            if ($ok === false && $comment === null) {
                $errors[$key] = 'Contá qué pasa en «' . $item['text'] . '» (no cumple).';
            }
            $photos = (int) ($photoCounts[$key] ?? 0);
            if (($item['photo'] === 'siempre' || ($item['photo'] === 'si_no_cumple' && $ok === false)) && $photos === 0 && !isset($errors[$key])) {
                $errors[$key] = 'Falta la foto de «' . $item['text'] . '».';
            }
            if ($ok === true) {
                $okCount++;
            } elseif ($ok === false) {
                $fail++;
                if ($item['critical']) {
                    $criticalFail++;
                }
            }
            $rows[] = ['key' => $key, 'section' => $section, 'text' => $item['text'], 'value' => $value, 'ok' => $ok,
                'critical' => (bool) $item['critical'], 'comment' => $comment ? mb_substr($comment, 0, 2000) : null, 'sort' => $sort];
        }
        $answered = $okCount + $fail;
        return [
            'rows'          => $rows,
            'errors'        => $errors,
            'result'        => $criticalFail > 0 ? 'no_conforme_critico' : ($fail > 0 ? 'con_observaciones' : 'conforme'),
            'score'         => $answered > 0 ? (int) round(100 * $okCount / $answered) : null,
            'ok'            => $okCount,
            'fail'          => $fail,
            'critical_fail' => $criticalFail,
        ];
    }

    /** Etiqueta legible de una respuesta. */
    public static function valueLabel(?string $value, ?string $unit = null): string
    {
        return match ($value) {
            null    => '—',
            'si'    => 'Sí',
            'no'    => 'No',
            'na'    => 'No aplica',
            default => $value . ($unit ? ' ' . $unit : ''),
        };
    }
}
