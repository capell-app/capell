<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;

/**
 * The caller's claim expired and another caller took the key over, so this caller may no longer complete or
 * release it. Its work must be treated as superseded.
 */
final class StaleIdempotencyClaim extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf('The claim on idempotency key [%s] was taken over by another caller.', $key));
    }
}
