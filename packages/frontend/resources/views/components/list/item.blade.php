@props(['item', 'active' => null])

@includeIsolated('capell::public.list-item', [
    'attributes' => $attributes,
    'renderData' => app(\Capell\Frontend\Data\PublicListItemData::class, [
        'item' => $item,
        'active' => $active === null ? null : (bool) $active,
        'class' => $attributes->get('class'),
    ]),
])
