<?php
declare(strict_types=1);

namespace App\Core;

final class JwtException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'invalid')
    {
        parent::__construct($message);
    }
}
