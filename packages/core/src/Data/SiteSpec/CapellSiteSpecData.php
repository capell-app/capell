<?php

declare(strict_types=1);

namespace Capell\Core\Data\SiteSpec;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

final class CapellSiteSpecData extends Data
{
    /**
     * @param  array<int, CapellSiteSpecPageData>  $pages
     * @param  array<int, CapellSiteSpecNavigationData>  $navigations
     * @param  list<string>  $extensions
     */
    public function __construct(
        public readonly CapellSiteSpecSiteData $site,
        public readonly CapellSiteSpecThemeData $theme,
        #[DataCollectionOf(CapellSiteSpecPageData::class)]
        public readonly array $pages,
        public readonly CapellSiteSpecLanguageData $language = new CapellSiteSpecLanguageData,
        #[DataCollectionOf(CapellSiteSpecNavigationData::class)]
        public readonly array $navigations = [],
        public readonly CapellSiteSpecMediaData $media = new CapellSiteSpecMediaData,
        public readonly array $extensions = [],
        public readonly string $initialVisibility = 'private',
        public readonly bool $acknowledgePublic = false,
    ) {}

    /** @return array<string, array<int, string>> */
    public static function rules(): array
    {
        return [
            'navigations' => ['sometimes', 'array'],
            'extensions' => ['sometimes', 'array'],
            'extensions.*' => ['string', 'distinct'],
        ];
    }
}
