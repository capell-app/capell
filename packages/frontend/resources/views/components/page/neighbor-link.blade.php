@props([
    'neighbor' => null,
    'neighborPage' => null,
    'withSummary' => true,
    'withImage' => true,
])

@includeIsolated('capell::public.neighbor-link', [
    'attributes' => $attributes,
    'renderData' => app(\Capell\Frontend\Data\PublicNeighborLinkData::class, [
        'neighbor' => $neighbor,
        'neighborPage' => $neighborPage,
        'withSummary' => (bool) $withSummary,
        'withImage' => (bool) $withImage,
    ]),
])
