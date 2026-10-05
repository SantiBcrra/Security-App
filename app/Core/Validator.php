<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Validación simple con mensajes en español.
 *   $errors = Validator::validate($data, ['email' => 'required|email', 'pass' => 'required|min:10']);
 * Devuelve [campo => primer error]; vacío si todo está bien.
 * Reglas: required, email, min:N, max:N, same:otroCampo, in:a,b,c
 */
final class Validator
{
    public static function validate(array $data, array $rules, array $labels = []): array
    {
        $errors = [];
        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;
            $value = is_string($value) ? trim($value) : $value;
            $label = $labels[$field] ?? $field;

            foreach (explode('|', $ruleString) as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $empty = $value === null || $value === '' || $value === [];

                if ($name !== 'required' && $empty) {
                    continue;
                }
                $error = match ($name) {
                    'required' => $empty ? "{$label}: es obligatorio." : null,
                    'email'    => filter_var($value, FILTER_VALIDATE_EMAIL) ? null : "{$label}: no es un email válido.",
                    'min'      => mb_strlen((string) $value) >= (int) $arg ? null : "{$label}: mínimo {$arg} caracteres.",
                    'max'      => mb_strlen((string) $value) <= (int) $arg ? null : "{$label}: máximo {$arg} caracteres.",
                    'same'     => $value === ($data[$arg] ?? null) ? null : "{$label}: no coincide.",
                    'in'       => in_array((string) $value, explode(',', (string) $arg), true) ? null : "{$label}: valor no permitido.",
                    default    => throw new \InvalidArgumentException("Regla desconocida: {$name}"),
                };
                if ($error !== null) {
                    $errors[$field] = $error;
                    break;
                }
            }
        }
        return $errors;
    }
}
