<?php

declare(strict_types=1);

namespace Capell\Core\Events;

use Capell\Core\Data\OutboundEventDefinitionData;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final readonly class OutboundEventPublished
{
    public function __construct(
        public OutboundEventDefinitionData $definition,
        public Data $payload,
        public string $eventId,
        public CarbonImmutable $occurredAt,
    ) {}

    /** @return array<string, mixed> */
    public function payloadData(): array
    {
        return $this->payload->toArray();
    }
}
