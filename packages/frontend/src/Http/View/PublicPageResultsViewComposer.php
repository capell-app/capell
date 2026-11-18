<?php

declare(strict_types=1);

namespace Capell\Frontend\Http\View;

use Capell\Core\Enums\AssetComponentEnum;
use Capell\Frontend\Contracts\FrontendContextReader;
use Capell\Frontend\Data\PublicPageResultsData;
use Illuminate\View\View;

final readonly class PublicPageResultsViewComposer
{
    public function __construct(private FrontendContextReader $frontend) {}

    public function compose(View $view): void
    {
        $page = $this->frontend->page();
        $translation = $page?->relationLoaded('translation') ? $page->translation : null;
        $view->with('renderData', new PublicPageResultsData(
            component: $page->meta['component'] ?? AssetComponentEnum::Page->value,
            componentItem: $page->meta['component_item'] ?? AssetComponentEnum::Card->value,
            noResultsText: $translation?->meta['no_results'] ?? null,
        ));
    }
}
