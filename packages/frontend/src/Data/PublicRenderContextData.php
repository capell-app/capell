<?php

declare(strict_types=1);

namespace Capell\Frontend\Data;

use Capell\Core\Contracts\PublicRenderData;
use Spatie\LaravelData\Data;

/**
 * Model-free page, site, language, layout and theme for public render data.
 * Consumers read this instead of the Eloquent models carried alongside it.
 */
final class PublicRenderContextData extends Data implements PublicRenderData
{
    public function __construct(
        public ?PublicPageData $page,
        public ?PublicSiteData $site,
        public ?PublicLanguageData $language,
        public ?PublicLayoutData $layout,
        public ?PublicThemeData $theme,
    ) {}
}
