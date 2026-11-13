<?php

declare(strict_types=1);

namespace Capell\Core\Data\Webhooks;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A webhook event whose signature a provider has verified, reduced to what the
 * receiver needs: the provider's own event id (the deduplication key), its type,
 * when the provider says it happened, the subject it is about (for ordering) and
 * the decoded payload.
 */
final readonly class InboundWebhookData
{
    public const int MAX_IDENTIFIER_LENGTH = 191;

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public function __construct(
        public string $eventId,
        public string $type,
        public CarbonImmutable $occurredAt,
        public array $payload,
        public ?string $subject = null,
    ) {
        foreach (['event id' => $eventId, 'type' => $type, 'subject' => $subject ?? 'x'] as $label => $value) {
            throw_if(trim($value) === '' || mb_strlen($value) > self::MAX_IDENTIFIER_LENGTH, InvalidArgumentException::class, sprintf('A webhook %s must be between 1 and %d characters.', $label, self::MAX_IDENTIFIER_LENGTH));
        }
    }
}
