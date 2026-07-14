<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use InvalidArgumentException;

final class InvalidPublicationTransitionRequest extends InvalidArgumentException
{
    public static function requestedTimeRequired(): self
    {
        return new self('A requested time is required for scheduled publication transitions.');
    }

    public static function requestedTimeForbidden(): self
    {
        return new self('A requested time is not accepted for immediate publication transitions.');
    }
}
