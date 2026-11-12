<?php

declare(strict_types=1);

namespace Capell\Core\Data\FirstRun;

use Spatie\LaravelData\Data;

/** What a person tells guided setup about their site. It is turned into a SiteSpec; it never writes anything itself. */
final class GuidedSetupInputData extends Data
{
    /**
     * @param  list<string>  $pageSlugs  Slugs of the preset's pages to create. The home page is always created.
     */
    public function __construct(
        public readonly string $presetKey,
        public readonly string $siteName,
        public readonly string $tagline = '',
        public readonly ?string $themeKey = null,
        public readonly ?string $primaryColour = null,
        public readonly array $pageSlugs = [],
    ) {}
}
