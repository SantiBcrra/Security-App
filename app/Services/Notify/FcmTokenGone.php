<?php
declare(strict_types=1);

namespace App\Services\Notify;

/** El celular ya no tiene ese token (desinstaló la app o se renovó): se deja de usar. */
final class FcmTokenGone extends \RuntimeException
{
}
