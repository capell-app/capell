@props([
    'item',
    'active' => $item->active,
])

@php
    $children = collect();

    if (method_exists($item, 'relationLoaded')) {
        $children = $item->relationLoaded('children') ? $item->children : collect();
    } elseif (is_iterable($item->children ?? null)) {
        $children = collect($item->children);
    }
@endphp

<li
    {{
        $attributes->class([
            'list-item',
            'capell-component capell-list-item',
            'active' => $active,
        ])
    }}
>
    <a
        href="{{ $item->data['url'] ?? '' }}"
        @class([
            'inline-block py-1',
            'hover:text-primary focus:text-primary' => ! $active,
            'text-primary font-semibold' => $active,
        ])
        @wireNavigate
    >
        {{ $item->label }}
    </a>

    @if ($children->isNotEmpty())
        <x-capell::list
            class="ml-2"
            gap="gap-y-0.5"
        >
            @foreach ($children as $child)
                <x-dynamic-component
                    :component="! empty($child->data['component']) ? $child->data['component'] : 'capell::list.item'"
                    :class="$attributes->get('class')"
                    :item="$child"
                />
            @endforeach
        </x-capell::list>
    @endif
</li>
