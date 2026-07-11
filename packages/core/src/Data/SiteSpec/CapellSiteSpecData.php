<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteSpec;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

final class CapellSiteSpecData extends Data
{
    /** @param array<int, CapellSiteSpecPageData> $pages */
    public function __construct(
        public readonly CapellSiteSpecSiteData $site,
        public readonly CapellSiteSpecThemeData $theme,
        #[DataCollectionOf(CapellSiteSpecPageData::class)]
        public readonly array $pages,
        public readonly CapellSiteSpecLanguageData $language = new CapellSiteSpecLanguageData,
        public readonly string $initialVisibility = 'private',
        public readonly bool $acknowledgePublic = false,
    ) {}
}
