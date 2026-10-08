<?php
declare(strict_types=1);

namespace App\Core;

/** Error de validación cuyo mensaje se puede mostrar tal cual al usuario (web o app de campo). */
final class UserError extends \InvalidArgumentException
{
}
