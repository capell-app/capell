@php
    use Capell\Core\Enums\AssetComponentEnum;
    use Capell\Frontend\Facades\Frontend;
    use Capell\Frontend\Support\View\DeferredHtmlable;
    use Illuminate\Support\Facades\Blade;

    $page = Frontend::page();
    $translation = $page !== null && method_exists($page, 'relationLoaded') && $page->relationLoaded('translation') ? $page->translation : null;

    // Evaluate all props now (template time), but defer the actual render of page.results
    // until the slot widget echoes $pageSlot — which happens AFTER all layout containers
    // (including the hero widget) have run. This ensures FrontendData set by widgets
    // (e.g. has_pagination_summary) is available when page.results renders.
    $component = $page['meta']['component'] ?? AssetComponentEnum::Page->value;
    $componentItem = $page['meta']['component_item'] ?? AssetComponentEnum::Card->value;
    $noResultsText = data_get($translation?->meta ?? [], 'no_results');
    $results = $this->results;

    $pageSlot = new DeferredHtmlable(
        fn (): string => Blade::render(
            '<x-capell::page.results :$results :$component :$componentItem :$noResultsText :wireLinks="false" />',
            ['results' => $results, 'component' => $component, 'componentItem' => $componentItem, 'noResultsText' => $noResultsText],
        ),
    );
@endphp

<div class="capell-component capell-page-results capell-livewire-page-results">
    <x-capell::layout
        class="layout-results"
        :page-slot="$pageSlot"
    >
        {{ $pageSlot }}
    </x-capell::layout>
</div>
