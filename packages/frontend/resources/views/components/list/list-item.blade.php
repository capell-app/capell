@props([
    'url' => null,
    'count' => null,
    'size' => null,
    'active' => false,
])

@php
    $classes = [
        'group flex items-center justify-between gap-3',
        'text-sm @md/item:text-base' => $size === 'sm',
        'text-md lg:text-base' => $size === 'md',
        'text-lg lg:text-xl' => $size === 'lg',
        'text-secondary hover:text-primary focus:text-primary font-medium' => ! $active,
        'text-primary font-semibold' => $active,
    ];
@endphp

<li
    @class([
        'capell-component capell-list-list-item @container/item list-item',
        'py-1.5' => $size === 'sm',
        'py-2' => $size !== 'sm',
        $attributes->get('class'),
    ])
>
    @if ($url && ! $active)
        <a
            href="{{ $url }}"
            @class([...$classes, 'hover:text-primary focus:text-primary'])
            @wireNavigate
        >
            <span class="grow">
                {{ $slot }}
            </span>

            @if ($count)
                <span
                    class="list-item-badge group-hover:text-primary group-focus:text-primary dark:group-hover:text-primary dark:group-focus:text-primary flex items-center rounded-full bg-gray-100 px-2 py-0.5 font-medium tracking-normal whitespace-nowrap text-gray-500 dark:bg-gray-800 dark:text-gray-500"
                >
                    {{ $count }}
                </span>
            @endif
        </a>
    @else
        <div @class($classes)>
            <span class="flex grow">
                {{ $slot }}
            </span>

            @if ($count)
                <span
                    class="list-item-badge group-hover:text-primary group-focus:text-primary dark:group-hover:text-primary dark:group-focus:text-primary flex items-center rounded-full bg-gray-100 px-2 py-0.5 font-medium tracking-normal whitespace-nowrap text-gray-500 dark:bg-gray-800 dark:text-gray-500"
                >
                    {{ $count }}
                </span>
            @endif
        </div>
    @endif
</li>
