<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Core\Models\Language;
use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Models\Theme;
use Capell\Frontend\Data\FrontendRenderContextData;
use Capell\Frontend\Data\PublicLanguageData;
use Capell\Frontend\Data\PublicLayoutData;
use Capell\Frontend\Data\PublicPageData;
use Capell\Frontend\Data\PublicRenderContextData;
use Capell\Frontend\Data\PublicSiteData;
use Capell\Frontend\Data\PublicThemeData;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/** Projects the render context's Eloquent models into model-free public Data. */
final class HydratePublicRenderContextDataAction
{
    use AsFake;
    use AsObject;

    public function handle(FrontendRenderContextData $context): PublicRenderContextData
    {
        return new PublicRenderContextData(
            page: $context->page instanceof Page ? new PublicPageData((string) $context->page->name) : null,
            site: $context->site instanceof Site ? new PublicSiteData((string) $context->site->name) : null,
            language: $context->language instanceof Language
                ? new PublicLanguageData((string) $context->language->code, (string) $context->language->name, (string) ($context->language->locale ?? ''))
                : null,
            layout: $context->layout instanceof Layout ? new PublicLayoutData((string) $context->layout->key, (string) $context->layout->name) : null,
            theme: $context->theme instanceof Theme ? new PublicThemeData((string) $context->theme->key, (string) $context->theme->name) : null,
        );
    }
}
