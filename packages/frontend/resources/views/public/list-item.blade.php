<li
    {{
    $attributes->class([
        'list-item',
        'capell-component capell-list-item',
        'active' => $renderData->active,
    ])
}}
>
    <a
        href="{{ $renderData->url }}"
        @class([
            'inline-block py-1',
            'hover:text-primary focus:text-primary' => ! $renderData->active,
            'text-primary font-semibold' => $renderData->active,
        ])
        @wireNavigate
    >
        {{ $renderData->label }}
    </a>

    {!! $renderData->renderedChildren !!}
</li>
