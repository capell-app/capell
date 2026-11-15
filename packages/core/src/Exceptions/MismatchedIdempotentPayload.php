<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;

/**
 * An idempotency key was reused with a different payload. The key identifies one operation, so a different request
 * under the same key is refused rather than treated as a replay.
 */
final class MismatchedIdempotentPayload extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self(sprintf('The idempotency key [%s] was already used for a different payload.', $key));
    }
}
