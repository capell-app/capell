<?php

declare(strict_types=1);

namespace Capell\Core\Data\Messaging;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class OutboundMessageCatalogueEntryData extends Data
{
    public function __construct(
        public OutboundMessageSourceData $source,
        public CarbonImmutable $firstSeenAt,
        public ?CarbonImmutable $lastSentAt,
        public int $sendCount,
        public bool $discoveredStatically,
        public ?CarbonImmutable $reviewedAt,
    ) {}

    public function isNew(): bool
    {
        return ! $this->reviewedAt instanceof CarbonImmutable;
    }
}
