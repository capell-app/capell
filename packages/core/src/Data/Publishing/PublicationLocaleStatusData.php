<?php

declare(strict_types=1);

namespace Capell\Core\Data\Publishing;

use Capell\Core\Enums\PublishVisibilityStateEnum;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class PublicationLocaleStatusData extends Data
{
    public function __construct(
        public readonly int|string|null $recordId,
        public readonly string $recordType,
        public readonly int|string|null $siteId,
        public readonly int|string|null $languageId,
        public readonly string $languageCode,
        public readonly PublishVisibilityStateEnum $visibilityState,
        public readonly CarbonImmutable $evaluatedAt,
    ) {}
}
