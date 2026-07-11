<?php

declare(strict_types=1);

namespace Capell\Core\Data\Diagnostics;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class PublicRenderContractEventData extends Data
{
    public function __construct(
        public readonly string $result,
        public readonly ?string $reason = null,
        public readonly ?string $matchedMarker = null,
        public readonly ?string $packageName = null,
        public readonly ?string $source = null,
        public readonly ?string $urlHash = null,
        public readonly ?string $pathHash = null,
        public readonly ?string $responseHash = null,
        public readonly ?int $pageId = null,
        public readonly ?int $layoutId = null,
        public readonly ?int $themeId = null,
        public readonly ?CarbonImmutable $createdAt = null,
    ) {}
}
