<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\PublicRenderData;
use Spatie\LaravelData\Data;

final class PublicHeadData extends Data implements PublicRenderData
{
    /**
     * @param  array<string, string>  $metadata
     * @param  list<string>  $fontUrls
     * @param  list<array{as: string, url: string, fetchPriority: string, imageSrcset: ?string, imageSizes: ?string, mimeType: ?string}>  $mediaHints
     * @param  list<array{href: string, hreflang: string}>  $alternates
     * @param  list<array{name: string, color: string}>  $colors
     * @param  list<array{name: string, style: string, weight: string, src: string}>  $localFonts
     */
    public function __construct(
        public readonly string $metaTitle,
        public readonly array $metadata,
        public readonly string $jsonLd,
        public readonly ?string $baseUrl,
        public readonly array $fontUrls,
        public readonly bool $hasGoogleFont,
        public readonly string $resourceHead,
        public readonly array $mediaHints,
        public readonly ?string $canonicalUrl,
        public readonly ?string $iconUrl,
        public readonly ?string $faviconUrl,
        public readonly ?string $color,
        public readonly array $alternates,
        public readonly ?string $defaultAlternateUrl,
        public readonly ?string $criticalCss,
        public readonly string $fontFamily,
        public readonly string $headingFontFamily,
        public readonly array $colors,
        public readonly string $customCss,
        public readonly array $localFonts,
        public readonly string $stylesHead,
        public readonly bool $usesLivewire,
        public readonly ?string $description,
        public readonly ?string $keywords,
        public readonly ?string $aiDisclosure,
        public readonly string $headOpenHook,
        public readonly string $headHook,
        public readonly string $siteHead,
        public readonly string $themeHead,
        public readonly string $pageHead,
        public readonly string $customHead,
        public readonly string $sectionHead,
    ) {}
}
