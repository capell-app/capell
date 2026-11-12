<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A guarded outbound request was stopped before or while it ran: the target was not
 * a public address, used a scheme or port the policy does not allow, carried
 * credentials in the URL, or the response exceeded the download limit.
 *
 * The message names the host only, never the full URL, so query-string secrets do
 * not reach logs. `reason` is one of the REASON_* constants so callers can explain
 * the refusal without parsing the message.
 */
final class OutboundRequestRefusedException extends RuntimeException
{
    public const string REASON_SCHEME = 'scheme';

    public const string REASON_PORT = 'port';

    public const string REASON_UNRESOLVED = 'unresolved';

    public const string REASON_ADDRESS = 'address';

    public const string REASON_SIZE = 'size';

    public const string REASON_CREDENTIALS = 'credentials';

    private function __construct(string $message, public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function forHost(string $host, string $reason, string $explanation, ?Throwable $previous = null): self
    {
        return new self(sprintf('Outbound request to [%s] refused: %s', $host, $explanation), $reason, $previous);
    }
}
