@props([
    'component' => null,
    'componentItem' => \Capell\Core\Enums\AssetComponentEnum::Card->value,
    'noResultsText' => __('capell-frontend::generic.no_results'),
    'results',
    'wireLinks' => true,
    'withParent' => false,
    'wireChildrenCount' => false,
])

@includeIsolated('capell::public.page-results', [
    'attributes' => $attributes,
    'renderData' => app(\Capell\Frontend\Data\PublicPageResultsContentData::class, [
        'results' => $results,
        'componentItem' => $componentItem,
        'noResultsText' => $noResultsText,
        'wireLinks' => (bool) $wireLinks,
        'withParent' => (bool) $withParent,
        'wireChildrenCount' => (bool) $wireChildrenCount,
    ]),
])
