<?php

declare(strict_types=1);

namespace Capell\Frontend\Actions;

use Capell\Frontend\Data\PublicPageResultsData;
use Capell\Frontend\Support\View\DeferredHtmlable;
use Illuminate\Support\Facades\Blade;
use Livewire\Component;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

final class PreparePageResultsSlotAction
{
    use AsFake;
    use AsObject;

    /** @param Component&object{results: mixed} $listing Livewire listing with a computed results property. */
    public function handle(Component $listing, PublicPageResultsData $data): DeferredHtmlable
    {
        // Capture the listing now, but render after layout widgets have set pagination state.
        $results = $listing->results;

        return new DeferredHtmlable(static fn (): string => Blade::render(
            '<x-capell::page.results :$results :component="$data->component" :component-item="$data->componentItem" :no-results-text="$data->noResultsText" :wire-links="false" />',
            ['results' => $results, 'data' => $data],
        ));
    }
}
