<?php
declare(strict_types=1);

namespace App\Core;

/** Mensajes de una sola vez (se muestran en el próximo request y se borran). */
final class Flash
{
    private const KEY = '_flash';

    /** @param 'success'|'danger'|'warning'|'info' $type */
    public static function add(string $type, string $message): void
    {
        $_SESSION[self::KEY][] = ['type' => $type, 'message' => $message];
    }

    /** Valores del formulario anterior, para volver a mostrarlos tras un error. */
    public static function withInput(array $input, array $errors = []): void
    {
        $_SESSION['_old'] = $input;
        $_SESSION['_errors'] = $errors;
    }

    /** @return list<array{type:string, message:string}> */
    public static function pull(): array
    {
        $messages = $_SESSION[self::KEY] ?? [];
        unset($_SESSION[self::KEY]);
        return $messages;
    }

    /** @return array{0:array, 1:array} [old, errors] */
    public static function pullInput(): array
    {
        $old = $_SESSION['_old'] ?? [];
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_old'], $_SESSION['_errors']);
        return [$old, $errors];
    }
}
