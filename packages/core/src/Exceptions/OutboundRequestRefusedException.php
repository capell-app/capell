<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;

/**
 * A guarded outbound request was stopped before or while it ran: the target was not
 * a public address, used a scheme or port the policy does not allow, or the
 * response exceeded the download limit.
 *
 * The message names the host only, never the full URL, so query-string secrets do
 * not reach logs.
 */
final class OutboundRequestRefusedException extends RuntimeException
{
    public static function forHost(string $host, string $reason): self
    {
        return new self(sprintf('Outbound request to [%s] refused: %s', $host, $reason));
    }
}
