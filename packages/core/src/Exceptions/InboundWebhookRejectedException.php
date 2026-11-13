<?php

declare(strict_types=1);

namespace Capell\Core\Exceptions;

use RuntimeException;

/**
 * A webhook delivery the receiver will not trust. The message is for logs only;
 * the sender receives a bare 400 so a forger learns nothing about which check
 * failed.
 */
final class InboundWebhookRejectedException extends RuntimeException
{
    public const string REASON_SIGNATURE = 'signature';

    public const string REASON_TIMESTAMP = 'timestamp';

    public const string REASON_MALFORMED = 'malformed';

    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function because(string $reason, string $explanation): self
    {
        return new self('Inbound webhook rejected: ' . $explanation, $reason);
    }
}
