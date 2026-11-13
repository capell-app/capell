<?php

declare(strict_types=1);

namespace Capell\Core\Data\Capabilities;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class CampaignConversionData extends Data
{
    public function __construct(
        public int $conversionId,
        public int $campaignId,
        public CarbonImmutable $convertedAt,
        public ?int $siteId = null,
        public ?string $goal = null,
    ) {}
}
